<?php
declare(strict_types=1);

namespace Weline\Server\Service;

final class ResponseStatusResolver
{
    public function resolve(string $result, ?int $explicitStatusCode = null, bool $hasExplicitStatusCode = false): int
    {
        if ($hasExplicitStatusCode && $explicitStatusCode !== null) {
            return $explicitStatusCode;
        }

        $first = \ltrim($result);
        if (($first[0] ?? '') !== '{') {
            return 200;
        }

        $decoded = \json_decode($result, true);
        if (!\is_array($decoded) || !\array_key_exists('code', $decoded)) {
            return 200;
        }

        $code = (int) $decoded['code'];
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        return 200;
    }
}
