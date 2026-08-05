<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

/**
 * Public contract for ensuring website language assignments exist.
 *
 * Implementations must only insert missing (website_id, language_code) pairs.
 * Existing assignments must never be deleted or replaced.
 * website_id = 0 (system default site) is a valid target.
 */
interface WebsiteLanguageAssignmentInterface
{
    /**
     * @param list<string> $readyLocales Canonical locale codes to ensure
     */
    public function ensureAssigned(int $websiteId, array $readyLocales): void;
}
