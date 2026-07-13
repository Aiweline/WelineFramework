<?php
declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Transport-neutral HTTP/1.x connection persistence rules.
 */
final class ConnectionSemantics
{
    public static function shouldKeepAlive(string $protocol, string $connectionHeader = ''): bool
    {
        $hasKeepAlive = false;
        foreach (\explode(',', $connectionHeader) as $rawToken) {
            $token = \strtolower(\trim($rawToken, " \t"));
            if ($token === 'close') {
                // A close token in any repeated/comma-joined Connection field
                // wins over every persistence token.
                return false;
            }
            if ($token === 'keep-alive') {
                $hasKeepAlive = true;
            }
        }

        $protocol = \strtoupper(\trim($protocol));
        if ($protocol === 'HTTP/1.0') {
            return $hasKeepAlive;
        }

        return $protocol === 'HTTP/1.1';
    }
}
