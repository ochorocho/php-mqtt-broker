<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Message;

use PhpMqtt\Broker\Message\RetainedMessageStore;
use PhpMqtt\Broker\Message\RetainedStateFile;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Tests\Unit\Handler\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * The file that lets retained messages outlive the process.
 */
final class RetainedStateFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mqtt-retained-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testWhatIsWrittenIsReadBack(): void
    {
        $properties = new PropertyCollection();
        $properties->set(PropertyId::ContentType, 'image/jpeg');
        $properties->set(PropertyId::UserProperty, ['camera', 'xiao']);
        $properties->set(PropertyId::UserProperty, ['room', 'hall']);

        $store = new RetainedMessageStore();
        // Bytes, not text: the payload of a camera frame.
        $store->store('cam/image', new PublishPacket(topicName: 'cam/image', payload: "\xFF\xD8\xFF\xE0\x00binary", retain: true));
        $store->store('sensor/temp', new PublishPacket(
            topicName: 'sensor/temp',
            payload: '21.5',
            qos: 1,
            retain: true,
            packetId: 42,
            protocolVersion: ProtocolVersion::V50,
            properties: $properties,
        ));

        $file = new RetainedStateFile($this->directory . '/retained.ndjson');
        self::assertSame(2, $file->write($store));

        $read = [];
        foreach ($file->read(new RecordingLogger()) as $packet) {
            $read[$packet->topicName] = $packet;
        }

        self::assertSame(['cam/image', 'sensor/temp'], array_keys($read));
        self::assertSame("\xFF\xD8\xFF\xE0\x00binary", $read['cam/image']->payload);
        self::assertSame(0, $read['cam/image']->qos);
        self::assertTrue($read['cam/image']->retain);
        self::assertNull($read['cam/image']->properties);

        $temp = $read['sensor/temp'];
        self::assertSame(1, $temp->qos);
        self::assertSame(ProtocolVersion::V50, $temp->protocolVersion);
        self::assertNotNull($temp->properties);
        self::assertSame('image/jpeg', $temp->properties->get(PropertyId::ContentType));
        self::assertSame([['camera', 'xiao'], ['room', 'hall']], $temp->properties->get(PropertyId::UserProperty));
    }

    public function testAFileFillsAFreshStoreWithinItsLimits(): void
    {
        $store = new RetainedMessageStore();
        foreach (['a', 'b', 'c'] as $topic) {
            $store->store($topic, new PublishPacket(topicName: $topic, payload: str_repeat('x', 10), retain: true));
        }
        $file = new RetainedStateFile($this->directory . '/retained.ndjson');
        $file->write($store);

        $fresh = new RetainedMessageStore(maxMessages: 2);
        $accepted = 0;
        foreach ($file->read(new RecordingLogger()) as $packet) {
            $accepted += $store->store($packet->topicName, $packet) ? 1 : 0;
            $fresh->store($packet->topicName, $packet);
        }

        self::assertSame(3, $accepted, 'a store with room takes them all');
        self::assertSame(2, $fresh->count(), 'the limits of the store being filled still apply');
    }

    public function testAnAbsentOrEmptyFileHoldsNothing(): void
    {
        $file = new RetainedStateFile($this->directory . '/retained.ndjson');
        self::assertSame([], iterator_to_array($file->read(new RecordingLogger()), false));

        touch($this->directory . '/retained.ndjson');
        self::assertSame([], iterator_to_array($file->read(new RecordingLogger()), false));
    }

    public function testADamagedLineCostsOnlyItself(): void
    {
        $path = $this->directory . '/retained.ndjson';
        file_put_contents($path, implode("\n", [
            json_encode(['t' => 'a/b', 'p' => base64_encode('one'), 'q' => 0, 'v' => 4]),
            '{"t":"broken',
            json_encode(['t' => 'c/d', 'p' => 'not base64!', 'q' => 0, 'v' => 4]),
            json_encode(['t' => 'e/f', 'p' => base64_encode('three'), 'q' => 0, 'v' => 4]),
        ]) . "\n");

        $logger = new RecordingLogger();
        $topics = array_map(
            static fn (PublishPacket $packet): string => $packet->topicName,
            iterator_to_array((new RetainedStateFile($path))->read($logger), false),
        );

        self::assertSame(['a/b', 'e/f'], $topics);
        self::assertCount(2, $logger->messagesAt('warning'));
    }

    public function testTheFileIsReplacedWholeAndLeavesNoTemporaryBehind(): void
    {
        $path = $this->directory . '/retained.ndjson';
        file_put_contents($path, "garbage that must not survive\n");

        $store = new RetainedMessageStore();
        $store->store('a/b', new PublishPacket(topicName: 'a/b', payload: 'one', retain: true));
        (new RetainedStateFile($path))->write($store);

        self::assertSame(1, substr_count((string) file_get_contents($path), "\n"));
        self::assertStringNotContainsString('garbage', (string) file_get_contents($path));
        $files = array_values(array_filter(glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        self::assertSame([$path], $files, 'nothing but the file itself');
    }

    public function testAnEmptyStoreWritesAnEmptyFile(): void
    {
        $path = $this->directory . '/retained.ndjson';
        self::assertSame(0, (new RetainedStateFile($path))->write(new RetainedMessageStore()));
        self::assertSame('', file_get_contents($path));
    }

    public function testAnUnwritableDirectoryIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        (new RetainedStateFile($this->directory . '/missing/retained.ndjson'))->write(new RetainedMessageStore());
    }

    public function testBinaryPropertiesSurviveTheFile(): void
    {
        // Correlation data is binary. Written as JSON text, one non-UTF-8 byte made
        // json_encode fail and aborted the whole write, every five seconds, for good.
        $properties = new PropertyCollection();
        $properties->set(PropertyId::CorrelationData, "\xFF\x00\xFE");
        $properties->set(PropertyId::MessageExpiryInterval, 3600);

        $store = new RetainedMessageStore();
        $store->store('req', new PublishPacket(
            topicName: 'req',
            payload: 'x',
            retain: true,
            protocolVersion: ProtocolVersion::V50,
            properties: $properties,
        ));
        $store->store('other', new PublishPacket(topicName: 'other', payload: 'y', retain: true));

        $file = new RetainedStateFile($this->directory . '/retained.ndjson');
        self::assertSame(2, $file->write($store, new RecordingLogger()));

        $read = [];
        foreach ($file->read(new RecordingLogger()) as $packet) {
            $read[$packet->topicName] = $packet;
        }

        self::assertSame("\xFF\x00\xFE", $read['req']->properties?->get(PropertyId::CorrelationData));
        self::assertSame(3600, $read['req']->properties?->get(PropertyId::MessageExpiryInterval));
        self::assertArrayHasKey('other', $read);
    }

    public function testOneMessageThatCannotBeWrittenDoesNotCostTheOthers(): void
    {
        $store = new RetainedMessageStore();
        // Not a valid UTF-8 topic, so JSON cannot carry it. The store itself does not
        // validate; the wire decoder does, so this only arrives by a path like this one.
        $store->store("bad\xC3", new PublishPacket(topicName: "bad\xC3", payload: 'x', retain: true));
        $store->store('good', new PublishPacket(topicName: 'good', payload: 'y', retain: true));

        $logger = new RecordingLogger();
        $file = new RetainedStateFile($this->directory . '/retained.ndjson');

        self::assertSame(1, $file->write($store, $logger));
        self::assertCount(1, $logger->messagesAt('warning'));
        self::assertSame(['good'], array_map(
            static fn(PublishPacket $p): string => $p->topicName,
            iterator_to_array($file->read(new RecordingLogger()), false),
        ));
    }

    public function testAFileFromTheEarlierFormatStillLoads(): void
    {
        $path = $this->directory . '/retained.ndjson';
        // Properties as the first release wrote them: a JSON map of id => value.
        file_put_contents($path, json_encode([
            't' => 'sensor/temp',
            'p' => base64_encode('21.5'),
            'q' => 1,
            'v' => 5,
            'pr' => [3 => 'text/plain', 38 => [['k', 'v']]],
        ]) . "\n");

        $packets = iterator_to_array((new RetainedStateFile($path))->read(new RecordingLogger()), false);

        self::assertCount(1, $packets);
        self::assertSame('text/plain', $packets[0]->properties?->get(PropertyId::ContentType));
        self::assertSame([['k', 'v']], $packets[0]->properties?->get(PropertyId::UserProperty));
    }

    public function testLinesThatWouldFailLaterAreSkippedNow(): void
    {
        $path = $this->directory . '/retained.ndjson';
        $line = static fn(array $fields): string => (string) json_encode(
            $fields + ['p' => base64_encode('x'), 'q' => 0, 'v' => 5],
        );
        file_put_contents($path, implode("\n", [
            // Values of the wrong type used to load, then throw while encoding a
            // delivery - disconnecting whichever client subscribed.
            $line(['t' => 'a', 'pr' => [38 => 'not a pair']]),
            $line(['t' => 'b', 'pr' => [3 => ['not', 'text']]]),
            $line(['t' => 'c', 'pb' => base64_encode("\x05\xFF")]),
            // Not topic names a client could have published.
            $line(['t' => 'd/#']),
            $line(['t' => '']),
            $line(['t' => 'ok']),
        ]) . "\n");

        $logger = new RecordingLogger();
        $topics = array_map(
            static fn(PublishPacket $p): string => $p->topicName,
            iterator_to_array((new RetainedStateFile($path))->read($logger), false),
        );

        self::assertSame(['ok'], $topics);
        self::assertCount(5, $logger->messagesAt('warning'));
    }
}
