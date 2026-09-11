<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class AiTranslationProgressContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testProgressServiceExposesLanguageByTypeBoard(): void
    {
        $service = $this->read('app/code/Weline/I18n/Service/AiTranslationProgressService.php');
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
        $template = $this->read('app/code/Weline/I18n/view/templates/Backend/AiTranslation/index.phtml');
        $css = $this->read('app/code/Weline/I18n/view/statics/css/backend-admin.css');

        self::assertStringContainsString('class AiTranslationProgressService', $service);
        self::assertStringContainsString('TYPE_DICTIONARY', $service);
        self::assertStringContainsString('TYPE_LOCAL_MODEL', $service);
        self::assertStringContainsString('TYPE_MODULE_EXPORT', $service);
        self::assertStringContainsString('function buildBoard', $service);
        self::assertStringContainsString('humanizeQueueStatus', $service);

        self::assertStringContainsString('AiTranslationProgressService', $controller);
        self::assertStringContainsString('progress_board', $controller);
        self::assertStringContainsString('buildBoard', $controller);

        self::assertStringContainsString('data-ai-progress-board', $template);
        self::assertStringContainsString('翻译进度', $template);
        self::assertStringContainsString('待写回 CSV', $template);
        self::assertStringContainsString('模块 CSV', $template);
        self::assertStringContainsString('CSV 已对齐', $template);
        self::assertStringContainsString('模块 CSV', $service);
        self::assertStringContainsString('i18n-admin-progress-bar', $template);
        self::assertStringContainsString('语言配置与操作', $template);
        self::assertStringContainsString('进度见上方总览', $template);
        self::assertStringNotContainsString('white-space:pre-wrap', $template);
        // Ops table must not re-render progress columns (dedupe vs progress board).
        self::assertDoesNotMatchRegularExpression(
            '/语言配置与操作[\\s\\S]*?<th>\\s*词典进度\\s*<\\/th>/u',
            $template,
        );
        self::assertDoesNotMatchRegularExpression(
            '/语言配置与操作[\\s\\S]*?<th>\\s*待补翻译\\s*<\\/th>/u',
            $template,
        );
        self::assertStringNotContainsString('i18n-admin-stat-tiles', $template);

        self::assertStringContainsString('.i18n-admin-progress-board', $css);
        self::assertStringContainsString('.i18n-admin-progress-bar', $css);
        self::assertStringContainsString('var(--color-primary', $css);
    }

    private function read(string $relative): string
    {
        $root = \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR;
        $path = $root . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
