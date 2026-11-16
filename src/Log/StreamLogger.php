<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Log;

use Psr\Log\AbstractLogger;

/**
 * One line per record on a stream, as `[date] level: message`.
 *
 * This is the logger bin/mqtt-broker uses. It escapes every interpolated value, so
 * even a call site that forgot to sanitize cannot produce a second line: the format
 * is what a fail2ban filter matches, and only the broker may write to it.
 */
final class StreamLogger extends AbstractLogger
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Defaults to STDERR.
     */
    public function __construct($stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $rendered = self::render($value);
            if ($rendered !== null) {
                $replacements['{' . $key . '}'] = $rendered;
            }
        }

        // Applied to the finished line rather than per value, so a value concatenated
        // into the template instead of passed as context is still kept to one line.
        $line = LogSanitizer::escapeControlCharacters(strtr((string) $message, $replacements));

        // Full date and offset, so lines stay orderable across days and are usable as
        // input to a log-based blocker rather than only readable by a human.
        fwrite($this->stream, sprintf("[%s] %s: %s\n", date('Y-m-d H:i:sP'), (string) $level, $line));
    }

    private static function render(mixed $value): ?string
    {
        if ($value instanceof \Throwable) {
            return $value->getMessage();
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return null;
    }
}
