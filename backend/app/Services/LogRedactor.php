<?php

namespace App\Services;

/**
 * Masks credentials in log text before it leaves the server (Settings >
 * System logs view and download): `password=`/`secret:`/`*_token`/`*_key`
 * style key-value pairs (plain, JSON or env-dump form), `Bearer` tokens,
 * `base64:` app keys, and passwords embedded in connection URLs.
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

    public static function redact(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }
}
