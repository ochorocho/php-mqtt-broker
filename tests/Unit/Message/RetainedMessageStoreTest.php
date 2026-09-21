<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Message;

use PhpMqtt\Broker\Message\RetainedMessageStore;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PHPUnit\Framework\TestCase;

final class RetainedMessageStoreTest extends TestCase
{
    public function testAllReturnsEveryMessageByTopic(): void
    {
        $store = new RetainedMessageStore();
        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        $store->store('c/d', new PublishPacket(topicName: 'c/d', payload: 'two', retain: true));

        self::assertSame(['a/b', 'c/d'], array_keys($store->all()));
        self::assertSame('two', $store->all()['c/d']->payload);
    }

    public function testAStoreChangesOnlyOnStoreAndRemove(): void
    {
        $store = new RetainedMessageStore();
        self::assertFalse($store->isDirty());

        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        self::assertTrue($store->isDirty());

        $store->markClean();
        self::assertFalse($store->isDirty());

        // Removing what is not there changes nothing, and must not cost a write.
        $store->remove('never/stored');
        self::assertFalse($store->isDirty());

        $store->remove('a/b');
        self::assertTrue($store->isDirty());
    }

    public function testARejectedStoreDoesNotDirtyTheStore(): void
    {
        $store = new RetainedMessageStore(maxMessages: 1);
        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        $store->markClean();

        self::assertFalse($store->store('c/d', new PublishPacket(topicName: 'c/d', payload: 'two', retain: true)));
        self::assertFalse($store->isDirty());
    }
}
