<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use PhpMqtt\Broker\Connection\Connection;
use PhpMqtt\Broker\Handler\InFlightMessageTracker;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\PacketEncoder;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

final class InFlightMessageTrackerTest extends TestCase
{
    private InFlightMessageTracker $tracker;

    protected function setUp(): void
    {
        $this->tracker = new InFlightMessageTracker();
    }

    private function connection(): Connection
    {
        return new Connection(new RecordingStream(), new PacketEncoder(), Loop::get());
    }

    private function publish(int $qos, ?int $packetId): PublishPacket
    {
        return new PublishPacket(topicName: 'a/b', payload: 'x', qos: $qos, packetId: $packetId);
    }

    public function testPacketIdsStartAtOneAndIncrement(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->tracker->allocatePacketId('c');
        }

        self::assertSame([1, 2, 3], $ids);
    }

    public function testPacketIdsAreSeparatePerClient(): void
    {
        self::assertSame(1, $this->tracker->allocatePacketId('a'));
        self::assertSame(1, $this->tracker->allocatePacketId('b'));
    }

    public function testAnIdStillInFlightIsNotHandedOutAgain(): void
    {
        $connection = $this->connection();

        // Take id 1 and leave it unacknowledged.
        $id = $this->tracker->allocatePacketId('c');
        $this->tracker->sendTracked($connection, $this->publish(1, $id), 'c');

        // Wrap the counter back around to 1; the in-flight id must be skipped.
        for ($i = 0; $i < 65534; $i++) {
            $this->tracker->allocatePacketId('c');
        }

        self::assertNotSame($id, $this->tracker->allocatePacketId('c'));
    }

    public function testSendingATrackedMessageCountsAgainstTheFlowControlWindow(): void
    {
        $connection = $this->connection();

        $this->tracker->sendTracked($connection, $this->publish(1, 5), 'c');

        // Recording and the counter have to move together: if a send is not recorded,
        // the window never closes and flow control silently stops working.
        self::assertSame(1, $connection->getUnackedOutgoing());
        self::assertCount(1, $this->tracker->unacknowledged('c'));
    }

    public function testQos0MessagesAreNotTracked(): void
    {
        $connection = $this->connection();

        $this->tracker->sendTracked($connection, $this->publish(0, null), 'c');

        self::assertSame(0, $connection->getUnackedOutgoing());
        self::assertSame([], $this->tracker->unacknowledged('c'));
    }

    public function testPubackSettlesAQos1Message(): void
    {
        $connection = $this->connection();
        $this->tracker->sendTracked($connection, $this->publish(1, 5), 'c');

        $this->tracker->acknowledgeOutgoing('c', 5);

        self::assertSame([], $this->tracker->unacknowledged('c'));
    }

    public function testQos2MovesFromUnacknowledgedToAwaitingPubcomp(): void
    {
        $connection = $this->connection();
        $this->tracker->sendTracked($connection, $this->publish(2, 7), 'c');

        $this->tracker->awaitPubcomp('c', 7);
        self::assertSame([], $this->tracker->unacknowledged('c'), 'PUBREC clears the outgoing record');

        // The id is still spoken for until PUBCOMP, so it must not be reissued.
        for ($i = 0; $i < 6; $i++) {
            $this->tracker->allocatePacketId('c');
        }
        self::assertNotSame(7, $this->tracker->allocatePacketId('c'));

        $this->tracker->completeOutgoing('c', 7);
    }

    public function testIncomingQos2MessagesAreHeldUntilTaken(): void
    {
        $packet = $this->publish(2, 9);

        $this->tracker->holdIncoming('c', $packet);
        self::assertSame(1, $this->tracker->countIncoming('c'));

        self::assertSame($packet, $this->tracker->takeIncoming('c', 9));
        self::assertSame(0, $this->tracker->countIncoming('c'));
        self::assertNull($this->tracker->takeIncoming('c', 9));
    }

    public function testForgettingAClientReleasesEverything(): void
    {
        $connection = $this->connection();
        $this->tracker->sendTracked($connection, $this->publish(1, 1), 'c');
        $this->tracker->holdIncoming('c', $this->publish(2, 2));
        $this->tracker->awaitPubcomp('c', 3);

        $this->tracker->forget('c');

        self::assertSame([], $this->tracker->unacknowledged('c'));
        self::assertSame(0, $this->tracker->countIncoming('c'));
        self::assertSame(1, $this->tracker->allocatePacketId('c'), 'Ids restart for a fresh session');
    }
}
