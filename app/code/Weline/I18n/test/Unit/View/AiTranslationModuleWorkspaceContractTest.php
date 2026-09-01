<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AiTranslationModuleWorkspaceContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testModuleTabTranslateWritebackSurface(): void
    {
        $template = $this->read('app/code/Weline/I18n/view/templates/Backend/AiTranslation/index.phtml');
        $js = $this->read('app/code/Weline/I18n/view/statics/js/backend-admin.js');
        $controller = $this->read('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
        $provider = $this->read('app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
        $service = $this->read('app/code/Weline/I18n/Service/AiTranslationModuleWorkspaceService.php');
        $catalog = $this->read('app/code/Weline/I18n/Service/DictionaryModuleCatalogService.php');

        self::assertStringContainsString('listModulesLite', $controller);
        self::assertStringContainsString('postModuleTranslateAndWriteback', $controller);
        self::assertStringContainsString('getModuleLocaleMatrix', $controller);
        self::assertStringContainsString('AiTranslationModuleWorkspaceService', $controller);
        self::assertMatchesRegularExpression(
            '/function getModuleLocaleMatrix\(\)[\s\S]*?getLocaleName[\s\S]*?function /',
            $controller,
        );
        if (\preg_match('/function getModuleLocaleMatrix\(\)[\s\S]*?\n    public function /', $controller, $matrixMethod) === 1) {
            self::assertStringNotContainsString('$this->buildLocaleRows', $matrixMethod[0]);
        } else {
            self::fail('getModuleLocaleMatrix method body not found');
        }

        self::assertStringContainsString('ai-module-matrix', $provider);
        self::assertStringContainsString('ai-module-translate-writeback', $provider);
        self::assertStringContainsString("'ai-module-matrix' => 'Weline_I18n::i18n_ai_translation'", $provider);

        self::assertStringContainsString('tab=modules', $template);
        self::assertStringContainsString('一键翻译并写回', $template);
        self::assertStringContainsString('data-ai-module-writeback-form', $template);
        self::assertStringContainsString('data-ai-module-expand', $template);
        self::assertStringContainsString('系统活跃模块', $template);

        self::assertStringContainsString('bindAiModuleWorkspace', $js);
        self::assertStringContainsString('DOMContentLoaded', $js);
        self::assertStringContainsString('ai-module-matrix', $js);
        self::assertStringContainsString('locale_codes[]', $js);

        self::assertStringContainsString('translateAndWriteback', $service);
        self::assertStringContainsString('summarizeModuleWorkOverview', $catalog);
        self::assertStringContainsString('summarizeModuleWorkOverview', $service);
        self::assertStringContainsString('locales_pending', $service);
        self::assertStringContainsString('words_pending', $service);
        self::assertStringContainsString('data-ai-module-status-detail', $template);
        self::assertStringContainsString('待处理 %{1}/%{2} 种语言 · %{3} 词', $template);
        self::assertStringContainsString('updateModuleRowStatusFromMatrix', $js);
        self::assertStringContainsString('listModulesLite', $service);
        self::assertStringContainsString('getModuleLocaleMatrix', $service);
        self::assertStringContainsString('getActiveModules', $service);
        self::assertStringContainsString('syncModuleCollectedWords', $catalog);
        self::assertStringContainsString('TranslationCollector', $catalog);
        self::assertStringContainsString('syncModuleCollectedWords', $service);
        self::assertStringContainsString('data-ai-module-matrix-url', $template);
        self::assertStringContainsString('summarizeModuleLocaleAlignment', $catalog);
        self::assertStringContainsString('countModuleSourceWords', $catalog);
        self::assertStringContainsString('getModuleLocaleMatrixBundle', $service);
        self::assertStringContainsString('summarizeModuleLocaleAlignment', $service);
        self::assertStringContainsString('streamModuleLocaleMatrix', $service);
        self::assertStringContainsString('SseWriter', $service);
        self::assertStringContainsString('countAiPendingExportByLocales', $catalog);
        self::assertStringContainsString('countAiPendingExportByLocales', $service);
        self::assertStringContainsString('streamModuleLocaleMatrix', $controller);
        self::assertStringContainsString('text/event-stream', $controller);
        self::assertStringContainsString('EventSource', $js);
        self::assertStringContainsString("searchParams.set('sse', '1')", $js);
        self::assertStringContainsString('__matrixXhr', $js);
        self::assertStringContainsString('parseSseBuffer', $js);
        self::assertStringContainsString('updateSourceBanner', $js);
        self::assertStringContainsString('data-ai-module-source-banner', $template);
        self::assertStringContainsString('data-col-words', $template);
        self::assertStringContainsString('data-col-translated', $template);
        self::assertStringContainsString('data-col-gap', $template);
        self::assertStringContainsString("setRequestHeader('Accept', 'text/event-stream')", $js);
        self::assertStringContainsString('closeMatrixStream', $js);
        self::assertStringContainsString('resolveModuleMatrixUrl', $js);
        self::assertStringContainsString('module-locale-matrix', $js);
        self::assertStringContainsString('data-has-work', $js);
        self::assertStringContainsString('data-ai-module-select-work', $js);
        self::assertStringContainsString('data-ai-module-matrix-hint', $js);
        self::assertStringNotContainsString('data-ai-module-locale-stat', $template);
        self::assertStringContainsString('loadTranslatedWordSet', $catalog);
        $collector = $this->read('app/code/Weline/I18n/Service/TranslationCollector.php');
        self::assertStringContainsString('SchedulerSystem::yield()', $collector);
        self::assertStringContainsString('includeDb', $catalog);
        self::assertStringContainsString('Env::system(\'deploy\')', $catalog);
        self::assertStringContainsString("key === 'csrf'", $js);
        self::assertStringContainsString('requestHttpFormFallback', $js);
        self::assertStringContainsString('isAuthTransportError', $js);
        self::assertStringContainsString('data-ai-module-writeback-form', $js);
        self::assertStringContainsString('preferHttp', $js);
        self::assertStringContainsString('streamTranslateAndWriteback', $service);
        self::assertStringContainsString('runTranslateAndWriteback', $service);
        self::assertStringContainsString('streamTranslateAndWriteback', $controller);
        self::assertStringContainsString('getModuleTranslateAndWriteback', $controller);
        self::assertStringContainsString('startModuleWritebackSse', $js);
        self::assertStringContainsString('parseSseBuffer', $js);
        self::assertStringContainsString("setRequestHeader('Accept', 'text/event-stream')", $js);
        self::assertStringContainsString('locale_done', $js);
        self::assertStringContainsString('data-ai-module-writeback-sse', $template);
        self::assertStringContainsString('backendAdminJsVersion', $template);
        self::assertStringContainsString('/Weline/I18n/view/statics/js/backend-admin.js?v=', $template);
        self::assertStringContainsString('confirmWriteback', $js);
        self::assertStringContainsString('dismissStaleDialogs', $js);
        self::assertStringContainsString('onDocClick', $js);
        self::assertStringContainsString('I18N_ADMIN_UI_VERSION', $js);
        self::assertStringContainsString('currentHintEl', $js);
        self::assertStringContainsString('__writebackBusy', $js);
        self::assertStringContainsString('keepWriteback', $js);
        self::assertStringContainsString('createWritebackProgressUi', $js);
        self::assertStringContainsString('scheduleMatrixReconnect', $js);
        self::assertStringContainsString('scheduleWritebackReconnect', $js);
        self::assertStringContainsString('SSE_RECONNECT_MAX', $js);
        self::assertStringContainsString('连接中断，', $js);
        self::assertStringContainsString('openWritebackStream', $js);
        self::assertStringContainsString('data-ai-writeback-progress', $js);
        self::assertStringContainsString('data-ai-writeback-chip', $js);
        self::assertStringContainsString('data-ai-writeback-chip', $template);
        self::assertStringContainsString("'node' => 'collect'", $service);
        self::assertStringContainsString("'node' => 'verify'", $service);
        self::assertStringContainsString("id: 'verify'", $js);
        self::assertStringContainsString('校验对齐', $js);
        self::assertStringContainsString('未对齐', $js);
        self::assertStringContainsString('exportModuleCsvGaps', $service);
        $export = $this->read('app/code/Weline/I18n/Service/AiTranslationExportService.php');
        self::assertStringContainsString('exportModuleCsvGaps', $export);
        self::assertStringContainsString('translateModule($moduleName, $localeCode, 0, false)', $service);
        self::assertStringContainsString('bool $syncFirst = true', $catalog);
        self::assertStringContainsString("'source_locale' => \$sourceLocale", $catalog);
        self::assertStringContainsString('Target locale CSVs are filled later', $catalog);
        self::assertStringContainsString('stageButtonLabel', $js);
        self::assertStringNotContainsString('data-async-action="ai-module-translate-writeback"', $template);
    }

    private function read(string $relativePath): string
    {
        $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
