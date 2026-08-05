<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Remove credential material from authenticated gateway payloads.
 *
 * This is deliberately applied after response MAC verification: compatible
 * host slots released before controller-side redaction can otherwise expose
 * backend capabilities to non-CLI consumers in a newer project runtime.
 */
final class GatewaySensitivePayloadSanitizer
{
    public static function sanitize(mixed $value): mixed
    {
        $seenObjects = new \SplObjectStorage();
        $sanitize = static function (mixed $item, int $depth = 0) use (&$sanitize, $seenObjects): mixed {
            if ($depth > 64) {
                return null;
            }
            if (\is_object($item)) {
                if ($seenObjects->contains($item)) {
                    return null;
                }
                $seenObjects->attach($item);
                try {
                    return $sanitize(
                        $item instanceof \JsonSerializable
                            ? $item->jsonSerialize()
                            : \get_object_vars($item),
                        $depth + 1,
                    );
                } finally {
                    $seenObjects->detach($item);
                }
            }
            if (!\is_array($item)) {
                return $item;
            }
            $sanitized = [];
            foreach ($item as $key => $child) {
                if (\is_string($key) && self::isSensitiveKey($key)) {
                    continue;
                }
                $sanitized[$key] = $sanitize($child, $depth + 1);
            }
            return $sanitized;
        };

        return $sanitize($value);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', '', $key));
        if ($normalized === '') {
            return false;
        }
        if (\str_contains($normalized, 'credential')) {
            if (\preg_match(
                '/credential(?:id|identifier|reference|generation|digest|hash|fingerprint|thumbprint|installed|available|present|status|receipt)\z/D',
                $normalized,
            ) !== 1) {
                return true;
            }
            $prefix = (string)\preg_replace(
                '/credential(?:id|identifier|reference|generation|digest|hash|fingerprint|thumbprint|installed|available|present|status|receipt)\z/D',
                '',
                $normalized,
            );
            return self::hasSensitiveMaterial($prefix);
        }
        if (\preg_match('/(?:digest|hash|fingerprint|thumbprint)/', $normalized) === 1
            || \preg_match(
                '/\A(?:response)?(?:signature|mac)(?:algorithm|version|metadata|keyid|status)?\z/D',
                $normalized,
            ) === 1
        ) {
            return false;
        }
        return self::hasSensitiveMaterial($normalized);
    }

    private static function hasSensitiveMaterial(string $normalized): bool
    {
        return \str_contains($normalized, 'secret')
            || \str_contains($normalized, 'token')
            || \str_contains($normalized, 'authorization')
            || \str_contains($normalized, 'password')
            || \str_contains($normalized, 'passphrase')
            || \str_contains($normalized, 'apikey')
            || \str_contains($normalized, 'signingkey')
            || \str_contains($normalized, 'privatekey')
            || \preg_match(
                '/(?:auth|authentication)(?:data|header|material|value|key|bearer)/',
                $normalized,
            ) === 1;
    }
}
