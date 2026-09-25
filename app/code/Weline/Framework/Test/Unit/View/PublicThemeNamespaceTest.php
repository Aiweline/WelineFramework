<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\View\PublicThemeNamespace;

/**
 * UT：公开设计主题命名空间归一化（唯一权威）。
 *
 * 契约：
 * - `resolve()` 的返回值**永远**是可安全拼进 `pub/static/{...}` 的相对命名空间；
 * - `tryResolve()` 对无法对应命名空间的输入返回 `null`（供需要区分「无法解析」的调用点）；
 * - 两者对「可解析」的输入必须给出**同一个**结果（本类与 ThemeStaticNamespaceService 共用它）。
 *
 * 反例来自真实事故：`theme.path` 曾是绝对源码路径，未归一化即被当作目录段，
 * `pub/static` 下长出 `Users/<name>/.../app/code/...` 与 `Weline_Theme::view/` 畸形树。
 */
final class PublicThemeNamespaceTest extends TestCase
{
    private string $defaultNamespace;

    private string $designRoot;

    protected function setUp(): void
    {
        $this->defaultNamespace = trim(str_replace('\\', '/', (string)Env::default_theme_DATA['path']), '/');
        $this->designRoot = rtrim(str_replace('\\', '/', (string)Env::path_THEME_DESIGN_DIR), '/');
    }

    public function testRelativeCustomThemePathIsPreserved(): void
    {
        self::assertSame('Weline/hanfu', PublicThemeNamespace::resolve('Weline/hanfu'));
        self::assertSame('WeShop/motor', PublicThemeNamespace::resolve('WeShop/motor'));
        self::assertSame('Weline/hanfu', PublicThemeNamespace::resolve('Weline/hanfu/'));

        // 前导斜杠按绝对路径处理 → 回落默认命名空间（与既有 TraitTemplate 语义一致）。
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve('/Weline/hanfu/'));
    }

    /**
     * 模块标识 `Vendor_Module::path` **展开**为 `Vendor/Module/path`，而非回落默认命名空间。
     *
     * 理由：该写法命名的是某个**具体主题**；若一律回落默认，两个不同主题会写进同一个
     * `pub/static/{默认}/...` 而互相覆盖。对内置默认主题（`Weline_Theme::view/theme`）
     * 展开结果恰好等于默认命名空间，故历史行为不变。
     */
    public function testModuleIdentifierExpandsToVendorModulePath(): void
    {
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve('Weline_Theme::view/theme'));
        self::assertSame('Weline/Frontend/view/theme', PublicThemeNamespace::resolve('Weline_Frontend::view/theme'));
        self::assertSame('WeShop/Motor/view/theme', PublicThemeNamespace::resolve('WeShop_Motor::view/theme'));
        self::assertSame('Weline/Hanfu/view/theme', PublicThemeNamespace::resolve('Weline_Hanfu::view\\theme'));
    }

    /**
     * 项目内 `app/code/{Vendor}/{Module}/view/theme` → 保留主题身份（而非回落默认）。
     */
    public function testProjectInternalCodePathExpandsToVendorModuleTheme(): void
    {
        if (!defined('APP_CODE_PATH')) {
            self::markTestSkipped('APP_CODE_PATH 未定义，跳过该用例。');
        }

        $codeRoot = rtrim(str_replace('\\', '/', (string)APP_CODE_PATH), '/');

        self::assertSame(
            'Weline/Theme/view/theme',
            PublicThemeNamespace::resolve($codeRoot . '/Weline/Theme/view/theme')
        );
        self::assertSame(
            'Codex/Foo/view/theme',
            PublicThemeNamespace::resolve($codeRoot . '/Codex/Foo/view/theme')
        );
    }

    /**
     * 项目内相对写法 `app/code/...` / `app/design/...` 也归一化，不再原样铺成 `pub/static/app/code/...`。
     */
    public function testProjectRelativeNotationIsNormalized(): void
    {
        self::assertSame(
            'Weline/Theme/view/theme',
            PublicThemeNamespace::resolve('app/code/Weline/Theme/view/theme')
        );
        self::assertSame('Weline/hanfu', PublicThemeNamespace::resolve('app/design/Weline/hanfu'));
    }

    public function testAbsoluteSourcePathFallsBackToBuiltinDefault(): void
    {
        // 这正是制造 pub/static/Users/... 畸形树的输入（不在本项目 app/code 下，无法对应命名空间）。
        self::assertSame(
            $this->defaultNamespace,
            PublicThemeNamespace::resolve('/Users/example/project/app/code/Weline/Theme/view/theme')
        );
        self::assertSame(
            $this->defaultNamespace,
            PublicThemeNamespace::resolve('C:/project/app/code/Weline/Theme/view/theme')
        );
    }

    /**
     * `tryResolve()` 与 `resolve()` 的分工：前者区分「无法解析」，后者永不返回空。
     */
    public function testTryResolveDistinguishesUnresolvableFromDefault(): void
    {
        // 可解析：两者一致。
        self::assertSame('Weline/hanfu', PublicThemeNamespace::tryResolve('Weline/hanfu'));
        self::assertSame('Weline/Frontend/view/theme', PublicThemeNamespace::tryResolve('Weline_Frontend::view/theme'));

        // 无法解析：tryResolve 给 null，resolve 给默认命名空间。
        foreach (['', '   ', null, '..', '.', 'a//b', 'a::b', '/Users/foo/bar', 'C:/x/y'] as $unresolvable) {
            self::assertNull(
                PublicThemeNamespace::tryResolve($unresolvable),
                'tryResolve 应对无法解析的输入返回 null：' . var_export($unresolvable, true)
            );
            self::assertSame(
                $this->defaultNamespace,
                PublicThemeNamespace::resolve($unresolvable),
                'resolve 对同一输入应回落默认命名空间：' . var_export($unresolvable, true)
            );
        }
    }

    public function testAbsoluteDesignPathBecomesRelativeVendorTheme(): void
    {
        if ($this->designRoot === '') {
            self::markTestSkipped('未配置主题设计目录，跳过该用例。');
        }

        self::assertSame(
            'Weline/hanfu',
            PublicThemeNamespace::resolve($this->designRoot . '/Weline/hanfu')
        );
    }

    public function testEmptyInputFallsBackToBuiltinDefault(): void
    {
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve(''));
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve(null));
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve('   '));
    }

    /**
     * 核心契约：任何输入都不得产出可越出 pub/static 的命名空间。
     *
     * @dataProvider hostilePathProvider
     */
    public function testResultIsAlwaysASafeRelativeNamespace(string $hostilePath): void
    {
        $resolved = PublicThemeNamespace::resolve($hostilePath);

        self::assertTrue(
            PublicThemeNamespace::isSafeRelativeNamespace($resolved),
            '归一化结果必须可安全拼进 pub/static：' . $resolved
        );
        self::assertStringNotContainsString('..', $resolved);
        self::assertStringNotContainsString('::', $resolved);
        self::assertStringNotContainsString('\\', $resolved);
        self::assertFalse(str_starts_with($resolved, '/'));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function hostilePathProvider(): array
    {
        return [
            'traversal' => ['Weline/hanfu/../evil'],
            'deep traversal' => ['../../etc'],
            'absolute traversal' => ['/Users/example/../../etc'],
            'module identifier' => ['Weline_Theme::view/theme'],
            'absolute source' => ['/Users/example/project/app/code/Weline/Theme/view/theme'],
            'windows drive' => ['C:/project/app/code/Weline/Theme/view/theme'],
            'double slash' => ['Weline//hanfu'],
            'backslash' => ['Weline\\hanfu'],
            'dot only' => ['.'],
            'dotdot only' => ['..'],
            'bare module separator' => ['a::b'],
            'relative code path' => ['app/code/../../etc'],
            'empty' => [''],
        ];
    }

    public function testSafeRelativeNamespaceRejectsUnsafeValues(): void
    {
        self::assertTrue(PublicThemeNamespace::isSafeRelativeNamespace('Weline/hanfu'));
        self::assertTrue(PublicThemeNamespace::isSafeRelativeNamespace('Weline/Theme/view/theme'));

        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace(''));
        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace('/abs/path'));
        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace('C:/abs'));
        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace('a/../b'));
        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace('a//b'));
        self::assertFalse(PublicThemeNamespace::isSafeRelativeNamespace('Weline_Theme::view'));
    }
}
