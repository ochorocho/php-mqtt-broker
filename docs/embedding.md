# Embedding the broker

For running the broker as a service, see the [README](../README.md). This document is
for putting it inside your own PHP application.

## `start()` never returns

`Broker::start()` binds the listener, installs a session reaper, and then runs the
ReactPHP event loop. That last call blocks for the lifetime of the process, so
anything you write after it will not execute:

```php
$broker = new Broker(config: new Configuration());
$broker->start();

$this->doSomethingElse(); // unreachable
```

You have two options. Either give the broker a process of its own — which is what
`bin/mqtt-broker` is — or share an event loop with the rest of your application.

## Sharing an event loop

`Broker` takes a `ServerInterface`, and the shipped `ReactPhpServer` accepts the loop
to use. Pass yours and the broker registers on it instead of creating its own:

```php
use PhpMqtt\Broker\Broker;
use PhpMqtt\Broker\Configuration;
use PhpMqtt\Broker\Server\ReactPhpServer;
use React\EventLoop\Loop;

$loop = Loop::get();

$loop->addPeriodicTimer(60.0, function (): void {
    // your application's own work, on the same loop
});

$broker = new Broker(
    config: new Configuration(port: 1883),
    server: new ReactPhpServer($loop),
);

$broker->start(); // binds, then runs $loop — your timer runs too
```

`ReactPhpServer` takes three more arguments, which matter for a TLS listener:

```php
new ReactPhpServer(
    $loop,
    handshakeTimeout: 10.0,       // seconds a TLS client has to finish its handshake
    maxPendingHandshakes: 1000,   // TLS sockets allowed mid-handshake at once
    logger: $logger,              // handshake failures and timeouts, at debug
);
```

`Broker` only sees a TLS connection once its handshake is done, so its own CONNECT
deadline and `maxConnections` do not cover a socket that connects and sends nothing;
these two do. `bin/mqtt-broker` sets `handshakeTimeout` to `connectTimeout`.

Two things to know:

- **You must still call `start()`.** It is what binds the listener and installs the
  60-second session reaper that expires idle sessions. Constructing a `Broker` does
  nothing on its own.
- **`start()` runs the loop.** If your application calls `$loop->run()` itself, call
  `start()` first and let your own `run()` be the one that blocks — a second `run()`
  on an already-running loop is a no-op.

Without a `server:` argument the broker calls `Loop::get()`, so it joins ReactPHP's
global loop. That is usually what you want in a single-purpose process and rarely
what you want inside a framework.

## Shutting down

`Broker::stop()` closes every connection, stops the server, and stops the loop. The
CLI wires it to signals, and that is the pattern to copy:

```php
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static fn() => $broker->stop());
    pcntl_signal(SIGTERM, static fn() => $broker->stop());
}
```

Two consequences worth planning for.

**`stop()` stops the loop.** It calls `$loop->stop()` unconditionally, so on a shared
loop it takes your whole application down with it. If the broker is a component rather
than the process, stop the server and let your application own the loop's lifetime:

```php
$server = new ReactPhpServer($loop);
$broker = new Broker(config: $config, server: $server);

// later, to shut the broker down without stopping $loop:
foreach ($broker->getConnectionManager()->getAll() as $connection) {
    $connection->close();
}
$server->stop();
```

**Shutdown publishes every client's will message.** Closing a connection is
indistinguishable from a client vanishing, so each one is treated as an unclean
disconnect and its will is delivered. A broker with a hundred will-carrying clients
emits a hundred wills on shutdown. If that matters — a will topic that triggers
alerting, say — have clients send an explicit DISCONNECT before you stop, or design
will topics to tolerate it.

## The authenticator contract

`AuthenticatorInterface` has four methods:

```php
interface AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool;

    public function canUseClientId(string $clientId, ?string $username): bool;

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool;

    public function canPublish(string $clientId, ?string $username, string $topicName): bool;
}
```

**Every method receives the username** the connection authenticated with (null for an
anonymous one). Decide per account from that argument. Do not keep a map of "which
account is this client ID" filled in during `authenticate()`: that runs *before* the
broker checks `canUseClientId()`, so an account with valid credentials could
overwrite another device's entry with an attempt that is then refused. Earlier
versions passed no username to `canSubscribe()`/`canPublish()` and this page
recommended exactly that map; implementations written against it need the extra
parameter, and can drop the map.

### When each method is called

| Method | Called |
|---|---|
| `authenticate()` | Once per connection, on CONNECT — unless the remote host is over its failed-login budget (`maxAuthFailuresPerHost`), in which case it is refused without being asked |
| `canUseClientId()` | Once per connection, immediately after `authenticate()` succeeds. Not for an ID the broker assigned itself (the client sent an empty one): those are random and belong to no account |
| `canSubscribe()` | Per topic filter in every SUBSCRIBE — **and again for every restored subscription when a client reconnects to a persistent session** |
| `canPublish()` | Per inbound PUBLISH, plus once on the will topic at connect, plus again when a will is actually published |
| `canReceive()` | Only if the authenticator implements `DeliveryAuthorizerInterface`: per message, per subscriber, just before it is sent |

The session-restore case surprises people. A client reconnecting with
`cleanSession: false` re-authorizes each of its stored subscriptions, so an
authenticator that makes a network call per check does that work on every reconnect,
not just on the original SUBSCRIBE. Cache accordingly.

`canPublish()` on the will topic is checked twice deliberately: once at connect, so a
client is told immediately rather than discovering at disconnect that its will was
dropped, and once at publication, because authorization may have been revoked while
the client was connected.

`authenticate()` runs on the broker's only thread. A password hash is slow on purpose
(bcrypt at PHP's default cost is about 0.2 s), and every client waits while it runs.
The broker throttles hosts that keep failing, but a check that succeeds is paid in
full each time: remember a verified password — as a keyed hash, never the password —
the way `PasswordFileAuthenticator` does.

### Vetoing individual deliveries

`canSubscribe()` is asked once per filter. Grant `sensors/#` and every topic beneath it
is delivered for as long as the subscription lasts, so an authorizer that only checks
filters has to reason about everything a wildcard could ever match.

An authenticator that also implements `DeliveryAuthorizerInterface` is asked instead
about each message, for each subscriber it is about to go to:

```php
interface DeliveryAuthorizerInterface
{
    public function canReceive(string $clientId, ?string $username, string $topicName): bool;
}
```

It is consulted for live deliveries, for retained messages on subscribe, and for queued
messages when they finally go out — so access revoked while a client was offline
holds when it returns. A denied message is dropped for that subscriber only; the
publisher is told nothing. It fails closed like every other check.

It runs once per message per subscriber, on the broker's only thread. Answer it from
memory — say, a rule set loaded in `authenticate()` per username — rather than from a
database call.

### Failures deny rather than crash

Every check runs inside a wrapper that catches `\Throwable`, logs it at `error`, and
returns `false`. A database outage in your authenticator denies connections — it does
not take the broker down, and it does not accidentally grant access.

That also means a thrown exception and a returned `false` are indistinguishable to the
client. If you need to tell "wrong password" from "my user table is unreachable", log
it yourself; the broker logs the exception but answers the client the same way.

### A per-user ACL authenticator

This is the shape to copy:

```php
use PhpMqtt\Broker\Auth\AuthenticatorInterface;

final class TopicScopedAuthenticator implements AuthenticatorInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        if ($username === null || $password === null) {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT password_hash FROM mqtt_user WHERE username = ?');
        $statement->execute([$username]);
        $hash = $statement->fetchColumn();

        return is_string($hash) && password_verify($password, $hash);
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        // Without a check here, any valid account can take over another client's
        // session and inherit its subscriptions and queued messages.
        return $username !== null
            && ($clientId === $username || str_starts_with($clientId, $username . '-'));
    }

    public function canSubscribe(string $clientId, ?string $username, string $topicFilter): bool
    {
        return $this->ownsPrefix($username, $topicFilter);
    }

    public function canPublish(string $clientId, ?string $username, string $topicName): bool
    {
        return $this->ownsPrefix($username, $topicName);
    }

    private function ownsPrefix(?string $username, string $topic): bool
    {
        // Fail closed for anonymous clients, and for usernames that are topic syntax:
        // a user called "+" would otherwise own "+/#", which is everyone's topics.
        if ($username === null || preg_match('/[\/+#]/', $username) === 1) {
            return false;
        }

        return str_starts_with($topic, $username . '/');
    }
}
```

Three things this deliberately does not do. `ownsPrefix()` treats a subscription
filter as a plain string: `str_starts_with` correctly rejects `#`, `+/…` and
`$share/…`, because none of them begins with `alice/`. Do not reach for the broker's
internal topic matcher here; it answers "does this filter match this topic", which is
a different question from "may this account use this filter". `canUseClientId()` has
the same prefix collision `PasswordFileAuthenticator` guards against — if `alice` and
`alice-bob` can both be accounts, refuse a client ID that a longer username owns. And
it does not cache successful logins, which a busy deployment should.

### The default authenticator

Without an `authenticator:` argument the broker uses `AllowAllAuthenticator`, which
permits everything — with one exception: it refuses subscriptions to
`test/nosubscribe` (shared ones included), an affordance for the MQTT conformance suite. Harmless, but
surprising if you ever wonder why that one topic behaves differently.

## Error handling

### What `start()` throws

`start()` validates the TLS material before binding, and throws plain
`\RuntimeException` in four cases: the certificate is unreadable, the key is
unreadable, `tlsRequireClientCert` is set without `tlsClientCaPath`, or the CA bundle
is unreadable. Refusing to start is deliberate — a `tls://` listener without usable
material accepts nothing, and failing every handshake silently is worse than stopping.

```php
try {
    $broker->start();
} catch (\RuntimeException $e) {
    // misconfiguration: report it and exit, do not retry
}
```

With TLS disabled there is nothing to validate, so a plaintext broker does not throw
from this path.

### What never reaches you

Client-caused protocol failures are contained per connection. A malformed packet or a
protocol violation is logged at `warning` and closes only the offending connection;
anything else that escapes packet handling is caught by a `\Throwable` backstop and
closes that one connection too. The reasoning is in the source: the broker is a single
process with one event loop, so an exception reaching the loop would drop every
connected client.

So `MalformedPacketException` and `ProtocolViolationException` are internal. You do not
need to catch them, and you cannot use them to detect a bad client — watch the log
instead.

### What does reach you: event listeners

This is the one gap in that containment, and it is worth knowing before you attach a
listener.

Event dispatch has no try/catch of its own. A listener that throws propagates up
through packet handling and hits the same backstop as any other unexpected error — so
**a throwing listener closes the connection of the client whose message triggered it**.
The client sees a dropped connection; your listener's bug is logged as an unexpected
error against that client.

Listeners also run synchronously inside the event loop. A listener that makes a slow
HTTP call stalls the entire broker for its duration, because there is nothing else
running to take over.

Both follow from the same design, so the rule is simple: wrap listener bodies in your
own try/catch, and hand slow work to a queue rather than doing it inline.

```php
$dispatcher->addListener(MessagePublished::class, function (MessagePublished $event): void {
    try {
        $this->queue->push($event->topic, $event->payload);
    } catch (\Throwable $e) {
        $this->logger->error('listener failed', ['exception' => $e]);
    }
});
```

Contrast with authenticators, which are wrapped: a throwing authenticator denies
access and is logged. Listeners get no such treatment.

## Events

Pass any PSR-14 dispatcher as `eventDispatcher:` to observe broker activity:

```php
use PhpMqtt\Broker\Event\MessagePublished;

$broker = new Broker(
    config: new Configuration(),
    eventDispatcher: $dispatcher,
);
$broker->start();
```

One event exists today, `PhpMqtt\Broker\Event\MessagePublished`, with six readonly
properties: `topic`, `payload`, `qos`, `retain`, `clientId` and `timestamp`.

For QoS 0 and 1 it fires as the PUBLISH arrives. For QoS 2 it fires once, on PUBREL,
when the publisher confirms delivery — so a message is never reported twice, and never
before the sender has committed to it. It is not dispatched for a publish the
authenticator denied, nor for will messages, which the broker sends on a client's
behalf rather than receiving.

**It is an observation hook, not an interception hook.** The dispatcher's return value
is discarded and the event is not stoppable, so a listener cannot veto or modify a
message. By the time it fires, the retained store has been updated and any PUBACK
already sent; only routing to subscribers is still ahead.

## Inspecting a running broker

Three getters expose the broker's live state — useful for a health endpoint or an
admin view:

```php
$broker->getConnectionManager()->count();        // connected clients
$broker->getSubscriptionManager();               // subscription table
$broker->getPacketHandler()->getRetainedMessages();
$broker->getPacketHandler()->getSessionManager();
```

These return the live objects, not copies. Read them; do not mutate them from outside
the loop.

## Testing without sockets

`Broker` takes a `ServerInterface`, and connections arrive as `ConnectionStream` — both
small enough to implement directly, which is how the broker's own tests drive it
without opening a port.

```php
interface ServerInterface
{
    public function listen(string $uri, callable $onConnection, array $context = []): void;

    public function stop(): void;

    public function getLoop(): \React\EventLoop\LoopInterface;
}

interface ConnectionStream
{
    public function write(string $data): bool;   // false = backpressure; see below

    public function close(): void;

    public function onData(callable $handler): void;

    public function onClose(callable $handler): void;

    public function getRemoteAddress(): string;
}
```

`tests/Unit/Handler/RecordingStream.php` is a complete worked example in 43 lines: it
collects writes into an array, records whether it was closed, and leaves `onData()` and
`onClose()` as empty stubs — a double driven by the test rather than by a socket has
nothing to register. Assertions then read what the broker wrote, decoded back into
packets.

To drive a whole `Broker` instead, `tests/Unit/Broker/` has a `FakeServer`, a
`ScriptedStream` that keeps its handlers so a test can `push()` raw bytes, and a
`FakeLoop` whose `run()` returns at once and whose timers fire only when the test
calls `advance()` — so `start()` can be called in a test and timeouts are
deterministic.

The same shape gives you an alternative transport. Implement both interfaces over
WebSocket, a Unix socket, or an in-memory pipe, and the broker is unchanged.

## Configuration reference

`Configuration` is a value object: 29 constructor parameters, all with defaults, all
readonly. **Only nine are reachable from the CLI** — `host`, `port`, `--state-file`
and the six `--tls-*` options. The others can only be set by constructing it
yourself, which is the main reason to embed the broker rather than run the binary.

```php
$config = new Configuration(
    host: '127.0.0.1',
    port: 8883,
    maxConnections: 500,
    maxPacketSize: 4 * 1024 * 1024,
    maxRetainedBytes: 16 * 1024 * 1024,
);
```

### Listener

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `host` | `string` | `'0.0.0.0'` | Bind address. `0.0.0.0` is every interface. |
| `port` | `int` | `1883` | Bind port. Use 8883 by convention when TLS is on. |
| `maxConnections` | `int` | `10000` | Concurrent connections accepted; beyond this new sockets are closed immediately. Global, not per-IP. |
| `connectTimeout` | `float` | `10.0` | Seconds a connection may stay open before sending CONNECT. Bounds what an unauthenticated peer can hold. |
| `maxPacketSize` | `int` | `1048576` | Largest accepted inbound packet, in bytes, once a client has connected. Also caps what a connection may leave buffered without completing a packet. |
| `maxConnectPacketSize` | `int` | `65536` | Largest packet accepted *before* CONNECT succeeds. An unauthenticated peer has proven nothing, so it does not get `maxPacketSize`; at the old limit ten thousand of them could hold ten gigabytes. Raise it if clients send wills larger than this. |
| `maxOutboundBufferBytes` | `int` | `8388608` | Backlog tolerated for a client that has stopped reading. Past it the client is disconnected and a `warning` is logged. Without it, a subscriber that never reads makes the broker keep every message routed to it. |
| `maxClientIdLength` | `int` | `256` | Client identifier length in bytes. |
| `maxAuthFailuresPerHost` | `int` | `10` | Failed logins tolerated from one remote address within `authFailureWindow`. Beyond it, CONNECT is refused (`0x05`, or `0x9F` Connection rate exceeded in 5.0) without asking the authenticator, and logged as "Login throttled". Successful logins never count. `0` disables. |
| `authFailureWindow` | `float` | `60.0` | Seconds a failed login counts against its host. |

### Sessions and keep-alive

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `minKeepAlive` | `int` | `300` | Lower bound applied when a client requests keep-alive 0, which would otherwise never be reaped. |
| `maxSessions` | `int` | `10000` | Persistent sessions retained; the oldest idle session is evicted beyond this. |
| `maxSessionExpiry` | `int` | `86400` | Ceiling for a session's expiry interval, in seconds. MQTT 3.1.1 sessions have no expiry of their own. |
| `maxPendingMessagesPerSession` | `int` | `1000` | Offline queue depth per session. When full the oldest message is dropped and logged at `warning`. |
| `maxInflightMessages` | `int` | `64` | QoS 1 and 2 messages sent to one client and not yet acknowledged. MQTT 3.1.1 has no Receive Maximum, so this is the whole limit there; a 5.0 client's own Receive Maximum applies when it is lower. Further messages wait in the session queue. |

### Subscriptions and retained messages

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `maxSubscriptionsPerClient` | `int` | `1000` | Active subscriptions per client. |
| `maxFiltersPerSubscribe` | `int` | `128` | Topic filters acted on in one SUBSCRIBE; the rest are refused (`0x80`, or `0x97` Quota exceeded in 5.0). A filter named twice in one packet has its retained messages sent once. |
| `maxTopicLevels` | `int` | `32` | Levels (`/`-separated) in a topic or filter. |
| `maxRetainedMessages` | `int` | `10000` | Distinct retained topics. |
| `maxRetainedBytes` | `int` | `67108864` | Total retained bytes, counting each message's topic name, payload and properties — the real memory bound, since everything is in-process. Only properties that travel with a message (payload format, content type, response topic, correlation data, user properties, message expiry) are retained; a topic alias or authentication data is dropped. |
| `retainedStatePath` | `?string` | `null` | File the retained messages are kept in across restarts (`--state-file` on the CLI). Written a few seconds after they change and on `stop()`, read back in `start()`; its directory must be writable or `start()` refuses. Null keeps them in memory only. |

### TLS

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `tlsCertPath` | `?string` | `null` | Server certificate. Setting it is what enables TLS. |
| `tlsKeyPath` | `?string` | `null` | Private key. |
| `tlsKeyPassphrase` | `?string` | `null` | Passphrase for an encrypted private key. |
| `tlsRequireClientCert` | `bool` | `false` | Require and verify a client certificate (mutual TLS). Needs `tlsClientCaPath`, and a TLS listener: `start()` refuses it without `tlsCertPath`, where it would enforce nothing. |
| `tlsClientCaPath` | `?string` | `null` | CA bundle used to verify client certificates. |
| `tlsCiphers` | `?string` | `null` | OpenSSL cipher list. Null uses the PHP default. |
| `tlsMinVersion` | `int` | `STREAM_CRYPTO_METHOD_TLSv1_2_SERVER` | Minimum protocol, as an OpenSSL crypto-method constant. Defaults to TLS 1.2 or better; PHP's own default would still negotiate TLS 1.0 on some builds. |
| `tlsIdentityAsUsername` | `bool` | `false` | Use the common name of the verified client certificate as the username, ignoring the one in CONNECT; a connection without one is refused. Needs `tlsRequireClientCert`. Pair it with `CertificateIdentityAuthenticator` for certificate-only authentication (client IDs bound to the name), or with your own authenticator, which then receives the certificate's name. `start()` refuses `CertificateIdentityAuthenticator` without it. |

### Not configurable

Three server-side values are compile-time constants, despite appearing in the feature
list as though they were tunable: the server's Receive Maximum is 20, its Topic Alias
Maximum is 10, and the Server Keep Alive it advertises to MQTT 5.0 clients is 60
seconds.

### Custom transports

`ConnectionStream::write()` returns `bool`: `false` means the transport is buffering
past its soft limit because the peer is not keeping up. The data must still be
queued. The broker counts the bytes written while it keeps answering `false` and
disconnects past `maxOutboundBufferBytes`. A transport that cannot tell should return
`true`.
