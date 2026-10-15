<?php

declare(strict_types=1);

namespace Weline\Server\Security;

/**
 * Single source for trusted-proxy CIDRs shared by WLS RuntimePolicy and managed Nginx real_ip.
 *
 * WLS keeps loopback transport peers; Nginx set_real_ip_from must not.
 */
final class TrustedProxyCidrResolver
{
    /** @var list<string> */
    private const LOOPBACK_TRANSPORT_CIDRS = [
        '127.0.0.0/8',
        '::1/128',
    ];

    /**
     * @param array<string, mixed> $rules AttackDetector / security-rules blob
     * @param array<string, mixed> $acceptGate env wls.accept_gate
     * @return list<string>
     */
    public static function forWls(array $rules, array $acceptGate = []): array
    {
        return self::normalizeList(\array_merge(
            self::publicTrustedProxyCidrs($rules, $acceptGate),
            self::LOOPBACK_TRANSPORT_CIDRS,
        ));
    }

    /**
     * Public CDN / reverse-proxy hops for nginx real_ip (no loopback).
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $acceptGate
     * @return list<string>
     */
    public static function forNginx(array $rules, array $acceptGate = []): array
    {
        return self::withoutLoopback(self::publicTrustedProxyCidrs($rules, $acceptGate));
    }

    /**
     * Drop WLS loopback transport peers from any CIDR list (nginx real_ip must not).
     *
     * @param list<mixed>|array<int|string, mixed> $values
     * @return list<string>
     */
    public static function withoutLoopback(array $values): array
    {
        $loopback = \array_fill_keys(self::LOOPBACK_TRANSPORT_CIDRS, true);
        $filtered = [];
        foreach (self::normalizeList($values) as $cidr) {
            if (!isset($loopback[$cidr])) {
                $filtered[] = $cidr;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $acceptGate
     * @return list<string>
     */
    private static function publicTrustedProxyCidrs(array $rules, array $acceptGate): array
    {
        $trustedProxyCidrs = [];
        $trustedProxy = \is_array($rules['cdn_trusted_ips'] ?? null) ? $rules['cdn_trusted_ips'] : [];
        if (($trustedProxy['enabled'] ?? true) !== false) {
            $trustedProxyCidrs = (array)($trustedProxy['ips'] ?? []);
            // Orange-cloud: peel CF edges so shared_ban / real_ip never keys on the POP.
            $trustedProxyCidrs = \array_merge(
                $trustedProxyCidrs,
                CloudflareTrustedProxyCatalog::cidrs(),
            );
        }

        return \array_merge(
            $trustedProxyCidrs,
            (array)($acceptGate['trusted_proxy_cidrs'] ?? []),
        );
    }

    /**
     * @param list<mixed>|array<int|string, mixed> $values
     * @return list<string>
     */
    public static function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value === '' || !self::isSafeCidrLiteral($value)) {
                continue;
            }
            $normalized[$value] = true;
        }
        $normalized = \array_keys($normalized);
        \sort($normalized, \SORT_STRING);

        return $normalized;
    }

    /**
     * Reject nginx-config injection (spaces, quotes, semicolons).
     */
    public static function isSafeCidrLiteral(string $value): bool
    {
        if (\str_contains($value, '/')) {
            [$network, $prefix] = \explode('/', $value, 2);
            if (!\preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $prefix)) {
                return false;
            }
            $bits = (int)$prefix;
            if (\filter_var($network, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
                return $bits >= 0 && $bits <= 32;
            }
            if (\filter_var($network, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return $bits >= 0 && $bits <= 128;
            }

            return false;
        }

        return \filter_var($value, \FILTER_VALIDATE_IP) !== false;
    }
}
