<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class DuplicatePanelThemeContractTest extends TestCase
{
    public function testDuplicateIndexUsesSeoFormSectionsAndEmptyState(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Duplicate/index.phtml';
        $css = $root . '/view/statics/css/seo-admin.css';
        $proto = $root . '/view/statics/prototype/duplicate-panel-ui.html';

        self::assertFileExists($template);
        self::assertFileExists($css);
        self::assertFileExists($proto);

        $src = (string) file_get_contents($template);
        $cssSrc = (string) file_get_contents($css);
        $protoSrc = (string) file_get_contents($proto);

        self::assertStringContainsString('data-testid="seo-duplicate-panel"', $src);
        self::assertStringContainsString('data-seo-dup-ui-variant="A"', $src);
        self::assertStringContainsString('seo-form-section', $src);
        self::assertStringContainsString('seo-form-grid', $src);
        self::assertStringContainsString('seo-form-actions', $src);
        self::assertStringContainsString('seo-switch-card', $src);
        self::assertStringContainsString('class="w-field"', $src);
        self::assertStringContainsString('class="w-input"', $src);
        self::assertStringContainsString('class="w-select"', $src);
        self::assertStringContainsString('seo-empty-state', $src);
        self::assertStringContainsString('data-testid="seo-dup-runs-empty"', $src);
        self::assertStringNotContainsString('class="w-form-row"', $src);
        self::assertStringNotContainsString('<style>', $src);

        self::assertStringContainsString('.seo-dup-meta', $cssSrc);
        self::assertStringContainsString('--weline-theme-primary', $cssSrc);
        self::assertStringContainsString('?variant=A|B|C', $protoSrc);
        self::assertStringContainsString('verdict_default: \'A\'', $protoSrc);
    }

    public function testDuplicateReportUsesMetaAndGradeChips(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Duplicate/report.phtml';
        $src = (string) file_get_contents($template);

        self::assertStringContainsString('data-testid="seo-duplicate-report"', $src);
        self::assertStringContainsString('seo-form-section', $src);
        self::assertStringContainsString('seo-dup-meta', $src);
        self::assertStringContainsString('seo-dup-grade-filter', $src);
        self::assertStringContainsString('report_path_all', $src);
        self::assertStringContainsString('panel_path', $src);
        self::assertStringNotContainsString('href="/seo/backend/duplicate"', $src);
        self::assertStringNotContainsString('href="/seo/backend/duplicate/report?run_id=', $src);
        self::assertStringNotContainsString('<style>', $src);
    }
}
