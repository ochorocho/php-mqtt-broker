<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Log;

/**
 * Makes attacker-controlled strings safe to put into a log line.
 *
 * Client IDs, usernames and topics come off the wire, and MQTT's UTF-8 validation
 * rejects NUL and surrogates but not newlines. An unescaped newline splits one record
 * into two, and the second says whatever the client wrote: journald and file-based
 * log readers then treat it as a genuine entry, and a fail2ban jail bans on it.
 */
final class LogSanitizer
{
    private const int DEFAULT_MAX_LENGTH = 128;

    /**
     * Escape control characters and backslashes, with no length limit. Escaping the
     * backslash too keeps the result unambiguous: a literal "\\n" stays distinct
     * from an escaped newline.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, "\0..\37\\\177");
    }

    /**
     * Escape control characters only. For a whole line whose values may already have
     * been through escape(): it cannot split the line, and does not double-escape.
     */
    public static function escapeControlCharacters(string $value): string
    {
        return addcslashes($value, "\0..\37\177");
    }

    /**
     * Escape, then truncate, so one oversized value cannot flood the log either.
     */
    public static function forLog(string $value, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        $escaped = self::escape($value);

        if (mb_strlen($escaped) <= $maxLength) {
            return $escaped;
        }

        return mb_substr($escaped, 0, $maxLength) . '…';
    }

    /**
     * The peer address without the transport scheme.
     *
     * The stream reports `tcp://host:port` (or `tls://…`), but a log line is read by
     * fail2ban and friends as well as by people, and a scheme that varies with the
     * listener only complicates the pattern they have to match.
     */
    public static function address(string $remoteAddress): string
    {
        $withoutScheme = preg_replace('#^[a-z0-9.+-]+://#i', '', $remoteAddress) ?? $remoteAddress;

        return self::forLog($withoutScheme);
    }
}
