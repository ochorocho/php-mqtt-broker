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

## Still to come

Sections being written, in this document:

- **Error handling** — what `start()` throws, and which exceptions reach your code.
- **Configuration reference** — all 21 options, most of which the CLI cannot set.
