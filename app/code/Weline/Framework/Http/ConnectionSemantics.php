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
        $protocol = \strtoupper(\trim($protocol));
        if (\in_array($protocol, ['H2', 'HTTP/2', 'HTTP/2.0', 'H3', 'HTTP/3', 'HTTP/3.0'], true)) {
            return true;
        }

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

        if ($protocol === 'HTTP/1.0') {
            return $hasKeepAlive;
        }

        return $protocol === 'HTTP/1.1';
    }
}
