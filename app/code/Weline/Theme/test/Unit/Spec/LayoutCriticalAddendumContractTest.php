<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Spec;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Spec\ThemeSpecValidator;
use Weline\Theme\Service\Theme\LayoutCriticalCssMaterializer;
use Weline\Theme\Service\Theme\ThemeCssLayerContract;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * layout-critical 的「baseline + addendum」组合语义：
 *  - addendum 免 S11（层序由基线钉死），critical 不免；
 *  - S4 按 head 实际内联的「生效集合」判（基线或 override + addendum），不是逐文件判；
 *  - 带 GENERATED 标记的托管快照不等于 override，不重复计入。
 *
 * 断言值一律由基线实际字节推导，基线升级后测试仍然成立。
 */
final class LayoutCriticalAddendumContractTest extends TestCase
{
    private string $tmpRoot;

    private int $baselineBytes;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/weline-critical-addendum-' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);

        $baseline = BP . 'app/code/Weline/Theme/view/ui/css/layout-critical-frontend.css';
        $this->baselineBytes = (int)filesize($baseline);
        self::assertGreaterThan(0, $this->baselineBytes, 'frontend baseline must exist to compute composed budgets');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpRoot);
    }

    public function testAddendumSkipsLayerDeclarationButCriticalDoesNot(): void
    {
        $addendum = $this->write('frontend/assets/css/layout-critical-addendum.css', ".pic-hero{min-block-size:14rem}\n");
        $critical = $this->write('backend/assets/css/layout-critical.css', ".be-shell{padding:1rem}\n");

        $validator = new ThemeSpecValidator();

        self::assertSame([], $validator->validateFile($addendum), 'addendum must not be forced to re-declare the layer order');
        self::assertSame(['S11'], array_column($validator->validateFile($critical), 'rule'));
    }

    public function testComposedBudgetCountsBaselinePlusAddendum(): void
    {
        // 每个文件都在单文件预算内，但「基线 + 增量」的 head 内联量超限 ⇒ 必须报 S4。
        $addendumBytes = ThemeSpecValidator::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES - $this->baselineBytes + 100;
        self::assertLessThan(ThemeSpecValidator::MAX_LAYOUT_CRITICAL_BYTES, $addendumBytes, 'fixture must stay under the per-file budget');

        $this->write('frontend/assets/css/layout-critical-addendum.css', $this->addendumOfSize($addendumBytes));

        $violations = (new ThemeSpecValidator())->assertDirectory($this->tmpRoot . '/frontend');

        $composed = $this->violationsWithRule($violations, 'S4');
        self::assertCount(1, $composed, 'per-file rules must not fire; only the composed budget may');
        self::assertStringContainsString(
            'is ' . ($this->baselineBytes + $addendumBytes) . ' bytes',
            $composed[0]['message'],
            'the total must be the baseline plus the addendum'
        );
        self::assertStringContainsString('baseline', $composed[0]['message']);
        self::assertSame(
            ThemeSpecValidator::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES,
            $this->budgetFromMessage($composed[0]['message'])
        );
    }

    public function testComposedBudgetPassesWhenAddendumFitsNextToBaseline(): void
    {
        $addendumBytes = ThemeSpecValidator::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES - $this->baselineBytes - 100;

        $this->write('frontend/assets/css/layout-critical-addendum.css', $this->addendumOfSize($addendumBytes));

        self::assertSame([], (new ThemeSpecValidator())->assertDirectory($this->tmpRoot . '/frontend'));
    }

    public function testGeneratedSnapshotIsNotCountedAsOverride(): void
    {
        // 快照比基线多 300B：若被当成 override 计入，基线(6979)+增量的等价组合就会假超限。
        $snapshotBytes = $this->baselineBytes + 300;
        $addendumBytes = ThemeSpecValidator::MAX_COMPOSED_LAYOUT_CRITICAL_BYTES - $this->baselineBytes - 100;

        $this->write('frontend/assets/css/layout-critical.css', $this->snapshotOfSize($snapshotBytes));
        $this->write('frontend/assets/css/layout-critical-addendum.css', $this->addendumOfSize($addendumBytes));

        self::assertSame([], (new ThemeSpecValidator())->assertDirectory($this->tmpRoot . '/frontend'));

        // 反证：同一份快照去掉 GENERATED 标记即「主题接管」，改按 override 计入 ⇒ 组合超限。
        $handOwned = $this->snapshotOfSize($snapshotBytes);
        $this->write('frontend/assets/css/layout-critical.css', str_replace(
            LayoutCriticalCssMaterializer::GENERATED_MARKER,
            'theme-owned',
            $handOwned
        ));

        $violations = $this->violationsWithRule((new ThemeSpecValidator())->assertDirectory($this->tmpRoot . '/frontend'), 'S4');
        self::assertCount(1, $violations);
        self::assertStringContainsString('layout-critical.css + layout-critical-addendum.css', $violations[0]['message']);
    }

    public function testOversizedAddendumAloneIsS4(): void
    {
        $this->write(
            'frontend/assets/css/layout-critical-addendum.css',
            $this->addendumOfSize(ThemeSpecValidator::MAX_LAYOUT_CRITICAL_BYTES + 200)
        );

        self::assertContains('S4', array_column(
            (new ThemeSpecValidator())->validateFile($this->tmpRoot . '/frontend/assets/css/layout-critical-addendum.css'),
            'rule'
        ));
    }

    /**
     * @param list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}> $violations
     * @return list<array{rule:string,file:string,line:int,severity:string,message:string,hint:string}>
     */
    private function violationsWithRule(array $violations, string $rule): array
    {
        return array_values(array_filter(
            $violations,
            static fn (array $violation): bool => $violation['rule'] === $rule
        ));
    }

    private function budgetFromMessage(string $message): int
    {
        self::assertSame(1, preg_match('/over the (\d+) byte inline budget/', $message, $matches));

        return (int)$matches[1];
    }

    private function addendumOfSize(int $bytes): string
    {
        return $this->cssOfSize(".pic-hero{min-block-size:14rem}\n", $bytes);
    }

    private function snapshotOfSize(int $bytes): string
    {
        // 真快照是基线物化出来的，正文含基线首行的层序声明（否则门禁会先报 S11，掩盖组合口径）。
        $header = '/* ' . LayoutCriticalCssMaterializer::GENERATED_MARKER
            . " area=frontend baselineSha=" . str_repeat('a', 64) . " */\n"
            . ThemeCssLayerContract::DECLARATION . "\n";

        return $this->cssOfSize($header, $bytes);
    }

    /** 生成恰好 $bytes 字节的 CSS（填充放在块注释内，不引入新规则）。 */
    private function cssOfSize(string $head, int $bytes): string
    {
        $tail = "\n/* */\n";
        $pad = $bytes - strlen($head) - strlen($tail);
        self::assertGreaterThan(0, $pad, 'target size too small for the fixture header');

        return $head . '/*' . str_repeat('x', $pad - 4) . '*/' . $tail;
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->tmpRoot . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
        self::assertSame(strlen($contents), (int)filesize($path), 'fixture size must match exactly');

        return $path;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }
}
