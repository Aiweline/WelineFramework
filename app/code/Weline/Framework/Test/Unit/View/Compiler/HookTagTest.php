<?php

declare(strict_types=1);

/**
 * `hook` 的迁移边界：
 * - 无实现（注册表里没有 / 没有实现文件）→ 空串；带 `<else/>` → fallback 内容；
 * - 有实现（例如 checkout layout 的 baked hook）→ PROD 复用 `HookCompiledPhpInliner`
 *   烘焙 include；DEV（PHPUnit 固定 DEV=true）退回运行期 `getHook()` / `getHookResult`。
 *
 * 注意：这里不能断言「有实现一定 bake」，因为测试进程 DEV=true；baked 路径由
 * WLS/PROD 页面验收与 `tpl-v3-hook-probe.php` 覆盖。
 *
 * 这里特别钉住 `CompiledExtensionRegistry` 的命名空间：真实类是
 * `Weline\Framework\Compilation\CompiledExtensionRegistry`，写错时异常被吞掉，
 * 有实现的 hook 会被误判成「无实现」并静默输出空串。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Ir\Emitter;
use Weline\Framework\View\Compiler\Ir\Lower;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Parser\Parser;
use Weline\Framework\View\Compiler\Tag\Builtin\HookTag;
use Weline\Framework\View\Compiler\Tag\Registry;
use Weline\Framework\View\Compiler\Tag\StaticTagNameMatcher;
use Weline\Framework\View\Template;

final class HookTagTest extends TestCase
{
    private const IMPLEMENTED_HOOK = 'Weline_Theme::frontend::layouts::base::head-before';

    public function testNoImplementationMatchesLegacyEmptyString(): void
    {
        $source = '<div><hook>weline_no_such_hook_for_test</hook></div>';
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'hook-no-impl.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    public function testImplementedHookUsesBakeOrRuntimeFallback(): void
    {
        $output = $this->compileV3('<div><hook>' . self::IMPLEMENTED_HOOK . '</hook></div>');

        if (\defined('DEV') && \DEV) {
            // DEV（PHPUnit 固定 DEV=true）：旧链同样不烘焙，退回运行期 getHook。
            self::assertStringContainsString('$this->getHook(\'' . self::IMPLEMENTED_HOOK . '\')', $output);
            return;
        }

        self::assertStringContainsString('/* baked-hook:' . self::IMPLEMENTED_HOOK . ' @', $output);
        self::assertStringContainsString('include ', $output);
        self::assertStringContainsString('com_head-before.phtml', $output);
    }

    public function testSelfClosingMissingHookKeepsRuntimeGetHook(): void
    {
        $source = '<div><hook name="weline_no_such_hook_for_test"/></div>';
        $template = Template::getInstance();
        $legacySource = $source;
        $legacy = (string)$template->getTaglib()->compile($template, $legacySource, 'hook-self-close.phtml');

        self::assertSame($legacy, $this->compileV3($source));
    }

    public function testNoImplementationWithElseReturnsFallback(): void
    {
        $output = $this->compileV3('<div><hook>weline_no_such_hook_for_test<else/>FALLBACK</hook></div>');

        self::assertSame('<div>FALLBACK</div>', $output);
    }

    public function testImplementedHookWithElseUsesBakeOrRuntimeFallback(): void
    {
        $output = $this->compileV3(
            '<div><hook>' . self::IMPLEMENTED_HOOK . '<else/>FALLBACK</hook></div>',
        );

        if (\defined('DEV') && \DEV) {
            self::assertStringContainsString('getHookResult', $output);
            self::assertStringContainsString('FALLBACK', $output);
            return;
        }

        self::assertStringContainsString('baked-hook:' . self::IMPLEMENTED_HOOK, $output);
        self::assertStringNotContainsString('FALLBACK', $output);
    }

    private function compileV3(string $source): string
    {
        $registry = new Registry([HookTag::descriptor()]);
        $parser = new Parser(new Lexer(), new StaticTagNameMatcher(['hook', 'else']));
        $statements = (new Lower($registry, null, null, Template::getInstance()))
            ->lower($parser->parse($source));

        return (new Emitter())->emit($statements);
    }
}
