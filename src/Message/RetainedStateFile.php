<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Log\LogSanitizer;
use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCodec;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use PhpMqtt\Broker\Protocol\TopicFilter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The retained messages on disk, so they outlive the process.
 *
 * Newline-delimited JSON, one line per topic: the topic, the payload as base64 (a
 * payload is bytes, not text - a camera frame is a JPEG), the QoS, the protocol
 * version, and the MQTT 5 properties as base64 of their wire encoding. Written whole
 * through a temporary file and a rename, so a reader never sees half a file; read line
 * by line, skipping what does not parse, so one damaged line does not cost every other
 * topic.
 *
 * Properties used to be written as a JSON map of id => value. Correlation data is
 * binary, so one such message made json_encode fail and the whole write with it. The
 * wire encoding carries any value, and reading it back through the protocol decoder
 * validates it the way a client's PUBLISH would be. Files in the old form still load.
 */
final class RetainedStateFile
{
    public function __construct(
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Write every retained message out, replacing the file.
     *
     * A message that cannot be represented is skipped and logged rather than aborting
     * the write: it would otherwise stop every other message from being saved.
     *
     * @return int Messages written.
     * @throws \RuntimeException when the file cannot be written.
     */
    public function write(RetainedMessageStore $store, LoggerInterface $logger = new NullLogger()): int
    {
        $lines = [];
        foreach ($store->all() as $topic => $packet) {
            $line = self::encode((string) $topic, $packet);
            if ($line === null) {
                $logger->warning('Retained message on {topic} cannot be written to {path}, skipped', [
                    'topic' => LogSanitizer::forLog((string) $topic),
                    'path' => $this->path,
                ]);
                continue;
            }
            $lines[] = $line;
        }

        // Checked first: tempnam() quietly falls back to the system temporary
        // directory when this one does not exist, and the rename below would
        // then fail with a far less useful message.
        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException(sprintf('Retained state directory is not writable: %s', $directory));
        }

        $temporary = @tempnam($directory, '.retained-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Cannot create a temporary file in %s', $directory));
        }

        $contents = $lines === [] ? '' : implode("\n", $lines) . "\n";
        if (@file_put_contents($temporary, $contents) !== strlen($contents)
            || !@chmod($temporary, 0640)
            || !@rename($temporary, $this->path)
        ) {
            @unlink($temporary);
            throw new \RuntimeException(sprintf('Cannot write the retained state file %s', $this->path));
        }

        return count($lines);
    }

    /**
     * Every message the file holds. An absent file holds none.
     *
     * @return iterable<PublishPacket>
     */
    public function read(LoggerInterface $logger): iterable
    {
        if (!is_file($this->path)) {
            return;
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot read the retained state file %s', $this->path));
        }

        try {
            $number = 0;
            while (($line = fgets($handle)) !== false) {
                $number++;
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $packet = self::decode($line);
                if ($packet === null) {
                    $logger->warning('Skipping line {line} of {path}: not a retained message', [
                        'line' => $number,
                        'path' => $this->path,
                    ]);
                    continue;
                }

                yield $packet;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function encode(string $topic, PublishPacket $packet): ?string
    {
        try {
            $properties = $packet->properties === null || $packet->properties->isEmpty()
                ? null
                : base64_encode(PropertyCodec::encode($packet->properties));
        } catch (MalformedPacketException) {
            return null;
        }

        $line = json_encode([
            't' => $topic,
            'p' => base64_encode($packet->payload),
            'q' => $packet->qos,
            'v' => $packet->protocolVersion->value,
            'pb' => $properties,
        ], JSON_UNESCAPED_SLASHES);

        return $line === false ? null : $line;
    }

    private static function decode(string $line): ?PublishPacket
    {
        try {
            $data = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !is_string($data['t'] ?? null) || !is_string($data['p'] ?? null)) {
            return null;
        }

        // A name a client could have published: the file is trusted, but a value that
        // gets in here is served to every matching subscriber.
        $topic = $data['t'];
        if (!TopicFilter::isValidName($topic) || str_contains($topic, "\0")) {
            return null;
        }

        $payload = base64_decode($data['p'], true);
        $version = ProtocolVersion::tryFrom((int) ($data['v'] ?? ProtocolVersion::V311->value));
        $qos = (int) ($data['q'] ?? 0);
        if ($payload === false || $version === null || $qos < 0 || $qos > 2) {
            return null;
        }

        try {
            $properties = match (true) {
                is_string($data['pb'] ?? null) => self::decodeProperties($data['pb']),
                is_array($data['pr'] ?? null) => self::decodeLegacyProperties($data['pr']),
                default => null,
            };
        } catch (MalformedPacketException|\ValueError) {
            return null;
        }

        return new PublishPacket(
            topicName: $topic,
            payload: $payload,
            qos: $qos,
            retain: true,
            // A stored message has no packet in flight; delivery allocates its own
            // id. The constructor only insists there is one for QoS above 0.
            packetId: $qos > 0 ? 1 : null,
            protocolVersion: $version,
            properties: $properties,
        );
    }

    /**
     * @throws MalformedPacketException
     */
    private static function decodeProperties(string $encoded): PropertyCollection
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false) {
            throw new MalformedPacketException('Properties are not base64');
        }

        $offset = 0;
        $properties = PropertyCodec::decode($bytes, $offset);
        if ($offset !== strlen($bytes)) {
            throw new MalformedPacketException('Trailing bytes after the properties');
        }

        return $properties;
    }

    /**
     * The first release's form: a map of property id => value. Each value is checked
     * against its property's type and then put through the wire codec, so one that
     * would fail when a delivery is encoded - and disconnect that subscriber - fails
     * here instead.
     *
     * @param array<array-key, mixed> $raw
     * @throws MalformedPacketException
     */
    private static function decodeLegacyProperties(array $raw): PropertyCollection
    {
        $properties = new PropertyCollection();
        foreach ($raw as $id => $value) {
            $property = PropertyId::tryFrom((int) $id);
            if ($property === null) {
                continue;
            }

            // A multi-value property was written as the list set() built.
            $values = $property->isMultiValue() && is_array($value) && array_is_list($value) && !self::isPair($value)
                ? $value
                : [$value];
            foreach ($values as $one) {
                if (!self::hasTypeOf($property, $one)) {
                    throw new MalformedPacketException(sprintf('Property 0x%02X has the wrong type', $property->value));
                }
                $properties->set($property, $one);
            }
        }

        $offset = 0;

        return PropertyCodec::decode(PropertyCodec::encode($properties), $offset);
    }

    private static function hasTypeOf(PropertyId $property, mixed $value): bool
    {
        return match ($property->dataType()) {
            'byte', 'two_byte', 'four_byte', 'variable_int' => is_int($value),
            'utf8', 'binary' => is_string($value),
            'utf8_pair' => is_array($value) && self::isPair($value),
            default => false,
        };
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isPair(array $value): bool
    {
        return count($value) === 2 && is_string($value[0] ?? null) && is_string($value[1] ?? null);
    }
}
