<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * ensureDefaultThemes must not treat a stale Model identity as "already seeded"
 * for another website (find/fetch miss can leave the previous row's id).
 */
final class EnsureDefaultThemesExistingMatchContractTest extends TestCase
{
    public function testEnsureDefaultThemesVerifiesWebsiteAndSlugBeforeSkip(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/PromotionActivityThemeService.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('function ensureDefaultThemes', $src);
        self::assertStringContainsString('existingMatches', $src);
        self::assertStringContainsString('schema_fields_WEBSITE_ID) === (int)$websiteId', $src);
        self::assertStringContainsString('schema_fields_PAGE_SLUG))) === strtolower(trim((string)$row[\'page_slug\'])', $src);
        self::assertStringContainsString("'product_ids' => []", $src);
    }
}
