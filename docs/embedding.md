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

## Still to come

- **Configuration reference** — all 21 options, most of which the CLI cannot set.
