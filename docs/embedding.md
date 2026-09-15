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

`AuthenticatorInterface` has four methods, and the difference between them is not
obvious from the signatures:

```php
interface AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool;

    public function canUseClientId(string $clientId, ?string $username): bool;

    public function canSubscribe(string $clientId, string $topicFilter): bool;

    public function canPublish(string $clientId, string $topicName): bool;
}
```

**Only the first two receive the username.** `canSubscribe()` and `canPublish()` get a
client ID and a topic, nothing else. So per-user topic rules are impossible unless you
record who a client ID belongs to while you still know — during `authenticate()`.

Getting this wrong is quiet rather than loud: you write an authorizer that compiles,
passes a smoke test with one client, and silently authorizes the wrong things once two
accounts are connected.

### When each method is called

| Method | Called |
|---|---|
| `authenticate()` | Once per connection, on CONNECT |
| `canUseClientId()` | Once per connection, immediately after `authenticate()` succeeds |
| `canSubscribe()` | Per topic filter in every SUBSCRIBE — **and again for every restored subscription when a client reconnects to a persistent session** |
| `canPublish()` | Per inbound PUBLISH, plus once on the will topic at connect, plus again when a will is actually published |

The session-restore case surprises people. A client reconnecting with
`cleanSession: false` re-authorizes each of its stored subscriptions, so an
authenticator that makes a network call per check does that work on every reconnect,
not just on the original SUBSCRIBE. Cache accordingly.

`canPublish()` on the will topic is checked twice deliberately: once at connect, so a
client is told immediately rather than discovering at disconnect that its will was
dropped, and once at publication, because authorization may have been revoked while
the client was connected.

### Failures deny rather than crash

Every check runs inside a wrapper that catches `\Throwable`, logs it at `error`, and
returns `false`. A database outage in your authenticator denies connections — it does
not take the broker down, and it does not accidentally grant access.

That also means a thrown exception and a returned `false` are indistinguishable to the
client. If you need to tell "wrong password" from "my user table is unreachable", log
it yourself; the broker logs the exception but answers the client the same way.

### A per-user ACL authenticator

This is the shape to copy. Note `$identities` — without it, `canPublish()` has no idea
which account `$clientId` belongs to:

```php
use PhpMqtt\Broker\Auth\AuthenticatorInterface;

final class TopicScopedAuthenticator implements AuthenticatorInterface
{
    /** @var array<string, string> clientId => username, recorded at authenticate() */
    private array $identities = [];

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

        if (!is_string($hash) || !password_verify($password, $hash)) {
            return false;
        }

        // The only place the username is available. Everything below depends on it.
        $this->identities[$clientId] = $username;

        return true;
    }

    public function canUseClientId(string $clientId, ?string $username): bool
    {
        // Without a check here, any valid account can take over another client's
        // session and inherit its subscriptions and queued messages.
        return $username !== null
            && ($clientId === $username || str_starts_with($clientId, $username . '-'));
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        return $this->ownsPrefix($clientId, $topicFilter);
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        return $this->ownsPrefix($clientId, $topicName);
    }

    private function ownsPrefix(string $clientId, string $topic): bool
    {
        $username = $this->identities[$clientId] ?? null;

        // Fail closed: an unknown client ID means we never saw it authenticate.
        return $username !== null && str_starts_with($topic, $username . '/');
    }
}
```

Two things this deliberately does not do. It never prunes `$identities`, which is fine
for a fixed device roster and a leak for thousands of rotating client IDs — clear the
entry when a client disconnects if that describes you. And `ownsPrefix()` treats a
subscription filter as a plain string: `str_starts_with` correctly rejects `#` and
`+/…`, because neither begins with `alice/`. Do not reach for the broker's internal
topic matcher here; it answers "does this filter match this topic", which is a
different question from "may this account use this filter".

### The default authenticator

Without an `authenticator:` argument the broker uses `AllowAllAuthenticator`, which
permits everything — with one exception: it refuses subscriptions to
`test/nosubscribe`, an affordance for the MQTT conformance suite. Harmless, but
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
    public function write(string $data): void;

    public function close(): void;

    public function onData(callable $handler): void;

    public function onClose(callable $handler): void;

    public function getRemoteAddress(): string;
}
```

`tests/Unit/Handler/RecordingStream.php` is a complete worked example in 41 lines: it
collects writes into an array, records whether it was closed, and leaves `onData()` and
`onClose()` as empty stubs — a double driven by the test rather than by a socket has
nothing to register. Assertions then read what the broker wrote, decoded back into
packets.

The same shape gives you an alternative transport. Implement both interfaces over
WebSocket, a Unix socket, or an in-memory pipe, and the broker is unchanged.

## Configuration reference

`Configuration` is a value object: 21 constructor parameters, all with defaults, all
readonly. **Only seven are reachable from the CLI** — `host`, `port` and the five
`--tls-*` options. The other fourteen can only be set by constructing it yourself,
which is the main reason to embed the broker rather than run the binary.

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
| `maxPacketSize` | `int` | `1048576` | Largest accepted inbound packet, in bytes. Also caps a connection's receive buffer, so a client cannot stream unbounded data without completing a packet. |
| `maxClientIdLength` | `int` | `256` | Client identifier length in bytes. |

### Sessions and keep-alive

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `minKeepAlive` | `int` | `300` | Lower bound applied when a client requests keep-alive 0, which would otherwise never be reaped. |
| `maxSessions` | `int` | `10000` | Persistent sessions retained; the oldest idle session is evicted beyond this. |
| `maxSessionExpiry` | `int` | `86400` | Ceiling for a session's expiry interval, in seconds. MQTT 3.1.1 sessions have no expiry of their own. |
| `maxPendingMessagesPerSession` | `int` | `1000` | Offline queue depth per session. When full the oldest message is dropped and logged at `warning`. |

### Subscriptions and retained messages

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `maxSubscriptionsPerClient` | `int` | `1000` | Active subscriptions per client. |
| `maxTopicLevels` | `int` | `32` | Levels (`/`-separated) in a topic or filter. |
| `maxRetainedMessages` | `int` | `10000` | Distinct retained topics. |
| `maxRetainedBytes` | `int` | `67108864` | Total retained payload bytes — the real memory bound, since everything is in-process. |

### TLS

| Parameter | Type | Default | Purpose |
|---|---|---|---|
| `tlsCertPath` | `?string` | `null` | Server certificate. Setting it is what enables TLS. |
| `tlsKeyPath` | `?string` | `null` | Private key. |
| `tlsKeyPassphrase` | `?string` | `null` | Passphrase for an encrypted private key. |
| `tlsRequireClientCert` | `bool` | `false` | Require and verify a client certificate (mutual TLS). Needs `tlsClientCaPath`. |
| `tlsClientCaPath` | `?string` | `null` | CA bundle used to verify client certificates. |
| `tlsCiphers` | `?string` | `null` | OpenSSL cipher list. Null uses the PHP default. |
| `tlsMinVersion` | `int` | `STREAM_CRYPTO_METHOD_TLSv1_2_SERVER` | Minimum protocol, as an OpenSSL crypto-method constant. Defaults to TLS 1.2 or better; PHP's own default would still negotiate TLS 1.0 on some builds. |

### Not configurable

Three server-side values are compile-time constants, despite appearing in the feature
list as though they were tunable: the server's Receive Maximum is 20, its Topic Alias
Maximum is 10, and the Server Keep Alive it advertises to MQTT 5.0 clients is 60
seconds.
