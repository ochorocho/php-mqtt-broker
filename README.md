# php-mqtt/broker

:warning: This is experimental for now :warning:

A pure-PHP MQTT broker supporting MQTT 3.1.1 and 5.0, built on [ReactPHP](https://reactphp.org/).

## Requirements

- PHP 8.3+
- Composer
- `ext-openssl`, for TLS listeners only — a plaintext broker does not need it
- Pulls in `react/socket`, `react/event-loop`, `psr/log` and `psr/event-dispatcher`

## Installation

```bash
composer require ochorocho/php-mqtt-broker
```

Installed as a dependency, the two binaries are at `vendor/bin/mqtt-broker` and
`vendor/bin/mqtt-passwd`. The examples below use the `bin/` paths of a clone; if you
installed with Composer, read them as `vendor/bin/`.

## Quick Start

### Standalone (CLI)

```bash
php bin/mqtt-broker --password-file=/etc/mqtt/passwd
```

The broker listens on `0.0.0.0:1883` by default. It refuses to start without an
authentication option: pass `--password-file` or `--auth`, or `--allow-anonymous`
to accept every client without credentials on a development machine. Options:

```bash
php bin/mqtt-broker --allow-anonymous --host=127.0.0.1 --port=1884   # local development
php bin/mqtt-broker --state-file=/var/lib/mqtt/retained.ndjson  # keep retained messages across restarts
php bin/mqtt-broker --help                             # every option
```

Retained messages live in memory unless `--state-file` names a file for them:
then they are written there a few seconds after they change and on shutdown,
and read back at the next start, so a restart no longer forgets what every
device announced. Sessions and queued offline messages are still memory only.

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

The CLI needs one of three options. `--allow-anonymous` accepts every client
without credentials: anyone who can reach the port can then read every topic and
take over any client ID, so it is for development and test rigs only. The other
two require credentials.

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
  evict another client and inherit its session. The most specific account wins:
  if `alice-bob` is an account too, it owns `alice-bob` and `alice-bob-*`, and
  `alice` cannot claim them. An empty client ID gets a broker-assigned one, which
  belongs to no account. Clients whose ID is unrelated to their username — ESPHome
  sets `client_id` independently — either need their `client_id` set to match, or
  the broker started with `--allow-any-client-id`.
- **Each login costs one bcrypt hash** on the broker's only thread — about 0.2 s at
  PHP's default cost. A password that has verified once is remembered (as a keyed
  hash, in memory) so a reconnecting device does not pay again, and a host that
  fails 10 logins within a minute is refused without hashing until its failures
  age out. For long random device secrets, `mqtt-passwd --cost=8` keeps the first
  login cheap too.
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

The same interface is available when embedding the broker as a library, by passing
`authenticator:` to `Broker`. Before you write one, read
[the authenticator contract](docs/embedding.md#the-authenticator-contract). Every
method receives the username the connection authenticated with, so per-user topic
rules need no bookkeeping of their own; that page has a worked example.

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
that would fail every handshake. A client gets `connectTimeout` (10 s) to finish
its TLS handshake, and at most 1000 handshakes are pending at once; a socket that
connects and sends nothing is closed rather than held.

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
php bin/mqtt-broker --tls-cert=/path/to/server.crt --tls-key=/path/to/server.key --password-file=passwd
php bin/mqtt-broker --tls-cert=server.crt --tls-key=server.key \
    --tls-require-client-cert --tls-client-ca=ca.crt --tls-identity-as-username
```

A verified client certificate proves who the client is, but on its own it only
decides who may complete the handshake: any holder of a certificate from that CA
could still claim any username and any client ID. With
`--tls-identity-as-username` (`tlsIdentityAsUsername: true`) the certificate's
common name *is* the username, whatever CONNECT says. On its own it is the whole
authentication — the CN `device-1` may connect as `device-1` or
`device-1-<anything>`, as with a password file. Combined with `--password-file` or
`--auth`, those check the certificate's name instead of a claimed one. A
certificate without a single common name is refused.

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

- **Connections are not limited per address.** `ConnectionManager` does not
  record remote addresses, so one host can use every `maxConnections` slot.
- **Login throttling is in-process only.** A host that fails
  `maxAuthFailuresPerHost` (10) logins within `authFailureWindow` (60 s) is refused
  without a password check until those failures age out — that protects the event
  loop, not the account: it forgets on restart, and many addresses each get their
  own budget. Rejected logins are logged at `warning`, with the username, the
  remote address and the client ID, and refused-while-throttled attempts on a line
  of their own ("Login throttled …"). A fail2ban filter can match the failures:

  ```
  failregex = ^\[.*\] warning: Authentication failed for .* from <HOST>:\d+
  ```

  Usernames, client IDs and topics are attacker-controlled, and MQTT allows
  newlines in all three. Every such value is escaped before it is logged, and the
  CLI's logger escapes control characters in each finished line as well, so no
  client can produce a line of its own — say, a forged "Authentication failed" for
  an address it wants banned. A client must also send CONNECT first: anything else
  is refused on its first byte, before it reaches a decoder. If you embed the broker
  with your own PSR-3 logger, it receives already-escaped values.

  A refused *client ID* is logged too, but on a separate line that the filter
  deliberately does not match — and should not. It means the password was correct
  and only the ID was wrong, which is what a misconfigured device emits on every
  reconnect; banning on it would lock out a whole NAT'd fleet, and whoever is
  debugging it, within seconds of a rollout.
- **`maxConnections` is global, not per-client**, and connections are admitted
  before authentication.
- **Most limits are unreachable from the CLI.** Only 9 of `Configuration`'s 31
  options have flags — `host`, `port`, `--state-file` and the six `--tls-*`
  ones. The other 22,
  including every `max*` limit and `connectTimeout`, require constructing
  `Configuration` in your own entrypoint; see the
  [configuration reference](docs/embedding.md#configuration-reference). Until then,
  bound the process with systemd (`LimitNOFILE`, `MemoryMax`).
- **`PasswordFileAuthenticator` grants every account full topic access**, so one
  leaked credential can subscribe to `#`. Use `--auth` with per-topic rules when
  accounts should not be equals.
- **Sessions are in memory.** A restart — including one triggered by certificate
  renewal — drops persistent sessions and their queued offline messages. Retained
  messages survive it only when `--state-file` is set; without it they go too.

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

Pass any PSR-14 event dispatcher as `eventDispatcher:` to observe broker activity.
One event exists today, `MessagePublished`, carrying `topic`, `payload`, `qos`,
`retain`, `clientId` and `timestamp`.

See [Events](docs/embedding.md#events) for when it fires, and
[error handling](docs/embedding.md#what-does-reach-you-event-listeners) for the two
things to know before attaching a listener: a listener that throws closes the
publishing client's connection, and listeners run synchronously inside the event loop.

## Testing with MQTT Clients

Once the broker is running, connect with any MQTT client. These commands assume a
development listener — `bin/mqtt-broker --allow-anonymous`, plaintext on 1883,
accepting anyone:

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
- Retained messages, kept across restarts with `--state-file`
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
- PSR-3 logging and PSR-14 events

Receive Maximum (20), Topic Alias Maximum (10) and Server Keep Alive (60s) are fixed
server-side values, not configurable.

## Development (DDEV)

The project includes a [DDEV](https://ddev.com/) configuration for local development. The container's port 1883 is published on the host as **11883**, so host-side `mosquitto_pub`/`mosquitto_sub` reach the containerized broker without claiming the well-known port — another project on the same machine may be serving real clients on 1883. The `web_extra_daemons` entry that would start the broker automatically is commented out, so start it yourself.

```bash
# Start the environment, then the broker
ddev start
ddev exec "cd /var/www/html && nohup php bin/mqtt-broker --allow-anonymous > /tmp/broker.log 2>&1 &"

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
├── Auth/                  # AuthenticatorInterface, password file, --auth loader
├── Connection/            # Connection and ConnectionManager
├── Event/                 # MessagePublished (PSR-14)
├── Exception/             # Protocol and packet exceptions
├── Handler/               # PacketHandler and the QoS InFlightMessageTracker
├── Message/               # Retained message store
├── Protocol/              # Wire primitives, reason-code enums, framing
│   ├── Packet/            # All 15 MQTT packet types
│   └── Property/          # MQTT 5.0 property system
├── Server/                # ReactPHP server abstraction
├── Session/               # Session persistence
├── Subscription/          # Subscription and topic matching
├── Broker.php             # Main orchestrator
└── Configuration.php      # Broker configuration

bin/                       # mqtt-broker, mqtt-passwd
deploy/                    # systemd units, fail2ban filter and jail
docs/                      # embedding.md
tools/                     # run_paho.py, for the conformance suites
```

## License

MIT
