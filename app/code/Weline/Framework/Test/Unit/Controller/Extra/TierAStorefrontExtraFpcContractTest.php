<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;

/**
 * A 档店面页 @Extra type=fpc 源码契约：政策/FAQ/博客/指南。
 */
final class TierAStorefrontExtraFpcContractTest extends TestCase
{
    public function testTierAControllersDeclareExtraFpcPatterns(): void
    {
        $weline = dirname(__DIR__, 5);
        $expected = [
            $weline . '/Theme/Controller/Frontend/Policy.php' => [
                '/about',
                '/sitemap',
                '/guide',
                '/qa',
                '/activity',
                '/policy',
                '/policy/*',
                '/terms',
                'website/default/theme',
            ],
            $weline . '/Currency/Controller/Frontend/Index.php' => ['/currency', 'website/default/theme', 'global/storefront/price'],
            $weline . '/Faq/Controller/Frontend/Index.php' => ['/faq', 'website/default/cms'],
            $weline . '/Faq/Controller/Frontend/View.php' => ['/faq/*', 'website/default/cms'],
            $weline . '/Blog/Controller/Frontend/Index.php' => ['/blog', 'global/storefront/blog'],
            $weline . '/Blog/Controller/Frontend/View.php' => ['/blog/*', 'global/storefront/blog'],
            $weline . '/Blog/Controller/Frontend/Category.php' => ['/blog/category/*', 'global/storefront/blog'],
            $weline . '/Shipping/Controller/Frontend/Guide/Shipping.php' => ['/guide/shipping', 'website/default/theme'],
            $weline . '/Shipping/Controller/Frontend/Guide/Returns.php' => ['/guide/returns', 'website/default/theme'],
            $weline . '/Payment/Controller/Frontend/Guide/Payment.php' => ['/guide/payment', 'website/default/theme'],
            $weline . '/Customer/Controller/Frontend/Guide/SocialLogin.php' => ['/guide/social-login', 'website/default/theme'],
        ];

        foreach ($expected as $file => $needles) {
            self::assertFileExists($file);
            $src = (string) file_get_contents($file);
            self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src, basename($file));
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $src, basename($file) . ' missing ' . $needle);
            }
        }
    }

    public function testCmsAndBlogChangedTypesBumpSharedParents(): void
    {
        $weline = dirname(__DIR__, 5);
        $cmsSrc = (string) file_get_contents(
            $weline . '/Cms/extends/module/Weline_Framework/Changed/Type/CmsPageChangedType.php'
        );
        self::assertStringContainsString('/cms', $cmsSrc);

        $publisher = (string) file_get_contents(
            $weline . '/Cms/Service/CmsPageResourceChangePublisher.php'
        );
        self::assertStringContainsString("['cms']", $publisher);

        $blogCache = (string) file_get_contents($weline . '/Blog/Service/BlogContentCache.php');
        self::assertStringContainsString("['blog']", $blogCache);

        self::assertFileExists(
            $weline . '/Blog/extends/module/Weline_Framework/Changed/Type/BlogPostChangedType.php'
        );
        self::assertFileExists(
            $weline . '/Blog/extends/module/Weline_Framework/Changed/Type/BlogCategoryChangedType.php'
        );
        self::assertFileExists(
            $weline . '/Faq/extends/module/Weline_Framework/Changed/Type/FaqItemChangedType.php'
        );
        $faqPub = (string) file_get_contents($weline . '/Faq/Service/FaqService.php');
        self::assertStringContainsString('FaqResourceChangePublisher', $faqPub);
    }
}
