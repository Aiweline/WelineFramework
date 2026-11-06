<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Spec;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Spec\ThemeCriticalSkinConflict;
use Weline\Theme\Service\Spec\ThemeSpecValidator;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * S13：主题 layout-critical 与皮肤叶的几何值必须一致。
 *
 * 默认主题的契约是「首帧几何由内联 critical 钉住，皮肤到齐后不得改动它」。
 * 两侧写成不同值 → 先画一帧再被覆盖 → 保证位移。hanfu 的 `.ca-brand`（168 vs 218）
 * 与 `.ca-nav`（gap 28px vs clamp(...)）就是这类缺陷。
 */
final class ThemeCriticalSkinConflictTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/weline-s13-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/frontend/partials/head', 0777, true);
        mkdir($this->root . '/frontend/assets/css', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testDetectsGeometryConflictBetweenCriticalAndSkin(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write('frontend/assets/css/layout-critical.css', ".ca-brand { width: 168px; flex: 0 0 168px; }\n");
        $this->write('frontend/assets/css/skin.css', ".ca-brand { width: 218px; flex: 0 0 218px; }\n");

        $violations = (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend');

        self::assertCount(2, $violations, 'width 与 flex 两条都应报');
        self::assertSame([ThemeCriticalSkinConflict::RULE, ThemeCriticalSkinConflict::RULE], array_column($violations, 'rule'));
        self::assertStringContainsString('168px', $violations[0]['message']);
        self::assertStringContainsString('218px', $violations[0]['message']);
    }

    public function testEqualValuesDoNotConflict(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write('frontend/assets/css/layout-critical.css', ".ca-brand { width: 218px; }\n");
        $this->write('frontend/assets/css/skin.css', ".ca-brand { width: 218px; }\n");

        self::assertSame([], (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend'));
    }

    public function testNonGeometryPropertiesAreIgnored(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write('frontend/assets/css/layout-critical.css', "body { color: #0f1111; background: #fff; }\n");
        $this->write('frontend/assets/css/skin.css', "body { color: var(--color-text); background: var(--ca-paper); }\n");

        self::assertSame(
            [],
            (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend'),
            '颜色/背景不改布局，不应进 S13'
        );
    }

    public function testVarFallbackOnlyDifferenceIsNotADrift(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write('frontend/assets/css/layout-critical.css', ".ca-copy { width: min(100%, var(--ca-content, 1360px)); }\n");
        $this->write('frontend/assets/css/skin.css', ".ca-copy { width: min(100%, var(--ca-content)); }\n");

        self::assertSame(
            [],
            (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend'),
            'critical 的 var 回退是首帧设计意图，不算与皮肤漂移'
        );
    }

    public function testRatioAndDecimalWithinToleranceAreEqual(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write('frontend/assets/css/layout-critical.css', ".ca-cat img { aspect-ratio: 420 / 270; }\n");
        $this->write('frontend/assets/css/skin.css', ".ca-cat img { aspect-ratio: 1.55; }\n");

        self::assertSame([], (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend'));
    }

    public function testMediaScopedConflictIsDetected(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write(
            'frontend/assets/css/layout-critical.css',
            "@media (max-width: 900px) {\n    .ca-header { height: 68px; }\n}\n"
        );
        $this->write(
            'frontend/assets/css/skin.css',
            "@media (max-width: 900px) {\n    .ca-header { height: 64px; }\n}\n"
        );

        $violations = (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend');

        self::assertCount(1, $violations);
        self::assertStringContainsString('@media (max-width: 900px)', $violations[0]['message']);
    }

    public function testWaiverSuppressesConflict(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write(
            'frontend/assets/css/layout-critical.css',
            "/* @critical-skin-waivers: .ca-brand:width */\n.ca-brand { width: 168px; }\n"
        );
        $this->write('frontend/assets/css/skin.css', ".ca-brand { width: 218px; }\n");

        self::assertSame([], (new ThemeCriticalSkinConflict())->collect($this->root . '/frontend'));
    }

    public function testValidatorWiresS13IntoDirectoryAudit(): void
    {
        $this->writeManifest(['theme/frontend/assets/css/skin.css']);
        $this->write(
            'frontend/assets/css/layout-critical.css',
            \Weline\Theme\Service\Theme\ThemeCssLayerContract::DECLARATION . "\n.ca-brand { width: 168px; }\n"
        );
        $this->write('frontend/assets/css/skin.css', ".ca-brand { width: 218px; }\n");

        $violations = (new ThemeSpecValidator())->assertDirectory($this->root . '/frontend');

        self::assertSame(['S13'], array_column($violations, 'rule'), 'assertDirectory 必须把 S13 带出来');
        self::assertSame(['hard'], array_column($violations, 'severity'));
    }

    public function testRealThemesHaveNoCriticalSkinDrift(): void
    {
        $collector = new ThemeCriticalSkinConflict();
        $roots = [BP . 'app/code/Weline/Theme/view/theme/frontend'];
        foreach (glob(BP . 'app/design/*/*/frontend', GLOB_ONLYDIR) ?: [] as $root) {
            $roots[] = $root;
        }
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $violations = $collector->collect($root);
            self::assertSame(
                [],
                array_column($violations, 'message'),
                'S13 在 ' . str_replace(BP, '', $root) . ' 报出首帧/皮肤几何矛盾'
            );
        }
    }

    /**
     * @param list<string> $leaves
     */
    private function writeManifest(array $leaves): void
    {
        $this->write(
            'frontend/partials/head/theme-head-css-overlay.php',
            "<?php\ndeclare(strict_types=1);\n\nreturn ['common' => " . var_export($leaves, true) . ", 'layouts' => []];\n"
        );
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function remove(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->remove($path . '/' . $entry);
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
