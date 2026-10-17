<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Extra\ExtraTypeProviderInterface;
use Weline\Framework\Controller\Extra\ExtraTypeRegistry;
use Weline\Framework\Extends\Module\Weline_Framework\Extra\Type\FpcExtraType;
use Weline\Framework\Runtime\TraceGate;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllControllerContractsPass().
 */
final class ControllerContractsTest extends TestCase
{
    public function testAllControllerContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ExtraTypeRegistry::testFpcExtraTypeNormalizesDeclaration' => function () {
            $this->assert_ExtraTypeRegistry_testFpcExtraTypeNormalizesDeclaration();
        },
            'ExtraTypeRegistry::testExtraTypeProviderInterfacePrefix' => function () {
            $this->assert_ExtraTypeRegistry_testExtraTypeProviderInterfacePrefix();
        },
            'ExtraTypeRegistry::testRegistryClassExists' => function () {
            $this->assert_ExtraTypeRegistry_testRegistryClassExists();
        },
            'TierAStorefrontExtraFpc::testTierAControllersDeclareExtraFpcPatterns' => function () {
            $this->assert_TierAStorefrontExtraFpc_testTierAControllersDeclareExtraFpcPatterns();
        },
            'TierAStorefrontExtraFpc::testCmsAndBlogChangedTypesBumpSharedParents' => function () {
            $this->assert_TierAStorefrontExtraFpc_testCmsAndBlogChangedTypesBumpSharedParents();
        },
            'PcControllerFetchTrace::testFetchTemplateWithEventsRecordsControllerFetchSpans' => function () {
            $this->assert_PcControllerFetchTrace_testFetchTemplateWithEventsRecordsControllerFetchSpans();
        },
        ];
    }

private function assert_ExtraTypeRegistry_testFpcExtraTypeNormalizesDeclaration(): void
{
    $type = new FpcExtraType();
    self::assertSame('fpc', $type->type());
    $normalized = $type->normalize(['type' => 'fpc', 'enabled' => 'true', 'ttl' => '600', 'namespaces' => ['website/default/catalog'], 'public_path_patterns' => ['/catalog/product/*']]);
    self::assertTrue($normalized['enabled']);
    self::assertSame(600, $normalized['ttl']);
    self::assertSame(['website/default/catalog'], $normalized['namespaces']);
    self::assertSame(['/catalog/product/*'], $normalized['public_path_patterns']);
    self::assertSame([], $normalized['query_keys']);
    $withKeys = $type->normalize(['type' => 'fpc', 'query_keys' => 'price,AF_Color,price']);
    self::assertSame(['af_color', 'price'], $withKeys['query_keys']);
    self::assertSame([], $type->normalize(['type' => 'fpc', 'query_keys' => '*'])['query_keys']);
    self::assertSame([], $type->normalize(['type' => 'fpc', 'query_keys' => '_wb_nc,price'])['query_keys']);
    self::assertSame([], $type->normalize(['type' => 'fpc', 'query_keys' => 'w_fpc_pierce,price'])['query_keys']);
    // Declaration order must not change normalized identity list (sorted unique).
    self::assertSame(
        ['af_x', 'page', 'price', 'sort'],
        $type->normalize(['type' => 'fpc', 'query_keys' => 'sort,price,af_x,page,price'])['query_keys'],
    );
    $defaults = $type->normalize(['type' => 'fpc']);
    self::assertTrue($defaults['enabled']);
    self::assertSame(31536000, $defaults['ttl']);
    self::assertSame([], $defaults['query_keys']);
    $spec = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/doc/开发/spec/controller-extra-fpc.md');
    self::assertStringContainsString('1e. WHEN `query_keys`', $spec);
    self::assertStringContainsString('UC-03 query_keys', $spec);
    self::assertStringContainsString('path-only', $spec);
}

private function assert_ExtraTypeRegistry_testExtraTypeProviderInterfacePrefix(): void
{
    self::assertStringContainsString('extra/type/', ExtraTypeProviderInterface::EXTENDS_RELATIVE_PREFIX);
}

private function assert_ExtraTypeRegistry_testRegistryClassExists(): void
{
    self::assertTrue(class_exists(ExtraTypeRegistry::class));
}

private function assert_TierAStorefrontExtraFpc_testTierAControllersDeclareExtraFpcPatterns(): void
{
    $weline = '/Users/weline/Project/Official/框架/app/code/Weline';
    $expected = [$weline . '/Theme/Controller/Frontend/Policy.php' => ['/about', '/sitemap', '/guide', '/qa', '/activity', '/policy', '/policy/*', '/terms', 'website/default/theme'], $weline . '/Currency/Controller/Frontend/Index.php' => ['/currency', 'website/default/theme', 'global/storefront/price'], $weline . '/Faq/Controller/Frontend/Index.php' => ['/faq', 'website/default/cms'], $weline . '/Faq/Controller/Frontend/View.php' => ['/faq/*', 'website/default/cms'], $weline . '/Blog/Controller/Frontend/Index.php' => ['/blog', 'global/storefront/blog'], $weline . '/Blog/Controller/Frontend/View.php' => ['/blog/*', 'global/storefront/blog'], $weline . '/Blog/Controller/Frontend/Category.php' => ['/blog/category/*', 'global/storefront/blog'], $weline . '/Shipping/Controller/Frontend/Guide/Shipping.php' => ['/guide/shipping', 'website/default/theme'], $weline . '/Shipping/Controller/Frontend/Guide/Returns.php' => ['/guide/returns', 'website/default/theme'], $weline . '/Payment/Controller/Frontend/Guide/Payment.php' => ['/guide/payment', 'website/default/theme'], $weline . '/Customer/Controller/Frontend/Guide/SocialLogin.php' => ['/guide/social-login', 'website/default/theme']];
    foreach ($expected as $file => $needles) {
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertMatchesRegularExpression('/@Extra\s+type=fpc\b/', $src, basename($file));
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $src, basename($file) . ' missing ' . $needle);
        }
    }
}

private function assert_TierAStorefrontExtraFpc_testCmsAndBlogChangedTypesBumpSharedParents(): void
{
    $weline = '/Users/weline/Project/Official/框架/app/code/Weline';
    $cmsSrc = (string) file_get_contents($weline . '/Cms/extends/module/Weline_Framework/Changed/Type/CmsPageChangedType.php');
    self::assertStringContainsString('/cms', $cmsSrc);
    $publisher = (string) file_get_contents($weline . '/Cms/Service/CmsPageResourceChangePublisher.php');
    self::assertStringContainsString("['cms']", $publisher);
    $blogCache = (string) file_get_contents($weline . '/Blog/Service/BlogContentCache.php');
    self::assertStringContainsString("['blog']", $blogCache);
    self::assertFileExists($weline . '/Blog/extends/module/Weline_Framework/Changed/Type/BlogPostChangedType.php');
    self::assertFileExists($weline . '/Blog/extends/module/Weline_Framework/Changed/Type/BlogCategoryChangedType.php');
    self::assertFileExists($weline . '/Faq/extends/module/Weline_Framework/Changed/Type/FaqItemChangedType.php');
    $faqPub = (string) file_get_contents($weline . '/Faq/Service/FaqService.php');
    self::assertStringContainsString('FaqResourceChangePublisher', $faqPub);
}

private function assert_PcControllerFetchTrace_testFetchTemplateWithEventsRecordsControllerFetchSpans(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/PcController.php');
    self::assertStringContainsString('use Weline\Framework\Runtime\RequestLifecycleTrace;', $src);
    self::assertStringContainsString("'controller.fetch.before'", $src);
    self::assertStringContainsString("'controller.fetch.template'", $src);
    self::assertStringContainsString("'controller.fetch.after'", $src);
    self::assertStringContainsString('TraceGate::recordSpan', $src);
    self::assertStringContainsString('template.fetch.profile', $src);
}
}
