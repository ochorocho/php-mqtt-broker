# php-mqtt/broker

:warning: This is experimental for now :warning:

A pure-PHP MQTT broker supporting MQTT 3.1.1 and 5.0, built on [ReactPHP](https://reactphp.org/).

## Requirements

- PHP 8.3+
- Composer

## Installation

```bash
composer require ochorocho/php-mqtt-broker
```

## Quick Start

### Standalone (CLI)

```bash
php bin/mqtt-broker
```

The broker listens on `0.0.0.0:1883` by default and accepts anyone. Options:

```bash
php bin/mqtt-broker --host=127.0.0.1 --port=1884
php bin/mqtt-broker --password-file=/etc/mqtt/passwd   # require credentials
php bin/mqtt-broker --help                             # every option
```

Signal handling (SIGINT/SIGTERM) is supported when the `pcntl` extension is available.

### As a Library

```php
<?php

use PhpMqtt\Broker\Broker;
use PhpMqtt\Broker\Configuration;

require __DIR__ . '/vendor/autoload.php';

$config = new Configuration(
    host: '0.0.0.0',
    port: 1883,
    maxConnections: 10000,
);

$broker = new Broker(config: $config);
$broker->start();
```

`start()` runs the event loop and **does not return**, so this shape suits a process
dedicated to the broker. To run it inside an application that has its own event loop,
to shut it down cleanly, or to implement an authenticator, see
[docs/embedding.md](docs/embedding.md).

### Authentication

Without an authentication option the broker accepts every client, which is fine
on a trusted network and wrong anywhere else. There are two ways to change that.

#### Password file

Give each client its own credentials in a file of bcrypt hashes, and point the
broker at it:

```bash
php bin/mqtt-passwd /etc/mqtt/passwd sensor01     # prompts twice, echo off
php bin/mqtt-broker --password-file=/etc/mqtt/passwd
```

The file holds one `username:hash` per line, like Mosquitto's `passwd`. Write it
with `bin/mqtt-passwd` — `-b` sets a password without prompting (visible in the
process list, so prefer the prompt), and `-D` deletes a user.

Three things worth knowing before you deploy it:

- **Client IDs are bound to the account.** `alice` may connect as `alice` or
  `alice-<anything>`, and nothing else. Without this, any valid account could
  evict another client and inherit its session. Clients whose ID is unrelated to
  their username — ESPHome sets `client_id` independently — either need their
  `client_id` set to match, or the broker started with `--allow-any-client-id`.
- **There are no per-topic rules.** Every authenticated client may publish and
  subscribe anywhere. If you need ACLs, use a custom authenticator below.
- **The file is read once at startup.** Adding or removing a user takes effect on
  restart.

The broker refuses to start if the file is missing, unreadable, or malformed,
naming the offending line, rather than starting up and rejecting every client.

#### Custom authenticator

For a database, LDAP, or per-topic rules, implement `AuthenticatorInterface` and
point the broker at a PHP file that returns one:

```bash
php bin/mqtt-broker --auth=/etc/mqtt/auth.php
```

```php
// /etc/mqtt/auth.php
return new MyAuthenticator($pdo);
```

That file is executed with the broker's privileges, so it must be owned by the
operator and not writable by the user the broker runs as.

#### Configuring clients

> These two snippets are **unverified** — neither project lives in this
> repository, so treat them as a starting point rather than tested configuration.

ESPHome sends credentials from its `mqtt:` block:

```yaml
mqtt:
  broker: 192.168.1.10
  port: 1883
  username: sensor01
  password: !secret mqtt_password
  client_id: sensor01     # must match the username unless --allow-any-client-id
```

A client that currently sends no credentials — such as TYPO3
`EXT:mqtt_client`'s `MqttReader` — has to set the username and password fields
in its CONNECT packet *and* the matching flag bits (`0x80` and `0x40` in the
connect flags byte). Most client libraries expose this as a
`setCredentials($username, $password)` call before connecting. Keep the
credentials in extension configuration, not in source.

The same interface is available when embedding the broker as a library:

```php
<?php

use PhpMqtt\Broker\Auth\AuthenticatorInterface;
use PhpMqtt\Broker\Broker;
use PhpMqtt\Broker\Configuration;

class MyAuthenticator implements AuthenticatorInterface
{
    public function authenticate(string $clientId, ?string $username, ?string $password): bool
    {
        return $username === 'admin' && $password === 'secret';
    }

    public function canSubscribe(string $clientId, string $topicFilter): bool
    {
        return true;
    }

    public function canPublish(string $clientId, string $topicName): bool
    {
        return true;
    }

    /**
     * Bind the client ID to the authenticated user.
     *
     * Connecting with another client's ID evicts that client and, for persistent
     * sessions, inherits its subscriptions and queued messages — so returning
     * `true` unconditionally here lets any valid account hijack any other.
     */
    public function canUseClientId(string $clientId, ?string $username): bool
    {
        return $clientId === $username . '-device';
    }
}

$broker = new Broker(
    config: new Configuration(),
    authenticator: new MyAuthenticator(),
);
$broker->start();
```

### TLS

```php
$config = new Configuration(
    host: '0.0.0.0',
    port: 8883,
    tlsCertPath: '/path/to/server.crt',
    tlsKeyPath: '/path/to/server.key',
);

$broker = new Broker(config: $config);
$broker->start();
```

TLS 1.2 is the minimum by default (TLS 1.3 is used when available). `start()`
throws if the certificate or key cannot be read, rather than binding a listener
that would fail every handshake.

For mutual TLS, require a client certificate and give the CA bundle to verify
it against:

```php
$config = new Configuration(
    port: 8883,
    tlsCertPath: '/path/to/server.crt',
    tlsKeyPath: '/path/to/server.key',
    tlsKeyPassphrase: 'secret',          // only for an encrypted key
    tlsRequireClientCert: true,
    tlsClientCaPath: '/path/to/ca.crt',
    tlsCiphers: 'ECDHE+AESGCM',          // optional
);
```

The CLI accepts the same options:

```bash
php bin/mqtt-broker --tls-cert=/path/to/server.crt --tls-key=/path/to/server.key
php bin/mqtt-broker --tls-cert=server.crt --tls-key=server.key \
    --tls-require-client-cert --tls-client-ca=ca.crt
```

With `--tls-cert` the default port is 8883. Note that MQTT credentials are sent
in the clear on a plaintext listener, so prefer TLS whenever clients
authenticate with a username and password.

### Production deployment

`deploy/` holds example artifacts to copy and edit: `mqtt-broker.service`,
a `mqtt-broker-cert-renew.path`/`.service` pair for picking up a renewed
certificate, and `fail2ban/` with a filter and a jail.

#### From clone to running

1. Create a system user for the broker and the directories it reads:

   ```bash
   adduser --system --group --no-create-home --shell /usr/sbin/nologin mqtt
   install -d -o root -g root -m 0755 /opt/mqtt-broker
   install -d -o root -g mqtt -m 0750 /etc/mqtt
   ```

2. Deploy the code as root-owned and merely readable by `mqtt`, so a compromised
   broker cannot rewrite its own code:

   ```bash
   git clone https://github.com/ochorocho/php-mqtt-broker /opt/mqtt-broker
   cd /opt/mqtt-broker && composer install --no-dev --optimize-autoloader
   chown -R root:root /opt/mqtt-broker
   ```

3. Create an account per device. The client ID must belong to the account, so name
   them together:

   ```bash
   php /opt/mqtt-broker/bin/mqtt-passwd /etc/mqtt/passwd sensor01
   chown root:mqtt /etc/mqtt/passwd && chmod 0640 /etc/mqtt/passwd
   ```

4. Put the certificate and key in place. The key is group-readable by `mqtt` and
   never world-readable, and the certificate should be the full chain:

   ```bash
   chown root:mqtt /etc/mqtt/tls/server.key && chmod 0640 /etc/mqtt/tls/server.key
   chown root:mqtt /etc/mqtt/tls/server.crt && chmod 0644 /etc/mqtt/tls/server.crt
   ```

5. Install the unit, edit its paths, and start it:

   ```bash
   cp deploy/mqtt-broker.service /etc/systemd/system/
   systemctl daemon-reload
   systemctl enable --now mqtt-broker
   ```

#### Verifying it works

```bash
systemctl status mqtt-broker
journalctl -u mqtt-broker -f
```

Then connect for real — note `-i`, which matters more than it looks:

```bash
mosquitto_sub -h mqtt.example.com -p 8883 --capath /etc/ssl/certs \
  -i sensor01 -u sensor01 -P 'secret' -t 'sensor01/#' -v
```

Leave `-i` out and the client library invents a random ID, which the account does
not own, and the connection is refused with `CONNACK (5) not authorised` — the same
code a wrong password returns. The log tells them apart:

```
warning: Client ID mosq-AbC123 not allowed for sensor01 from 203.0.113.10:51234
warning: Authentication failed for sensor01 from 203.0.113.10:51234 (client sensor01)
```

The first names the fix: give the client an ID its account owns (`sensor01`, or
`sensor01-`-prefixed). If your devices set client IDs you cannot control, start the
broker with `--allow-any-client-id` — at the cost of letting any valid account
evict any other client and inherit its session.

#### Certificate renewal

The certificate is read once, at startup. Wire your renewal to
`systemctl restart mqtt-broker` — a certbot `--deploy-hook` is simplest. If your
renewal only drops files, use the `mqtt-broker-cert-renew` units, and write the key
first and the certificate last so the watcher cannot fire on a mismatched pair; the
`.path` file explains why.

#### Why the unit looks the way it does

Four things matter more than the unit itself.

**Run it as a non-root user.** The broker never drops privileges, and `--auth`
executes an arbitrary PHP file with the broker's rights — as root, that file
becomes a root-RCE path. Port 8883 is above 1024, so no capability is needed.
Keep the code and the auth file owned by `root` and merely *readable* by the
broker user, so a compromised process cannot rewrite them.

**Expose TLS only.** The broker is a single process with a single listener: you
cannot serve plaintext locally and TLS publicly from one instance. Always pass
`--tls-cert`, and never open 1883 to an untrusted network — the username and
password travel inside the CONNECT packet in the clear.

**Certificates are read once, at startup.** `validateTls()` runs when the broker
starts and refuses to start on an unreadable cert or key, rather than binding a
listener that fails every handshake. A renewed certificate therefore does
nothing until the service restarts, and there is no SIGHUP handler — wire your
renewal to `systemctl restart mqtt-broker`. Keep the private key group-readable
by the broker user and never world-readable, and avoid `--tls-passphrase`: it is
visible in the process list and in the unit file.

**Restrict who can reach the port.** See the limitations below for why this
matters more here than for a mature broker.

#### What this broker does not do

It is marked experimental at the top of this README, and these gaps are real:

- **No rate limiting, and no per-IP accounting.** `ConnectionManager` does not
  record remote addresses, so password guessing is unthrottled.
- **Nothing slows a guessing run down.** A rejected login *is* logged — at
  `warning`, with the username, the remote address and the client ID — but the
  broker itself neither delays nor blocks the next attempt. Acting on those lines
  is left to you. A fail2ban filter can match them:

  ```
  failregex = ^\[.*\] warning: Authentication failed for .* from <HOST>:\d+
  ```

  Usernames and client IDs are attacker-controlled, so control characters in them
  are escaped before they reach the log and cannot forge entries.

  A refused *client ID* is logged too, but on a separate line that the filter
  deliberately does not match — and should not. It means the password was correct
  and only the ID was wrong, which is what a misconfigured device emits on every
  reconnect; banning on it would lock out a whole NAT'd fleet, and whoever is
  debugging it, within seconds of a rollout.
- **`maxConnections` is global, not per-client**, and connections are admitted
  before authentication.
- **Most limits are unreachable from the CLI.** `maxConnections`,
  `connectTimeout` and `maxPacketSize` require constructing `Configuration` in
  your own entrypoint. Until then, bound the process with systemd
  (`LimitNOFILE`, `MemoryMax`).
- **`PasswordFileAuthenticator` grants every account full topic access**, so one
  leaked credential can subscribe to `#`. Use `--auth` with per-topic rules when
  accounts should not be equals.
- **All state is in memory.** A restart — including one triggered by certificate
  renewal — drops retained messages and queued offline messages.

Failed logins are visible, but visibility is not a brake: the broker will answer
the next attempt just as quickly. So the effective control remains keeping the
port away from the open internet — allow only the source addresses your clients
use, or put the broker behind a VPN. Where clients are known and few, that single
firewall rule does more than any amount of tuning, and a log-based blocker is
worth adding behind it rather than instead of it.

### PSR-3 Logging

Pass any PSR-3 logger (Monolog, symfony/console-logger, etc.):

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('mqtt');
$logger->pushHandler(new StreamHandler('php://stdout'));

$broker = new Broker(
    config: new Configuration(),
    logger: $logger,
);
$broker->start();
```

### Events

Pass any PSR-14 event dispatcher to observe broker activity:

```php
use PhpMqtt\Broker\Event\MessagePublished;

$broker = new Broker(
    config: new Configuration(),
    eventDispatcher: $dispatcher,
);
$broker->start();
```

One event is dispatched today:

| Event              | Dispatched when                                                    | Properties                                                   |
|--------------------|--------------------------------------------------------------------|--------------------------------------------------------------|
| `MessagePublished` | A client's PUBLISH is accepted, before it is routed to subscribers | `topic`, `payload`, `qos`, `retain`, `clientId`, `timestamp` |

For QoS 0 and 1 it fires as the PUBLISH arrives. For QoS 2 it fires once, on
PUBREL, when the publisher confirms delivery — so a message is never reported
twice, and never before the sender has committed to it.

It is not dispatched for a publish the authenticator denied, nor for will
messages, which the broker sends on a client's behalf rather than receiving.

## Testing with MQTT Clients

Once the broker is running, connect with any MQTT client. These commands assume the
default listener — `bin/mqtt-broker` with no flags, plaintext on 1883, accepting
anyone:

```bash
# Subscribe (using mosquitto_sub)
mosquitto_sub -h localhost -p 1883 -t 'test/topic'

# Publish (using mosquitto_pub)
mosquitto_pub -h localhost -p 1883 -t 'test/topic' -m 'Hello MQTT'

# MQTT 5.0 (using mosquitto_pub with -V)
mosquitto_pub -h localhost -p 1883 -t 'test/topic' -m 'Hello' -V mqttv5
```

Once you add `--password-file` or `--tls-cert` these no longer apply: the port
becomes 8883, credentials are required, and a client ID that does not belong to the
account is refused. See [Production deployment](#production-deployment) for the
authenticated form, and use `-p 11883` under DDEV.

## Features

- MQTT 3.1.1 and 5.0 protocol support
- QoS 0, 1, and 2
- Retained messages
- Will messages (including Will Delay Interval for MQTT 5.0)
- Session persistence and offline message queueing
- Topic wildcards (`+` single-level, `#` multi-level)
- Shared subscriptions (`$share/group/topic`)
- Topic aliases (bidirectional)
- Flow control (Receive Maximum)
- Subscription identifiers
- Message expiry
- Server Keep Alive
- Password-file authentication with hashed credentials (`bin/mqtt-passwd`)
- Custom authentication and authorization via `AuthenticatorInterface`
- TLS support
- PSR-3 logging

## Development (DDEV)

The project includes a [DDEV](https://ddev.com/) configuration for local development. The container's port 1883 is published on the host as **11883**, so host-side `mosquitto_pub`/`mosquitto_sub` reach the containerized broker without claiming the well-known port — another project on the same machine may be serving real clients on 1883. The `web_extra_daemons` entry that would start the broker automatically is commented out, so start it yourself.

```bash
# Start the environment, then the broker
ddev start
ddev exec "cd /var/www/html && nohup php bin/mqtt-broker > /tmp/broker.log 2>&1 &"

# Connect from the host (11883 on the host, 1883 inside the container)
mosquitto_sub -h localhost -p 11883 -t '#'
mosquitto_pub -h localhost -p 11883 -t 'test/topic' -m 'Hello'

# Run unit tests
ddev exec vendor/bin/phpunit

# Run static analysis
ddev exec vendor/bin/phpstan analyse src/ --level=8

# Check broker daemon status
ddev exec supervisorctl status

# View broker logs
ddev exec supervisorctl tail mqtt-broker
```

## Architecture

```
src/
├── Auth/                  # Authentication interfaces and implementations
├── Connection/            # Connection and ConnectionManager
├── Exception/             # Protocol and packet exceptions
├── Handler/               # Packet handling logic
├── Message/               # Retained message store
├── Protocol/
│   ├── Packet/            # All 15 MQTT packet types
│   └── Property/          # MQTT 5.0 property system
├── Server/                # ReactPHP server abstraction
├── Session/               # Session persistence
├── Subscription/          # Subscription and topic matching
├── Broker.php             # Main orchestrator
└── Configuration.php      # Broker configuration
```

## License

MIT
