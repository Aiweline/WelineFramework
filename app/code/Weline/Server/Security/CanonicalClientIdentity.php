<?php

declare(strict_types=1);

namespace Weline\Server\Security;

final class CanonicalClientIdentity
{
    /**
     * @param array<string, string> $headers Lowercase header map.
     * @param list<string> $trustedProxyCidrs
     * @return array{ip:string,trusted_proxy:bool,transport_ip:string}
     */
    public function resolve(string $transportPeer, array $headers, array $trustedProxyCidrs): array
    {
        $transportIp = $this->normalizePeer($transportPeer);
        $trustedProxy = $transportIp !== '' && $this->matchesAny($transportIp, $trustedProxyCidrs);
        $clientIp = $transportIp;

        if ($trustedProxy) {
            // A client can inject single-value CF/X-Real/Weline headers unless
            // every upstream is explicitly configured to overwrite them. The
            // trusted-proxy contract therefore derives identity only from the
            // append-only XFF chain: peel trusted hops from the right and use
            // the nearest untrusted address. A malformed or all-trusted chain
            // fails closed to the authenticated transport peer.
            $forwardedClient = $this->resolveForwardedFor(
                (string)($headers['x-forwarded-for'] ?? ''),
                $trustedProxyCidrs,
            );
            if ($forwardedClient !== '') {
                $clientIp = $forwardedClient;
            }
        }

        if ($clientIp === '') {
            // Unknown transport identity must never inherit loopback trust.
            $clientIp = '0.0.0.0';
        }

        return [
            'ip' => $clientIp,
            'trusted_proxy' => $trustedProxy,
            'transport_ip' => $transportIp !== '' ? $transportIp : $clientIp,
        ];
    }

    public function normalizePeer(string $peer): string
    {
        $peer = \trim($peer);
        if ($peer === '') {
            return '';
        }
        if ($peer[0] === '[') {
            $end = \strpos($peer, ']');
            if ($end !== false) {
                $candidate = \substr($peer, 1, $end - 1);
                return $this->normalizeIp($candidate);
            }
        }
        $literal = $this->normalizeIp($peer);
        if ($literal !== '') {
            return $literal;
        }
        $lastColon = \strrpos($peer, ':');
        if ($lastColon !== false) {
            $candidate = \substr($peer, 0, $lastColon);
            $literal = $this->normalizeIp($candidate);
            if ($literal !== '') {
                return $literal;
            }
        }
        return '';
    }

    public function normalizeIp(string $ip): string
    {
        $ip = \trim($ip);
        if ($ip === '' || !\filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }

        return $this->normalizeMappedIpv4($ip);
    }

    private function normalizeMappedIpv4(string $ip): string
    {
        $lower = \strtolower($ip);
        if (\str_starts_with($lower, '::ffff:')) {
            $ipv4 = \substr($ip, 7);
            if (\filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ipv4;
            }
        }
        return $ip;
    }

    /** @param list<string> $cidrs */
    public function matchesAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->matchesCidr($ip, (string)$cidr)) {
                return true;
            }
        }
        return false;
    }

    public function matchesCidr(string $ip, string $cidr): bool
    {
        $ip = $this->normalizeIp($ip);
        $cidr = \trim($cidr);
        if ($ip === '' || $cidr === '') {
            return false;
        }
        if (!\str_contains($cidr, '/')) {
            $exact = $this->normalizeIp($cidr);
            return $exact !== '' && \hash_equals($exact, $ip);
        }
        [$network, $prefix] = \explode('/', $cidr, 2);
        if (!\preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $prefix)) {
            return false;
        }
        $ipPacked = @\inet_pton($ip);
        $networkPacked = @\inet_pton($network);
        if (!\is_string($ipPacked) || !\is_string($networkPacked) || \strlen($ipPacked) !== \strlen($networkPacked)) {
            return false;
        }
        $bits = (int)$prefix;
        $maxBits = \strlen($ipPacked) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && \substr($ipPacked, 0, $bytes) !== \substr($networkPacked, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainder)) & 0xff;
        return ((\ord($ipPacked[$bytes]) & $mask) === (\ord($networkPacked[$bytes]) & $mask));
    }

    /**
     * @param list<string> $trustedProxyCidrs
     */
    private function resolveForwardedFor(string $value, array $trustedProxyCidrs): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }

        $chain = [];
        foreach (\explode(',', $value) as $candidate) {
            $candidate = $this->normalizeIp($candidate);
            if ($candidate === '') {
                return '';
            }
            $chain[] = $candidate;
        }
        for ($index = \count($chain) - 1; $index >= 0; $index--) {
            $candidate = $chain[$index];
            if (!$this->matchesAny($candidate, $trustedProxyCidrs)) {
                return $candidate;
            }
        }

        return '';
    }
}
