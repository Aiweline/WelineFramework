<?php

declare(strict_types=1);

namespace Weline\Consent\Service;

use Weline\Consent\Api\ConsentVisitorIdentityInterface;
use Weline\Framework\Http\Cookie;

final class ConsentVisitorIdentity implements ConsentVisitorIdentityInterface
{
    public const COOKIE_NAME = 'weline_consent_vid';
    public const COOKIE_TTL = 31536000;

    public function resolveOrIssue(): string
    {
        $current = trim((string)Cookie::get(self::COOKIE_NAME, ''));
        if ($this->isValid($current)) {
            return $current;
        }

        $visitorKey = 'v1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Cookie::set(self::COOKIE_NAME, $visitorKey, self::COOKIE_TTL, [
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $visitorKey;
    }

    public function assertNoClientOverride(array $params): void
    {
        if (array_key_exists('visitor_key', $params)) {
            throw new \InvalidArgumentException('consent_visitor_key_forbidden');
        }
    }

    private function isValid(string $visitorKey): bool
    {
        return preg_match('/^v1_[A-Za-z0-9_-]{43}$/D', $visitorKey) === 1;
    }
}
