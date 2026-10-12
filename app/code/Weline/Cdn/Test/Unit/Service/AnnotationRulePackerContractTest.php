<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\AnnotationPackFlagResolver;
use Weline\Cdn\Service\AnnotationRulePacker;

/**
 * 注解三面打包契约：OR+中位TTL、admin 硬拒绝、identity、默认 pack 开。
 * 测试席可继续补全预算帽/拆包/expression 长度等边界。
 */
final class AnnotationRulePackerContractTest extends TestCase
{
    public function testFlagResolverConfigKeyAndDefaultTruthy(): void
    {
        self::assertSame('cdn/rules/pack_annotations', AnnotationPackFlagResolver::CONFIG_KEY);
        $resolver = new AnnotationPackFlagResolver(null);
        self::assertTrue($resolver->isEnabled());
    }

    public function testPackOrPathsMedianTtlAndIdentity(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\DemoA',
                'method' => 'index',
                'route' => '/api/a',
                'expression' => 'starts_with(http.request.uri.path, "/api/a")',
                'action' => ['cache' => ['ttl' => 100, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\DemoB',
                'method' => 'index',
                'route' => '/api/b',
                'expression' => 'starts_with(http.request.uri.path, "/api/b")',
                'action' => ['cache' => ['ttl' => 300, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
        ], 5);

        self::assertCount(1, $packs);
        $pack = $packs[0];
        self::assertSame('pack:ttl:api:0', $pack['_weline_identity']);
        self::assertStringContainsString('/api/a', (string)$pack['expression']);
        self::assertStringContainsString('/api/b', (string)$pack['expression']);
        self::assertStringContainsString(' or ', (string)$pack['expression']);
        self::assertSame(200, (int)$pack['action']['cache']['ttl']);
        self::assertSame('override_origin', $pack['action']['cache']['mode']);
        self::assertStringStartsWith('Weline pack:ttl:api ttl=200 n=', (string)$pack['description']);
    }

    public function testCacheableAdminPathRejectedFromPackExpression(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Backend',
                'class' => 'Weline\\Foo\\Controller\\Backend\\Dash',
                'method' => 'index',
                'route' => '/admin/dash',
                'expression' => 'starts_with(http.request.uri.path, "/admin/dash")',
                'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Store',
                'class' => 'Weline\\Store\\Controller\\Frontend\\Home',
                'method' => 'index',
                'route' => '/home',
                'expression' => 'starts_with(http.request.uri.path, "/home")',
                'action' => ['cache' => ['ttl' => 120, 'status_code' => [200]]],
            ],
        ], 5);

        foreach ($packs as $pack) {
            $expr = \strtolower((string)($pack['expression'] ?? ''));
            self::assertStringNotContainsString('/admin/', $expr);
            self::assertStringNotContainsString('/backend/', $expr);
        }
        self::assertNotEmpty($packs);
        self::assertStringContainsString('/home', (string)$packs[0]['expression']);
    }

    public function testCacheFalseOnlyAdminSurfaceEmitsNoPack(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Backend',
                'class' => 'Weline\\Foo\\Controller\\Backend\\Dash',
                'method' => 'index',
                'route' => '/admin/dash',
                'expression' => 'starts_with(http.request.uri.path, "/admin/dash")',
                'action' => ['cache' => false],
            ],
        ], 5);

        self::assertSame([], $packs);
    }

    public function testCacheableBackendPathRejectedFromPackExpression(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Backend',
                'class' => 'Weline\\Foo\\Controller\\Backend\\Panel',
                'method' => 'index',
                'route' => '/backend/panel',
                'expression' => 'starts_with(http.request.uri.path, "/backend/panel")',
                'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Store',
                'class' => 'Weline\\Store\\Controller\\Frontend\\Catalog',
                'method' => 'index',
                'route' => '/catalog',
                'expression' => 'starts_with(http.request.uri.path, "/catalog")',
                'action' => ['cache' => ['ttl' => 90, 'status_code' => [200]]],
            ],
        ], 5);

        foreach ($packs as $pack) {
            $expr = \strtolower((string)($pack['expression'] ?? ''));
            self::assertStringNotContainsString('/admin/', $expr);
            self::assertStringNotContainsString('/backend/', $expr);
        }
        self::assertNotEmpty($packs);
        self::assertStringContainsString('/catalog', (string)$packs[0]['expression']);
    }

    public function testTtlRatioSplitProducesTwoApiPacks(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\Short',
                'method' => 'index',
                'route' => '/api/short',
                'expression' => 'starts_with(http.request.uri.path, "/api/short")',
                'action' => ['cache' => ['ttl' => 60, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\Long',
                'method' => 'index',
                'route' => '/api/long',
                'expression' => 'starts_with(http.request.uri.path, "/api/long")',
                'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]],
            ],
        ], 5);

        self::assertCount(2, $packs);
        self::assertSame('pack:ttl:api:0', $packs[0]['_weline_identity']);
        self::assertSame('pack:ttl:api:1', $packs[1]['_weline_identity']);
    }

    public function testSearchCacheFalseEmitsForbidPackNotSpecialTtl(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Search',
                'class' => 'Weline\\Search\\Controller\\Frontend\\Index',
                'method' => 'index',
                'route' => '/search',
                'expression' => 'starts_with(http.request.uri.path, "/search")',
                'action' => ['cache' => false],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\FixtureSpecialTtl',
                'method' => 'getProbe',
                'route' => '/__fixture_special_ttl_api',
                'expression' => 'starts_with(http.request.uri.path, "/__fixture_special_ttl_api")',
                'action' => ['cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
        ], 5);

        $forbid = null;
        $ttl = null;
        foreach ($packs as $pack) {
            $id = (string)($pack['_weline_identity'] ?? '');
            if (\str_starts_with($id, 'pack:forbid:')) {
                $forbid = $pack;
            }
            if (\str_starts_with($id, 'pack:ttl:')) {
                $ttl = $pack;
            }
        }
        self::assertNotNull($forbid);
        self::assertSame(false, $forbid['action']['cache']);
        self::assertStringContainsString('/search', (string)$forbid['expression']);
        self::assertNotNull($ttl);
        self::assertSame(900, (int)$ttl['action']['cache']['ttl']);
        self::assertStringContainsString('/__fixture_special_ttl_api', (string)$ttl['expression']);
    }

    public function testCacheableSearchAndCheckoutRejectedFromSpecialTtlPack(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Search',
                'class' => 'Weline\\Search\\Controller\\Frontend\\Index',
                'method' => 'index',
                'route' => '/search',
                'expression' => 'starts_with(http.request.uri.path, "/search")',
                'action' => ['cache' => ['ttl' => 31536000, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Checkout',
                'class' => 'Weline\\Checkout\\Controller\\Frontend\\Index',
                'method' => 'index',
                'route' => '/checkout',
                'expression' => 'starts_with(http.request.uri.path, "/checkout")',
                'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Payment',
                'class' => 'Weline\\Payment\\Controller\\Frontend\\Success',
                'method' => 'index',
                'route' => '/payment/success',
                'expression' => 'starts_with(http.request.uri.path, "/payment/success")',
                'action' => ['cache' => ['ttl' => 600, 'status_code' => [200]]],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Catalog',
                'class' => 'Weline\\Catalog\\Controller\\Frontend\\Product',
                'method' => 'view',
                'route' => '/product',
                'expression' => 'starts_with(http.request.uri.path, "/product")',
                'action' => ['cache' => ['ttl' => 900, 'status_code' => [200]]],
            ],
        ], 5);

        self::assertNotEmpty($packs);
        foreach ($packs as $pack) {
            $expr = \strtolower((string)($pack['expression'] ?? ''));
            $id = (string)($pack['_weline_identity'] ?? '');
            if (\str_starts_with($id, 'pack:ttl:')) {
                self::assertStringNotContainsString('/search', $expr);
                self::assertStringNotContainsString('/checkout', $expr);
                self::assertStringNotContainsString('/payment', $expr);
            }
        }
        $ttlExpr = '';
        foreach ($packs as $pack) {
            if (\str_starts_with((string)($pack['_weline_identity'] ?? ''), 'pack:ttl:')) {
                $ttlExpr = (string)$pack['expression'];
            }
        }
        self::assertStringContainsString('/product', $ttlExpr);
    }

    public function testGuidePaymentNotRejectedAsNeverCacheEdge(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_Cms',
                'class' => 'Weline\\Cms\\Controller\\Frontend\\Guide',
                'method' => 'payment',
                'route' => '/guide/payment',
                'expression' => 'starts_with(http.request.uri.path, "/guide/payment")',
                'action' => ['cache' => ['ttl' => 86400, 'status_code' => [200]]],
            ],
        ], 5);

        self::assertCount(1, $packs);
        self::assertStringContainsString('/guide/payment', (string)$packs[0]['expression']);
    }

    /** A7：Packer 源码须直接读 ApiRule class/route，禁止仅依赖 toCloudflareRule 猜面。 */
    public function testPackerSourceReadsApiRuleClassRouteNotOnlyCloudflareShape(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/AnnotationRulePacker.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('ApiRule::schema_fields_CLASS', $src);
        self::assertStringContainsString('ApiRule::schema_fields_ROUTE', $src);
        self::assertStringContainsString('instanceof ApiRule', $src);
        self::assertStringContainsString('禁止只吃 toCloudflareRule', $src);
        // 允许文档提及；禁止调用形态 ->toCloudflareRule(
        self::assertDoesNotMatchRegularExpression('/->\s*toCloudflareRule\s*\(/', $src);

        $rmPath = \dirname(__DIR__, 3) . '/Service/RuleManager.php';
        $rmSrc = (string)\file_get_contents($rmPath);
        self::assertStringContainsString('getEnabledApiRuleModels', $rmSrc);
        self::assertStringContainsString('禁止仅 toCloudflareRule', $rmSrc);
    }
}
