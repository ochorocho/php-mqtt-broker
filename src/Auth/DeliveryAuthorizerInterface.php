<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Auth;

/**
 * Optional: veto each delivery, not only each subscription.
 *
 * canSubscribe() is asked once per filter, so a granted wildcard covers every topic
 * beneath it for as long as the subscription lasts - a filter the authorizer would
 * have to reason about in full to get right. An authenticator that also implements
 * this interface is asked about every message, for every subscriber it is about to be
 * sent to: live deliveries, retained messages, and queued messages when they finally
 * go out, so access revoked while a client was offline holds.
 *
 * It runs once per message per subscriber on the broker's only thread. Keep it to an
 * in-memory lookup; a call to a database here slows every client down.
 */
interface DeliveryAuthorizerInterface
{
    /**
     * @param string $clientId The subscriber the message is for.
     * @param ?string $username The username that subscriber authenticated with.
     * @param string $topicName The topic the message was published on.
     */
    public function canReceive(string $clientId, ?string $username, string $topicName): bool;
}
