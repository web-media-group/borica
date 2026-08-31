<?php

declare(strict_types=1);

namespace IvoWmg\Borica\Responses;

/**
 * Read a server-to-server reply.
 *
 * The gateway has been observed answering the same endpoint with JSON, with
 * JSON wrapped in HTML, and with a query string. None of these is wrong per
 * the specification, which does not pin the format down, so all three are
 * tried in turn rather than assuming the one seen during development.
 */
final class ResponseParser
{
    /** @return array<string, string> */
    public static function parse(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        return self::asJson($body)
            ?? self::asEmbeddedJson($body)
            ?? self::asQueryString($body)
            ?? self::asKeyValueLines($body);
    }

    /** @return array<string, string>|null */
    private static function asJson(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? self::stringify($decoded) : null;
    }

    /** Some replies arrive as a JSON blob inside an HTML page. */
    private static function asEmbeddedJson(string $body): ?array
    {
        if (preg_match('/\{.*\}/s', $body, $m) !== 1) {
            return null;
        }

        $decoded = json_decode($m[0], true);

        return is_array($decoded) ? self::stringify($decoded) : null;
    }

    /** @return array<string, string>|null */
    private static function asQueryString(string $body): ?array
    {
        if (! str_contains($body, '=')) {
            return null;
        }

        parse_str($body, $parsed);

        // parse_str never fails; it just yields nonsense. A reply that is
        // really a query string carries the fields we expect to see.
        if (! isset($parsed['ACTION']) && ! isset($parsed['RC'])) {
            return null;
        }

        return self::stringify($parsed);
    }

    /** @return array<string, string> */
    private static function asKeyValueLines(string $body): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $out[trim($key)] = trim($value);
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private static function stringify(array $values): array
    {
        $out = [];

        foreach ($values as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
