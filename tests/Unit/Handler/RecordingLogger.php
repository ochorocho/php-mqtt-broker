<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Tests\Unit\Handler;

use Psr\Log\AbstractLogger;

/**
 * A logger that keeps what it was told, with placeholders already interpolated,
 * so tests can assert on the line an operator would actually see.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => $this->interpolate((string) $message, $context),
            'context' => $context,
        ];
    }

    /** @return list<string> Interpolated messages logged at the given level. */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn(array $record): string => $record['message'],
            array_filter($this->records, static fn(array $r): bool => $r['level'] === $level),
        ));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
