<?php

namespace App\Services;

/**
 * Masks credentials in log text before it leaves the server (Settings >
 * System logs view and download): `password=`/`secret:`/`*_token`/`*_key`
 * style key-value pairs (plain, JSON or env-dump form), `Bearer` tokens,
 * `base64:` app keys, and passwords embedded in connection URLs.
 * Slow query logs also go through maskSqlLiterals() first: MySQL logs
 * full literal SQL, so session ids, cache tokens and insert values have
 * no key=value shape for redact() to catch.
 */
class LogRedactor
{
    public const MASK = '[REDACTED]';

    /**
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9\-._~+\/|]+=*/i' => 'Bearer '.self::MASK,
        '/\bbase64:[A-Za-z0-9+\/]{16,}={0,2}/' => 'base64:'.self::MASK,
        '#(\b[a-z][a-z0-9+.\-]*://[^\s:@/]+:)[^\s@/]+@#i' => '$1'.self::MASK.'@',
        // KEY = value / "key": "value" / 'key' => 'value' / \"key\":\"value\"
        '/(?<![A-Za-z0-9])((?:\\\\?["\'])?[A-Za-z0-9_.\-]*(?:password|passwd|pwd|secret|token|api[_-]?key|app[_-]?key|private[_-]?key|access[_-]?key|authorization|cookie|recovery[_-]?codes?)[A-Za-z0-9_.\-]*(?:\\\\?["\'])?\s*(?:=>|=|:)\s*)(\\\\"(?:[^\\\\]|\\\\[^"])*?\\\\"|"[^"]*"|\'[^\']*\'|[^\s,;&}\])]+)/i' => '$1'.self::MASK,
    ];

    /**
     * Replaces quoted SQL string literals (backslash escapes; '' doubling
     * just splits into two masked halves), 0x hex literals and long
     * unquoted hex/base64-ish runs with `?`. One left-to-right pass so a
     * quote inside another literal never starts a match; a literal still
     * open at end of line (multi-line value) is masked to the line end.
     */
    public static function maskSqlLiterals(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/\'(?:[^\'\\\\\n]++|\\\\.)*+(?:\'|$)|"(?:[^"\\\\\n]++|\\\\.)*+(?:"|$)/m',
            fn (array $m) => $m[0][0].'?'.$m[0][0],
            $text,
        );

        return (string) preg_replace([
            '/\b0x[0-9a-f]{8,}\b/i',
            '/(?<![A-Za-z0-9+\/_\-])(?=[A-Za-z0-9+\/_\-]*\d)(?=[A-Za-z0-9+\/_\-]*[A-Za-z])[A-Za-z0-9+\/_\-]{32,}={0,2}/',
        ], '?', $text);
    }

    public static function redact(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }
}
