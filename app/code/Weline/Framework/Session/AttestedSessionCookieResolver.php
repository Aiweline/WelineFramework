<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Context;

/** Selects only the request Session whose digest was bound into a page proof. */
class AttestedSessionCookieResolver
{
    public function resolve(string $expectedFingerprint): ?string
    {
        if (\preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1) {
            return null;
        }

        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (!\is_array($cookies)) {
            return null;
        }

        $candidateNames = \array_values(\array_unique(\array_filter([
            ...SessionCookieNameResolver::requestCookieCandidates(null, 'frontend'),
            ...SessionCookieNameResolver::requestCookieCandidates(null, 'backend'),
        ], static fn(string $name): bool => $name !== '')));

        foreach ($candidateNames as $name) {
            $sessionId = $cookies[$name] ?? null;
            if (!\is_string($sessionId)
                || $sessionId === ''
                || \strlen($sessionId) > 4096
                || \preg_match('/^[\x21-\x7E]+$/D', $sessionId) !== 1) {
                continue;
            }
            if (\hash_equals($expectedFingerprint, \hash('sha256', $sessionId))) {
                return $sessionId;
            }
        }

        return null;
    }
}
