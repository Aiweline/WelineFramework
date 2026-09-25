<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\View\PublicThemeNamespace;

/**
 * UT：公开设计主题命名空间归一化。
 *
 * 契约：`resolve()` 的返回值**永远**是可安全拼进 `pub/static/{...}` 的相对命名空间。
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

    public function testModuleIdentifierFallsBackToBuiltinDefault(): void
    {
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve('Weline_Theme::view/theme'));
        self::assertSame($this->defaultNamespace, PublicThemeNamespace::resolve('Weline_Frontend::view/theme'));
    }

    public function testAbsoluteSourcePathFallsBackToBuiltinDefault(): void
    {
        // 这正是制造 pub/static/Users/... 畸形树的输入。
        self::assertSame(
            $this->defaultNamespace,
            PublicThemeNamespace::resolve('/Users/example/project/app/code/Weline/Theme/view/theme')
        );
        self::assertSame(
            $this->defaultNamespace,
            PublicThemeNamespace::resolve('C:/project/app/code/Weline/Theme/view/theme')
        );
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
