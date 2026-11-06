<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Spec;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Theme\ThemeCssLayerContract;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * 层序单一出处契约（架构级）：全仓 CSS 的 `@layer` 序声明只允许一份规范序列。
 *
 * 为什么必须有这层测试：CSS 的「第一处序声明决定全部层序」是硬语义，
 * 后写的声明无法重排先写的序列。缺少 `weline-theme` 的旧声明会把该层追加到末尾
 * （= 最高优先级），默认主题骨架反过来压过主题皮肤，与 theme.css 契约相反。
 * 这类漂移在页面上表现成「某些主题某些组件样式忽然不生效」，靠肉眼极难定位。
 */
final class ThemeCssLayerContractTest extends TestCase
{
    public function testCanonicalDeclarationIsAccepted(): void
    {
        self::assertTrue(ThemeCssLayerContract::isDeclared(ThemeCssLayerContract::DECLARATION));
        self::assertFalse(ThemeCssLayerContract::violatesOrder(ThemeCssLayerContract::DECLARATION));
    }

    public function testWhitespaceAndCaseDoNotMatter(): void
    {
        $loose = "@layer  weline-theme ,reset,  tokens,base,layout,components,utilities,page ;";

        self::assertTrue(ThemeCssLayerContract::isDeclared($loose));
        self::assertFalse(ThemeCssLayerContract::violatesOrder($loose));
    }

    public function testLegacyOrderWithoutWelineThemeIsAViolation(): void
    {
        // 这正是 2026-11-06 收口前 foundation/frontend/backend 三处互不一致的旧口径：
        // 缺 weline-theme → 该层被追加到序列末尾 → 皮肤层反而最高优先级。
        $legacy = '@layer reset, tokens, base, layout, components, utilities, page;';

        self::assertTrue(ThemeCssLayerContract::hasOrderStatement($legacy));
        self::assertFalse(ThemeCssLayerContract::isDeclared($legacy));
        self::assertTrue(ThemeCssLayerContract::violatesOrder($legacy));
    }

    public function testReorderedDeclarationIsAViolation(): void
    {
        // 名字齐全但次序错（page 提前到最低）同样会改变级联结果，必须判违规。
        $reordered = '@layer page, weline-theme, reset, tokens, base, layout, components, utilities;';

        self::assertTrue(ThemeCssLayerContract::violatesOrder($reordered));
    }

    public function testBlockStatementIsNotAnOrderStatement(): void
    {
        // 块语句 `@layer components { ... }` 只往既有层里塞规则，不钉层序，不应被要求带序声明。
        $block = "@layer components {\n  .btn { color: red; }\n}\n";

        self::assertFalse(ThemeCssLayerContract::hasOrderStatement($block));
        self::assertFalse(ThemeCssLayerContract::violatesOrder($block));
    }

    public function testOrderStatementMentionedOnlyInCommentIsNotADeclaration(): void
    {
        // 回归：theme.css 在块注释里写了示例声明、正文只用 `@layer weline-theme {`。
        // 注释文本不得被当成真声明（否则要么误报违规，要么误判已声明）。
        $commentOnly = "/*\n * 层序示例：@layer reset, tokens, base, layout, components, utilities, page;\n */\n"
            . "@layer weline-theme {\n  .a { color: red; }\n}\n";

        self::assertFalse(ThemeCssLayerContract::hasOrderStatement($commentOnly));
        self::assertFalse(ThemeCssLayerContract::violatesOrder($commentOnly));
    }

    public function testRealStatementAfterACommentIsStillDetected(): void
    {
        $css = "/*\n * 说明\n */\n@layer reset, tokens, base;\n.a { color: red; }\n";

        self::assertTrue(ThemeCssLayerContract::violatesOrder($css));
        $offset = ThemeCssLayerContract::firstOrderStatementOffset($css);
        self::assertNotNull($offset);
        // 行号必须指向真正的声明行（第 4 行），不能被注释里的换行带偏。
        self::assertSame(4, 1 + substr_count(substr($css, 0, (int)$offset), "\n"));
        self::assertSame('@layer reset, tokens, base;', ThemeCssLayerContract::firstOrderStatement($css));
    }

    public function testEveryShippedOrderStatementInRepoIsCanonical(): void
    {
        // 全仓扫描：只要写了序声明，就必须是规范层序（防止新增文件再引入第二种口径）。
        $roots = [BP . 'app/code', BP . 'app/design'];
        $offenders = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'css') {
                    continue;
                }
                $css = (string)file_get_contents($file->getPathname());
                if (ThemeCssLayerContract::violatesOrder($css)) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders, '这些 CSS 的 @layer 序声明不是规范序列：' . implode(', ', $offenders));
    }
}
