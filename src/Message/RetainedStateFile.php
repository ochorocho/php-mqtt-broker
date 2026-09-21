<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Message;

use PhpMqtt\Broker\Protocol\Packet\PublishPacket;
use PhpMqtt\Broker\Protocol\Property\PropertyCollection;
use PhpMqtt\Broker\Protocol\Property\PropertyId;
use PhpMqtt\Broker\Protocol\ProtocolVersion;
use Psr\Log\LoggerInterface;

/**
 * The retained messages on disk, so they outlive the process.
 *
 * Newline-delimited JSON, one line per topic: the topic, the payload as
 * base64 (a payload is bytes, not text - a camera frame is a JPEG), the QoS,
 * the protocol version and the MQTT 5 properties by their numeric id. Written
 * whole through a temporary file and a rename, so a reader never sees half a
 * file; read line by line, skipping what does not parse, so one damaged line
 * does not cost every other topic.
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
     * @return int Messages written.
     * @throws \RuntimeException when the file cannot be written.
     */
    public function write(RetainedMessageStore $store): int
    {
        $lines = [];
        foreach ($store->all() as $topic => $packet) {
            $line = json_encode([
                't' => $topic,
                'p' => base64_encode($packet->payload),
                'q' => $packet->qos,
                'v' => $packet->protocolVersion->value,
                'pr' => $packet->properties === null || $packet->properties->isEmpty()
                    ? null
                    : $packet->properties->getAll(),
            ], JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                throw new \RuntimeException(sprintf('Retained message on %s cannot be encoded: %s', $topic, json_last_error_msg()));
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

        $payload = base64_decode($data['p'], true);
        $version = ProtocolVersion::tryFrom((int) ($data['v'] ?? ProtocolVersion::V311->value));
        $qos = (int) ($data['q'] ?? 0);
        if ($payload === false || $version === null || $qos < 0 || $qos > 2) {
            return null;
        }

        $properties = null;
        if (is_array($data['pr'] ?? null)) {
            $properties = new PropertyCollection();
            foreach ($data['pr'] as $id => $value) {
                $property = PropertyId::tryFrom((int) $id);
                if ($property === null) {
                    continue;
                }
                // A multi-value property was written as the list set() built;
                // set() appends one at a time, so hand each back on its own.
                foreach ($property->isMultiValue() && is_array($value) ? $value : [$value] as $one) {
                    $properties->set($property, $one);
                }
            }
        }

        return new PublishPacket(
            topicName: $data['t'],
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
}
