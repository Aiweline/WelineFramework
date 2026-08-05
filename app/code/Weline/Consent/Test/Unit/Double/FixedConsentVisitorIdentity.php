<?php

declare(strict_types=1);

namespace Weline\Consent\Test\Unit\Double;

use Weline\Consent\Api\ConsentVisitorIdentityInterface;

final class FixedConsentVisitorIdentity implements ConsentVisitorIdentityInterface
{
    public function __construct(
        private readonly string $visitorKey,
    ) {
    }

    public function resolveOrIssue(): string
    {
        return $this->visitorKey;
    }

    public function assertNoClientOverride(array $params): void
    {
        if (array_key_exists('visitor_key', $params)) {
            throw new \InvalidArgumentException('consent_visitor_key_forbidden');
        }
    }
}
