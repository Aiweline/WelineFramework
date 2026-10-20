<?php

declare(strict_types=1);

/**
 * v3 Lexer 契约测试。
 *
 * 主判据只有一条：**切分 + 重放 == 原文**（字节级）。
 * 附加判据：片段连续无缝、Span 行号正确、PHP 片段的 echo/openTag 分类正确。
 *
 * 注意：这里只验「切得对、放得回」，不验标签语义——标签是 Parser/Resolve 的事。
 *
 * @author Aiweline
 */

namespace Weline\Framework\Test\Unit\View\Compiler;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Lexer\Fragment;
use Weline\Framework\View\Compiler\Lexer\Lexer;
use Weline\Framework\View\Compiler\Lexer\PhpFragment;
use Weline\Framework\View\Compiler\Lexer\TextFragment;

final class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function trickySources(): array
    {
        return [
            '纯文本' => ["<div class=\"x\">hello</div>\n"],
            '标准 php 块' => ["<p><?php echo \$a; ?></p>"],
            '短回显' => ["<p><?= \$a ?></p>"],
            'php 字符串里含闭标签' => ["<?php echo \"?>\"; ?><span>ok</span>"],
            'php 字符串里含开标签' => ["<?php echo '<?php '; ?>tail"],
            '行注释里含闭标签' => ["<?php // ?> tail-after-close\n<p>x</p>"],
            '块注释里含开闭标签' => ["<?php /* <?php ?> */ echo 1; ?>"],
            'heredoc 里含闭标签' => ["<?php \$x = <<<TXT\n?> 不是闭标签\nTXT;\necho \$x; ?>"],
            'nowdoc' => ["<?php \$x = <<<'TXT'\n<?= \$y ?>\nTXT;\n?>"],
            'xml 序言' => ["<?xml version=\"1.0\"?>\n<div></div>"],
            '多个 php 块' => ["a<?php echo 1; ?>b<?= \$c ?>d"],
            '未闭合 php 到文件尾' => ["<div>a</div>\n<?php echo 1;"],
            'CRLF' => ["<div>\r\n<?php echo 1; ?>\r\n</div>\r\n"],
            '中文与多字节' => ["<p>汉服 · 霓裳</p><?php echo '中文'; ?>"],
            'script 里的模板标记' => ["<script>var t = '<w:block>' + '@lang(x)';</script>"],
            '空字符串' => [''],
        ];
    }

    /**
     * @dataProvider trickySources
     */
    public function testReplayIsByteExact(string $source): void
    {
        $fragments = $this->lexer->tokenize($source);

        self::assertSame($source, $this->lexer->replay($fragments));
        self::assertTrue($this->lexer->isRoundTrip($source));
    }

    /**
     * @dataProvider trickySources
     */
    public function testFragmentsAreContiguous(string $source): void
    {
        $fragments = $this->lexer->tokenize($source);

        $cursor = 0;
        $line = 1;
        $lineStart = 0;
        foreach ($fragments as $fragment) {
            $span = $fragment->span();
            self::assertSame($cursor, $span->start, '片段必须首尾相接，不丢字节');
            self::assertSame(\strlen($fragment->raw()), $span->length);
            self::assertSame($line, $span->line, '行号必须与原文一致');
            self::assertSame($cursor - $lineStart + 1, $span->column);

            $raw = $fragment->raw();
            $lastNewline = \strrpos($raw, "\n");
            if ($lastNewline !== false) {
                $line += \substr_count($raw, "\n");
                $lineStart = $cursor + $lastNewline + 1;
            }
            $cursor = $span->end();
        }

        self::assertSame(\strlen($source), $cursor);
    }

    public function testKindsAndEchoClassification(): void
    {
        $fragments = $this->lexer->tokenize("<p><?php echo 1; ?></p><?= \$x ?>");
        self::assertCount(4, $fragments);
        self::assertInstanceOf(TextFragment::class, $fragments[0]);
        self::assertInstanceOf(PhpFragment::class, $fragments[1]);
        self::assertInstanceOf(TextFragment::class, $fragments[2]);
        self::assertInstanceOf(PhpFragment::class, $fragments[3]);

        /** @var PhpFragment $standard */
        $standard = $fragments[1];
        self::assertFalse($standard->isEcho());
        self::assertSame('<?php', $standard->openTag());

        /** @var PhpFragment $echo */
        $echo = $fragments[3];
        self::assertTrue($echo->isEcho());
        self::assertSame('<?=', $echo->openTag());
    }

    public function testEmptySourceHasNoFragments(): void
    {
        self::assertSame([], $this->lexer->tokenize(''));
    }

    /**
     * 全量语料：app/code + app/design 下所有 .phtml。
     *
     * 这是 P0 的退出判据（计划书 §9 P0 行）：样本不足 1000 个直接失败，
     * 避免「只跑了三个文件就宣称通过」。
     */
    public function testCorpusRoundTrip(): void
    {
        if (!\defined('BP')) {
            self::markTestSkipped('BP 未定义，无法定位语料根');
        }

        $roots = [BP . 'app/code', BP . 'app/design'];
        $files = 0;
        $mismatch = [];

        foreach ($roots as $root) {
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
                // view/tpl 是编译产物目录，不属于模板语料。
                if (\str_contains($path, '/view/tpl/')) {
                    continue;
                }
                $source = @\file_get_contents($path);
                if (!\is_string($source)) {
                    continue;
                }
                $files++;
                if (!$this->lexer->isRoundTrip($source) && \count($mismatch) < 10) {
                    $mismatch[] = $path;
                }
            }
        }

        self::assertGreaterThan(1000, $files, '语料样本数异常偏小，检查扫描根');
        self::assertSame([], $mismatch, '以下模板切分后无法逐字节重放');
    }
}
