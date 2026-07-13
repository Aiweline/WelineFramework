<?php

declare(strict_types=1);

namespace Weline\Customer\Api\View;

/** Data-only request projection for account sidebar-content hook providers. */
final readonly class AccountSidebarProjection
{
    public function __construct(
        private string $requestedSection,
        private int $websiteId,
        private ?int $customerId,
    ) {
    }

    public function getRequestedSection(): string
    {
        return $this->requestedSection;
    }

    public function isSection(string $section): bool
    {
        return $this->requestedSection === $section;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function isLoggedIn(): bool
    {
        return $this->customerId !== null && $this->customerId > 0;
    }
}
