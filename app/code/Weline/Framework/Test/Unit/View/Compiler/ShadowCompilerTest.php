<?php

declare(strict_types=1);

/**
 * 影子编译骨架的契约测试。
 *
 * 判据：整条 Lexer → Parser → Lower → Emitter 跑完后，产物与输入**逐字节相同**。
 * 这是 P0 的退出判据；同时给 `taglib:bench` 提供一个可复现的取样口径。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Shadow\ShadowCompiler;

final class ShadowCompilerTest extends TestCase
{
    public function testPipelineReplaysExactBytes(): void
    {
        $source = "<div class=\"card\">\n<?php echo \$title; ?>\n<?= \$price ?>\n</div>\n";
        $result = (new ShadowCompiler())->compile($source);

        self::assertTrue($result->ok());
        self::assertSame($source, $result->output);
        // 相邻两段 PHP 之间没有文本（闭标签自带换行），所以会合并成一个 Php 片段：
        // Text + 合并后的 Php + Text = 3。这条断言同时钉住「合并不会丢字节」。
        self::assertSame(3, $result->fragments);
        self::assertSame(3, $result->nodes);
        self::assertSame(3, $result->statements);
        self::assertGreaterThan(0.0, $result->durationUs);
    }

    public function testTrickyPhpStaysIntact(): void
    {
        $source = "<?php \$x = \"?>\"; ?><b>a</b><?php /* <?php ?> */ echo 1; ?>";
        $result = (new ShadowCompiler())->compile($source);

        self::assertTrue($result->ok(), 'PHP 片段必须原样保留');
        self::assertSame($source, $result->output);
    }

    /**
     * 全量语料护栏：任何模板都要能逐字节重放。
     */
    public function testCorpusReplaysExactBytes(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP 未定义，无法定位语料根');
        }

        $compiler = new ShadowCompiler();
        $files = 0;
        $bad = [];
        $bytes = 0;
        $durationUs = 0.0;

        foreach ([BP . 'app/code', BP . 'app/design'] as $root) {
            if (!\is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $file): bool {
                        if ($file->isDir()) {
                            return !\in_array($file->getFilename(), ['vendor', 'node_modules', 'generated'], true);
                        }

                        return \str_ends_with($file->getFilename(), '.phtml');
                    }
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                if (\str_contains($path, '/view/tpl/')) {
                    continue;
                }
                $source = @\file_get_contents($path);
                if (!\is_string($source)) {
                    continue;
                }
                $files++;
                $bytes += \strlen($source);
                $result = $compiler->compile($source);
                $durationUs += $result->durationUs;
                if (!$result->ok() && \count($bad) < 10) {
                    $bad[] = $path;
                }
            }
        }

        self::assertGreaterThan(1000, $files, '语料样本数异常偏小');
        self::assertSame([], $bad, '以下模板影子编译后字节不一致');
        // 只做量级护栏（防止未来某次改动把复杂度从 O(n) 变成 O(n²)）；
        // 绝对性能判据在 taglib:bench / A4，不放在单测里。
        self::assertLessThan(5.0, $durationUs / 1_000_000, '全量影子编译耗时异常（应为秒级）');
        self::assertGreaterThan(0, $bytes);
    }
}
