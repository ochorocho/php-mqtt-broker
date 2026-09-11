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

## Testing with MQTT Clients

Once the broker is running, connect with any MQTT client:

```bash
# Subscribe (using mosquitto_sub)
mosquitto_sub -h localhost -p 1883 -t 'test/topic'

# Publish (using mosquitto_pub)
mosquitto_pub -h localhost -p 1883 -t 'test/topic' -m 'Hello MQTT'

# MQTT 5.0 (using mosquitto_pub with -V)
mosquitto_pub -h localhost -p 1883 -t 'test/topic' -m 'Hello' -V mqttv5
```

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

The project includes a [DDEV](https://ddev.com/) configuration for local development. Port 1883 is exposed directly to the host, so host-side `mosquitto_pub`/`mosquitto_sub` reach the containerized broker. The `web_extra_daemons` entry that would start the broker automatically is commented out, so start it yourself.

```bash
# Start the environment, then the broker
ddev start
ddev exec "cd /var/www/html && nohup php bin/mqtt-broker > /tmp/broker.log 2>&1 &"

# Connect from the host
mosquitto_sub -h localhost -p 1883 -t '#'
mosquitto_pub -h localhost -p 1883 -t 'test/topic' -m 'Hello'

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
