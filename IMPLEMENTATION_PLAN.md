# Security audit fixes (Critical + High)

Full findings: the audit plan (C1, H1–H6 plus M1, M2, M3, M7, M8, L3 in scope).

## Stage 0: Broker-level test harness
**Goal**: Drive `Broker` with raw bytes and controllable timers, without sockets.
**Success Criteria**: A test connects through `Broker` and reads back a CONNACK.
**Tests**: `tests/Unit/Broker/BrokerSmokeTest.php`
**Status**: Complete

## Stage 1: Crash and log forgery (C1, H1)
**Goal**: No packet can stop the event loop; no attacker string can forge a log line.
**Success Criteria**: Max-size packet + trailing bytes closes one connection only;
pre-CONNECT non-CONNECT packet rejected before decoding; every logged attacker value escaped.
**Tests**: PacketStreamTest, BrokerRobustnessTest, BrokerLoggingTest, LogSanitizerTest
**Status**: Complete

## Stage 2: Amplification and backpressure (H2, H3, M3, M7, L3)
**Goal**: Bound what one client can make the broker compute, buffer or hold.
**Success Criteria**: Repeated filters deliver retained once; filter cap; congested
writers disconnected; forced close after end(); small pre-CONNECT limit; bogus acks ignored.
**Tests**: PacketHandlerMessagingTest additions, ConnectionTest, BrokerRobustnessTest
**Status**: Complete

## Stage 3: Authentication hardening (H4, M1, M2, M8)
**Goal**: Cheap repeat logins, throttled failures, no client-ID collisions, username
passed to authorization, no accidental anonymous broker.
**Success Criteria**: Success cache, per-host throttle, longest-owner rule, new
authorizer signatures, `--allow-anonymous` required.
**Tests**: PasswordFileAuthenticatorTest, AuthFailureThrottleTest, PacketHandlerAuthorizationTest
**Status**: Complete

## Stage 4: Purge expired sessions (H6)
**Goal**: A session that expires or is evicted takes its subscriptions with it.
**Success Criteria**: Expired filters stop matching; reconnect after expiry gets nothing;
live sessions survive the reaper.
**Tests**: PacketHandlerLifecycleTest additions, SessionManagerTest
**Status**: Complete

## Stage 5: TLS handshake deadline (H5)
**Goal**: A socket that never completes the TLS handshake is closed.
**Success Criteria**: Idle pre-handshake socket closed after the timeout; pending cap.
**Tests**: ReactPhpServer test with a real loopback TLS listener
**Status**: In Progress
