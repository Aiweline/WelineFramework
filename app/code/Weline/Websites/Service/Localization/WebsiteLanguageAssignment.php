<?php

declare(strict_types=1);

namespace Weline\Websites\Service\Localization;

use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Localization\WebsiteLanguageAssignmentInterface;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Idempotent website language assignment.
 *
 * Contract: insert missing (website_id, language_code) pairs only; existing
 * assignments are never deleted or replaced. website_id = 0 (the system
 * default website) is a valid target. Concurrency safety comes from a single
 * dialect-safe upsert against the uk_website_language unique index instead of
 * find-then-insert with duplicate-error string matching.
 */
final class WebsiteLanguageAssignment implements WebsiteLanguageAssignmentInterface
{
    public function __construct(
        private readonly WebsiteLanguage $websiteLanguage,
    ) {
    }

    public function ensureAssigned(int $websiteId, array $readyLocales): void
    {
        if ($websiteId < 0) {
            return;
        }

        $rows = [];
        foreach ($readyLocales as $candidate) {
            $code = \trim((string)$candidate);
            if ($code === '') {
                continue;
            }
            $rows[$code] = [
                WebsiteLanguage::schema_fields_WEBSITE_ID => $websiteId,
                WebsiteLanguage::schema_fields_LANGUAGE_CODE => $code,
            ];
        }
        if ($rows === []) {
            return;
        }

        // Dialect-safe upsert on the unique (website_id, language_code) index:
        // conflicting rows re-write identical values, so existing assignments
        // stay untouched and concurrent publishers cannot race each other.
        $model = clone $this->websiteLanguage;
        $model->clearData(true);
        $model->clearQuery()
            ->insert(
                \array_values($rows),
                [
                    WebsiteLanguage::schema_fields_WEBSITE_ID,
                    WebsiteLanguage::schema_fields_LANGUAGE_CODE,
                ],
            )
            ->fetch();

        // Language caches must only reflect committed data: inside a publish
        // transaction the clear is deferred to afterCommit.
        $connection = $model->getConnection();
        /** @var TransactionCoordinatorInterface $transactions */
        $transactions = ObjectManager::getInstance(TransactionCoordinatorInterface::class);
        if ($transactions->isActive($connection)) {
            $websiteLanguage = $this->websiteLanguage;
            $transactions->afterCommit(
                $connection,
                'website_language_assignment_' . $websiteId,
                static function () use ($websiteLanguage, $websiteId): void {
                    $websiteLanguage->clearWebsiteLanguageCaches($websiteId);
                },
            );

            return;
        }
        $this->websiteLanguage->clearWebsiteLanguageCaches($websiteId);
    }
}
