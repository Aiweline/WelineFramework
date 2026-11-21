<?php

declare(strict_types=1);

namespace Amayum\Website\Test\Unit;

use Amayum\Website\Controller\Router;
use Amayum\Website\Helper\AmayumView;
use Amayum\Website\Service\SiteProfileService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Context;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;

/**
 * Amayum 站点门禁回归：2026-11 changanhanfu.com /contact 500 的根因是
 * 固定页路由无站点门禁 + 模块模板硬依赖主题私有文件。此测试把收口后的
 * 三条合同钉死：唯一门禁判据、Router 只服务 amayum、AmayumView 随模块走。
 */
final class SiteGateTest extends TestCase
{
    protected function setUp(): void
    {
        Context::leave();
        Context::enter(new Context());
        WebsiteData::resetRequestState();
    }

    protected function tearDown(): void
    {
        WebsiteData::resetRequestState();
        Context::leave();
        parent::tearDown();
    }

    public function testGateIsPublicStaticOnSiteProfileService(): void
    {
        $method = new ReflectionMethod(SiteProfileService::class, 'isAmayumSite');
        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
    }

    public function testGateTrueOnlyForAmayum(): void
    {
        self::assertFalse(SiteProfileService::isAmayumSite());

        foreach (['default', 'changanhanfu', 'daocharms', ''] as $code) {
            self::assertFalse(
                $this->withWebsite($code, static fn(): bool => SiteProfileService::isAmayumSite()),
                "code={$code} 不应通过门禁"
            );
        }

        self::assertTrue($this->withWebsite('amayum', static fn(): bool => SiteProfileService::isAmayumSite()));
        self::assertTrue($this->withWebsite('AMAYUM', static fn(): bool => SiteProfileService::isAmayumSite()));
    }

    public function testRouterClaimsFixedPagesOnlyOnAmayum(): void
    {
        $routes = [
            'contact' => 'amayum/frontend/contact',
            'diagnosis' => 'amayum/frontend/contact',
            'commerce' => 'amayum/frontend/commerce',
            'case-studies' => 'amayum/frontend/case-studies',
            'journal' => 'amayum/frontend/journal',
            'legal' => 'amayum/frontend/legal',
            'sitemap' => 'amayum/frontend/sitemap-page',
        ];

        $this->withWebsite('amayum', static function () use ($routes): void {
            foreach ($routes as $in => $expected) {
                $path = $in;
                $rule = [];
                Router::process($path, $rule);
                self::assertSame($expected, $path, "amayum 站 {$in} 未被认领");
                self::assertSame('Amayum_Website', $rule['module'] ?? null);
            }

            $path = 'journal/hello-world';
            $rule = [];
            Router::process($path, $rule);
            self::assertSame('amayum/frontend/journal-view', $path);
        });
    }

    public function testRouterClaimsNothingOffAmayum(): void
    {
        $paths = ['contact', 'diagnosis', 'commerce', 'case-studies', 'journal', 'legal', 'sitemap', 'journal/a-b'];

        foreach (['default', 'changanhanfu'] as $code) {
            $this->withWebsite($code, static function () use ($paths, $code): void {
                foreach ($paths as $in) {
                    $path = $in;
                    $rule = [];
                    Router::process($path, $rule);
                    self::assertSame($in, $path, "{$code} 站 {$in} 被 Amayum 劫持");
                    self::assertNull($rule['module'] ?? null);
                }
            });
        }

        // 无站点上下文同样不认领
        foreach ($paths as $in) {
            $path = $in;
            $rule = [];
            Router::process($path, $rule);
            self::assertSame($in, $path);
            self::assertNull($rule['module'] ?? null);
        }
    }

    public function testRouterDoesNotOverwriteClaimedModule(): void
    {
        $this->withWebsite('amayum', static function (): void {
            $path = 'contact';
            $rule = ['module' => 'Weline_Contact'];
            Router::process($path, $rule);
            self::assertSame('contact', $path);
            self::assertSame('Weline_Contact', $rule['module']);
        });
    }

    public function testAmayumViewShipsWithModuleAndLegacyAliasWorks(): void
    {
        self::assertTrue(class_exists(AmayumView::class), '模块内 AmayumView 必须可自动加载');
        self::assertSame('&lt;a&gt;', AmayumView::e('<a>'));

        // 用 __DIR__ 推仓库根：Pest/phpunit 引导不保证定义 BP 常量。
        $legacy = \dirname(__DIR__, 6) . '/app/design/Amayum/official/frontend/includes/AmayumView.php';
        if (!\is_file($legacy)) {
            self::markTestSkipped('本机无 Amayum 主题（垫片随主题分发），跳过旧路径兼容验证');
        }
        require_once $legacy;
        self::assertTrue(\class_exists(\AmayumDesign\Official\AmayumView::class));
        self::assertSame(
            AmayumView::class,
            (new \ReflectionClass(\AmayumDesign\Official\AmayumView::class))->getName()
        );
    }

    /** @template T @param callable():T $fn @return T */
    private function withWebsite(string $code, callable $fn): mixed
    {
        WebsiteData::resetRequestState();
        try {
            WebsiteData::setWebsite(new SiteGateWebsiteStub([
                Website::schema_fields_ID => 1,
                Website::schema_fields_CODE => $code,
            ]));
            return $fn();
        } finally {
            WebsiteData::resetRequestState();
        }
    }
}

final class SiteGateWebsiteStub extends Website
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
    }

    public function setData($key, $value = null, bool $is_unique = false): static
    {
        if (\is_array($key)) {
            $this->values = $key;
        } else {
            $this->values[(string)$key] = $value;
        }

        return $this;
    }

    public function getData(string $key = '', $index = null): mixed
    {
        return $key === '' ? $this->values : ($this->values[$key] ?? null);
    }

    public function hasData(string $key = ''): bool
    {
        return $key === '' ? $this->values !== [] : \array_key_exists($key, $this->values);
    }
}
