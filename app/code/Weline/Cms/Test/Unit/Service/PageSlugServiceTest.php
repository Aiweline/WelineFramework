<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Service\PageSlugService;

final class PageSlugServiceTest extends TestCase
{
    #[DataProvider('slugCases')]
    public function testSlugify(string $title, string $expected): void
    {
        self::assertSame($expected, (new PageSlugService())->slugify($title));
    }

    public static function slugCases(): array
    {
        return [
            'english' => ['About Our Team', 'about-our-team'],
            'chinese transliteration' => ['博客', 'bo-ke'],
            'punctuation and version' => [' Hello, PHP 8.4! ', 'hello-php-8-4'],
            'empty transliteration' => ['!!!', 'page'],
        ];
    }

    public function testAutoSlugUsesMinimalSameWebsiteSuffixAndForwardsWebsiteZero(): void
    {
        $calls = [];
        $service = new PageSlugService(conflictChecker: static function (
            string $identifier,
            int $websiteId,
            int $pageId
        ) use (&$calls): bool {
            $calls[] = [$identifier, $websiteId, $pageId];
            return in_array($identifier, ['blog/about', 'blog/about-2'], true);
        });
        $page = new Page();

        $decision = $service->resolveForSave(
            $page,
            'About',
            '',
            'blog',
            0,
            Page::STATUS_DRAFT,
            Page::SLUG_MODE_AUTO,
        );

        self::assertSame('about-3', $decision['slug']);
        self::assertSame('blog/about-3', $decision['identifier']);
        self::assertSame(Page::SLUG_MODE_AUTO, $decision['mode']);
        self::assertSame(64, strlen($decision['source_hash']));
        self::assertSame(0, $calls[0][1]);
    }

    public function testSameSlugOnAnotherWebsiteDoesNotConflict(): void
    {
        $service = new PageSlugService(conflictChecker: static fn(
            string $identifier,
            int $websiteId
        ): bool => $identifier === 'blog/about' && $websiteId === 2);

        $decision = $service->resolveForSave(
            new Page(),
            'About',
            '',
            'blog',
            1,
            Page::STATUS_DRAFT,
            Page::SLUG_MODE_AUTO,
        );

        self::assertSame('about', $decision['slug']);
    }

    public function testReservedTopLevelSlugIsPrefixed(): void
    {
        $decision = (new PageSlugService())->resolveForSave(
            new Page(),
            'CMS',
            '',
            '',
            0,
            Page::STATUS_DRAFT,
            Page::SLUG_MODE_AUTO,
        );

        self::assertSame('page-cms', $decision['slug']);
    }

    public function testHistoricalRandomDraftBecomesAutoButNormalLegacySlugStaysManual(): void
    {
        $random = new Page([
            Page::schema_fields_ID => 10,
            Page::schema_fields_SLUG => '20260729052847-c6796ab7',
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ]);
        $randomDecision = (new PageSlugService())->resolveForSave(
            $random,
            'Friendly title',
            $random->getSlug(),
            'blog',
            0,
            Page::STATUS_DRAFT,
        );
        self::assertSame('friendly-title', $randomDecision['slug']);
        self::assertSame(Page::SLUG_MODE_AUTO, $randomDecision['mode']);

        $normal = new Page([
            Page::schema_fields_ID => 11,
            Page::schema_fields_SLUG => 'stable-history',
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ]);
        $normalDecision = (new PageSlugService())->resolveForSave(
            $normal,
            'Changed title',
            'stable-history',
            'blog',
            0,
            Page::STATUS_DRAFT,
        );
        self::assertSame('stable-history', $normalDecision['slug']);
        self::assertSame(Page::SLUG_MODE_MANUAL, $normalDecision['mode']);
    }

    public function testManualAndPublishedSlugsDoNotFollowTitle(): void
    {
        $manual = new Page([
            Page::schema_fields_ID => 12,
            Page::schema_fields_SLUG => 'custom-slug',
            Page::schema_fields_SLUG_MODE => Page::SLUG_MODE_MANUAL,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ]);
        $manualDecision = (new PageSlugService())->resolveForSave(
            $manual,
            'Changed title',
            'custom-slug',
            'blog',
            0,
            Page::STATUS_DRAFT,
        );
        self::assertSame('custom-slug', $manualDecision['slug']);
        self::assertSame(Page::SLUG_MODE_MANUAL, $manualDecision['mode']);

        $published = new Page([
            Page::schema_fields_ID => 13,
            Page::schema_fields_SLUG => 'published-url',
            Page::schema_fields_SLUG_MODE => Page::SLUG_MODE_AUTO,
            Page::schema_fields_STATUS => Page::STATUS_PUBLISHED,
        ]);
        $publishedDecision = (new PageSlugService())->resolveForSave(
            $published,
            'Changed title',
            'attempted-change',
            'blog',
            0,
            Page::STATUS_PUBLISHED,
        );
        self::assertSame('published-url', $publishedDecision['slug']);
        self::assertSame(Page::SLUG_MODE_FROZEN, $publishedDecision['mode']);
    }

    public function testChangingAnAutoSlugExplicitlySwitchesToManual(): void
    {
        $page = new Page([
            Page::schema_fields_ID => 14,
            Page::schema_fields_SLUG => 'automatic-title',
            Page::schema_fields_SLUG_MODE => Page::SLUG_MODE_AUTO,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
        ]);

        $decision = (new PageSlugService())->resolveForSave(
            $page,
            'New title',
            'editor-choice',
            'blog',
            0,
            Page::STATUS_DRAFT,
        );

        self::assertSame('editor-choice', $decision['slug']);
        self::assertSame(Page::SLUG_MODE_MANUAL, $decision['mode']);
    }
}
