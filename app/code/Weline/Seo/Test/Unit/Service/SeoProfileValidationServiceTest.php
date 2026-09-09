<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Profile\SeoProfileValidationService;

class SeoProfileValidationServiceTest extends TestCase
{
    public function testValidatesNewsSitemapRequiredFields(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'news_article',
            'title' => 'Launch News',
            'description' => 'A launch update.',
            'canonical_url' => 'https://shop.test/news/launch',
            'robots' => 'index,follow',
            'article' => ['headline' => 'Launch News', 'datePublished' => '2026-05-25 10:00:00', 'author' => 'Editor'],
            'sitemap' => [
                'news' => [
                    'publication' => ['name' => 'Shop News', 'language' => 'zh-cn'],
                    'publication_date' => '2026-05-25 10:00:00',
                    'title' => 'Launch News',
                ],
            ],
        ]);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
    }

    public function testNoindexPagesCannotBeIncludedInSitemapOrGeo(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'checkout',
            'title' => 'Checkout',
            'description' => 'Checkout page.',
            'canonical_url' => 'https://shop.test/checkout',
            'robots' => 'noindex,follow',
            'sitemap' => ['include' => true],
            'geo' => ['include' => true],
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('noindex pages must be excluded from sitemap metadata.', $result['errors']);
        self::assertContains('noindex pages must be excluded from GEO feed metadata.', $result['errors']);
    }

    public function testFaqPageWithoutFaqsProducesWarning(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'faq',
            'title' => 'FAQ',
            'description' => 'FAQ page.',
            'canonical_url' => 'https://shop.test/faq',
            'robots' => 'index,follow',
            'faqs' => [],
        ]);

        self::assertTrue($result['valid']);
        self::assertContains('faq pages should expose faqs facts for FAQPage schema.', $result['warnings']);
    }

    public function testQaPageWithoutFactsProducesWarning(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'qa_page',
            'title' => 'QA',
            'description' => 'QA page.',
            'canonical_url' => 'https://shop.test/qa',
            'robots' => 'index,follow',
        ]);

        self::assertTrue($result['valid']);
        self::assertContains('qa pages should expose qa_list, faqs, or a QAPage schema node.', $result['warnings']);
    }

    public function testShallowArticleAuthorProducesEeatWarning(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'blog_post',
            'title' => 'Post',
            'description' => 'Body.',
            'canonical_url' => 'https://shop.test/blog/a',
            'robots' => 'index,follow',
            'article' => [
                'headline' => 'Post',
                'datePublished' => '2026-05-25 10:00:00',
                'authors' => [['@type' => 'Person', 'name' => 'Editor']],
            ],
        ]);

        self::assertTrue($result['valid']);
        self::assertContains(
            'article authors should expose Person url or sameAs (Helpful Content Who); name-only without identity link is incomplete for internal self-check.',
            $result['warnings']
        );
    }

    public function testJobTitleOnlyAuthorStillWarnsForWhoIdentity(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'blog_post',
            'title' => 'Post',
            'description' => 'Body.',
            'canonical_url' => 'https://shop.test/blog/a',
            'robots' => 'index,follow',
            'article' => [
                'headline' => 'Post',
                'datePublished' => '2026-05-25 10:00:00',
                'authors' => [[
                    '@type' => 'Person',
                    'name' => 'Editor',
                    'jobTitle' => 'Hanfu researcher',
                ]],
            ],
        ]);

        self::assertTrue($result['valid']);
        self::assertContains(
            'article authors should expose Person url or sameAs (Helpful Content Who); name-only without identity link is incomplete for internal self-check.',
            $result['warnings']
        );
    }

    public function testDeepArticleAuthorSkipsShallowWarning(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'blog_post',
            'title' => 'Post',
            'description' => 'Body.',
            'canonical_url' => 'https://shop.test/blog/a',
            'robots' => 'index,follow',
            'article' => [
                'headline' => 'Post',
                'datePublished' => '2026-05-25 10:00:00',
                'authors' => [[
                    '@type' => 'Person',
                    'name' => 'Editor',
                    'jobTitle' => 'Hanfu researcher',
                    'url' => 'https://shop.test/about/editor',
                ]],
            ],
        ]);

        self::assertTrue($result['valid']);
        self::assertNotContains(
            'article authors should expose Person url or sameAs (Helpful Content Who); name-only without identity link is incomplete for internal self-check.',
            $result['warnings']
        );
    }

    public function testOrganizationWithoutSameAsProducesWarning(): void
    {
        $result = (new SeoProfileValidationService())->validate([
            'page_type' => 'home',
            'title' => 'Home',
            'description' => 'Home.',
            'canonical_url' => 'https://shop.test/',
            'robots' => 'index,follow',
            'organization' => ['name' => 'Shop', 'url' => 'https://shop.test/'],
        ]);

        self::assertTrue($result['valid']);
        self::assertContains(
            'organization should expose sameAs profile URLs (Helpful Content Trust / entity example); not a ranking gate.',
            $result['warnings']
        );
    }
}
