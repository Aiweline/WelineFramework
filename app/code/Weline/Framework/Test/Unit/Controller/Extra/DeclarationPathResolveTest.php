<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Controller\Extra;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Controller\Extra\DeclarationPath;
use Weline\I18n\Controller\Frontend\InlineEdit\Api as InlineEditApi;
use Weline\I18n\Controller\Frontend\Switcher\Catalog as SwitcherCatalog;
use Weline\Product\Controller\Frontend\Catalog as ProductCatalog;

/**
 * 声明路径必须等于「真实可达路由」：路由表是机器契约，声明路径是策略挂载点。
 * 两者漂移时 `@Extra enabled=false` 会静默失效（历史故障：`/i18n/frontend/switcher/catalog/get`
 * 与真实 `/i18n/frontend/switcher/catalog` 不匹配）。
 */
final class DeclarationPathResolveTest extends TestCase
{
    public function testCanonicalMatchesBrowserReachablePath(): void
    {
        // 纯 HTTP 动词方法：生成器把方法名当成动词 → 公开路径不含该段。
        self::assertSame(
            '/i18n/frontend/switcher/catalog',
            DeclarationPath::canonical('i18n', SwitcherCatalog::class, 'get'),
        );
        // index 动作：末尾 index 段被剥掉。
        self::assertSame(
            '/weline_product/frontend/catalog',
            DeclarationPath::canonical('weline_product', ProductCatalog::class, 'index'),
        );
        // `Api` 是命名空间段而非模块标记，必须保留在路径里。
        self::assertSame(
            '/i18n/frontend/inline-edit/api/phrase',
            DeclarationPath::canonical('i18n', InlineEditApi::class, 'postPhrase'),
        );
    }

    public function testAliasesCoverGeneratorExtraKeys(): void
    {
        $aliases = DeclarationPath::aliases('i18n', SwitcherCatalog::class, 'get');
        self::assertContains('/i18n/frontend/switcher/catalog/get', $aliases);

        $aliases = DeclarationPath::aliases('i18n', InlineEditApi::class, 'postPhrase');
        self::assertContains('/i18n/frontend/inline-edit/api/post-phrase', $aliases);
        self::assertContains('/i18n/frontend/inline-edit/api/postphrase', $aliases);
        self::assertContains('/i18n/frontend/inlineedit/api/phrase', $aliases);
        self::assertNotContains('/i18n/frontend/inline-edit/api/phrase', $aliases, 'canonical 不得混进 aliases');
    }

    public function testActionKeepsHttpVerbOutOfPath(): void
    {
        self::assertSame(['verb' => 'GET', 'action' => ''], DeclarationPath::action('get'));
        self::assertSame(['verb' => 'POST', 'action' => 'phrase'], DeclarationPath::action('postPhrase'));
        self::assertSame(['verb' => 'GET', 'action' => 'locales'], DeclarationPath::action('getLocales'));
        self::assertSame(['verb' => '', 'action' => 'index'], DeclarationPath::action('index'));
    }

    /**
     * R9 本体证据：canonical 必须真的存在于生成的路由表里。
     * 路由生成器改规则时这条先红，而不是等策略静默失效后被线上发现。
     */
    public function testCanonicalExistsInGeneratedRouteTable(): void
    {
        $table = require BP . 'generated/routers/frontend_pc.php';
        self::assertIsArray($table);

        $keys = [];
        foreach (array_keys($table) as $route) {
            $keys['/' . trim(strtok((string) $route, ':'), '/')] = true;
        }

        foreach ([
            [DeclarationPath::canonical('i18n', SwitcherCatalog::class, 'get')],
            [DeclarationPath::canonical('weline_product', ProductCatalog::class, 'index')],
            [DeclarationPath::canonical('i18n', InlineEditApi::class, 'postPhrase')],
        ] as [$canonical]) {
            self::assertArrayHasKey($canonical, $keys, 'canonical 未出现在 generated/routers/frontend_pc.php: ' . $canonical);
        }
    }
}
