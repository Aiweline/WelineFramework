<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AiTranslationExportDialogContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testQueueErrorAndExportDialogSurface(): void
    {
        $template = $this->read('app/code/Weline/I18n/view/templates/Backend/AiTranslation/index.phtml');
        $js = $this->read('app/code/Weline/I18n/view/statics/js/backend-admin.js');
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
        $export = $this->read('app/code/Weline/I18n/Service/AiTranslationExportService.php');

        self::assertStringContainsString('queue_result_summary', $controller);
        self::assertStringContainsString('queue_is_error', $controller);
        self::assertStringContainsString('i18n-admin-queue-result', $template);
        self::assertStringContainsString('ai-export-modules-modal', $template);
        self::assertStringContainsString('data-ai-export-open', $template);
        self::assertStringContainsString('name="module_name"', $template);
        self::assertStringContainsString('bindAiExportDialog', $js);
        self::assertStringContainsString('listAiSourceModules', $export);
        self::assertStringContainsString("getPost('module_name'", $controller);
        self::assertStringContainsString('exportModuleTranslations', $export);
        self::assertStringContainsString('schema_fields_EXPORTED_AT', $export);
        self::assertStringContainsString('增量写回', $export);
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
