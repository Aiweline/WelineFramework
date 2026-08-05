<?php

declare(strict_types=1);

namespace Weline\I18n\Api\LanguageRequest;

interface LanguageSupportRequestWorkflowInterface
{
    /** @param list<int> $itemIds */
    public function review(array $itemIds, string $status, int $reviewerId, string $note = ''): int;

    /** @param list<string> $locales */
    public function markAssigned(int $websiteId, array $locales, int $reviewerId): int;

    /** @param list<string>|null $locales */
    public function recalculateReady(?array $locales = null): int;
}
