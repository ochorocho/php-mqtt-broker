<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Auth;

use PhpMqtt\Broker\Auth\AllowAllAuthenticator;
use PHPUnit\Framework\TestCase;

final class AllowAllAuthenticatorTest extends TestCase
{
    public function testTheDenyListAppliesToSharedSubscriptionsOfTheSameFilter(): void
    {
        $authenticator = new AllowAllAuthenticator(['test/nosubscribe']);

        // '$share/<group>/' changes who receives a message, not which messages: the
        // same filter behind the prefix used to walk straight past the list.
        self::assertFalse($authenticator->canSubscribe('c', null, 'test/nosubscribe'));
        self::assertFalse($authenticator->canSubscribe('c', null, '$share/g/test/nosubscribe'));
        self::assertTrue($authenticator->canSubscribe('c', null, 'test/other'));
    }
}
