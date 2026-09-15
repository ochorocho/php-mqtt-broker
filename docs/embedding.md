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

## Still to come

Sections being written, in this document:

- **The authenticator contract** — what `canPublish` and `canSubscribe` do *not*
  receive, and when each method fires.
- **Error handling** — what `start()` throws, and which exceptions reach your code.
- **Configuration reference** — all 21 options, most of which the CLI cannot set.
