<?php

declare(strict_types=1);

namespace Weline\I18n\Api\LanguageRequest;

interface LanguageSupportRequestDirectoryInterface
{
    /** @return list<array<string, mixed>> */
    public function readyForWebsite(int $websiteId): array;

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed>
     */
    public function adminList(array $filters = []): array;
}
