<?php
declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\MessageManager;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationExportService;
use Weline\I18n\Service\AiTranslationModuleWorkspaceService;
use Weline\I18n\Service\AiTranslationProgressService;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\AiTranslationService;
use Weline\I18n\Service\LocalModelTranslation\LocalModelTranslationQueueService;

class AiTranslation extends BaseController
{
    /** Keep page HTML far below WLS FiberOutputBuffer capture_limit (16MB). */
    private const QUEUE_RESULT_DISPLAY_MAX_BYTES = 4096;

    public function __construct(
        Locale $locale,
        I18n $i18n,
        private readonly AiTranslationConfig $config,
        private readonly AiTranslationQueueService $queueService,
        private readonly LocalModelTranslationQueueService $localModelQueueService,
        private readonly AiTranslationService $translationService,
        private readonly AiTranslationExportService $exportService,
        private readonly AiTranslationModuleWorkspaceService $moduleWorkspace,
        private readonly AiTranslationProgressService $progressService,
        private readonly WordDictionary $dictionary,
        private readonly LocaleDictionary $localeDictionary
    ) {
        parent::__construct($locale, $i18n);
    }

    public function __init()
    {
        parent::__init();
        $this->assign('title', __('AI翻译'));
        $this->assign('description', __('配置 I18n AI 自动翻译语言和队列任务。'));
    }

    public function index()
    {
        $tab = trim((string)$this->request->getGet('tab', 'locales'));
        if (!in_array($tab, ['locales', 'modules'], true)) {
            $tab = 'locales';
        }
        $moduleSearch = trim((string)$this->request->getGet('module_q', ''));

        $config = $this->config->getConfig();
        $localeRows = $this->buildLocaleRows($config);
        $totalWords = (int)$this->dictionary->clear()->reset()->count();
        $progressBoard = $this->progressService->buildBoard($localeRows, $totalWords);
        $this->assign('config', $config);
        $this->assign('active_tab', $tab);
        $this->assign('module_search', $moduleSearch);
        $this->assign('locales', $localeRows);
        $this->assign('progress_board', $progressBoard);
        $this->assign(
            'modules',
            $tab === 'modules' ? $this->moduleWorkspace->listModulesLite($moduleSearch) : [],
        );
        $this->assign('stats', [
            'total_words' => $totalWords,
            'enabled_locales' => count($this->config->getEnabledLocaleCodes()),
            'ai_translated' => (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->count(),
            'dictionary_pct' => (int)($progressBoard['summary']['dictionary_pct'] ?? 0),
            'module_pending_total' => (int)($progressBoard['summary']['module_pending_total'] ?? 0),
            'local_model_label' => (string)($progressBoard['summary']['local_model_label'] ?? ''),
        ]);

        return $this->fetch();
    }

    public function getModuleLocaleMatrix()
    {
        $moduleName = trim((string)$this->request->getGet(
            'module_name',
            (string)$this->request->getPost('module_name', ''),
        ));
        $names = $this->resolveLocaleDisplayNames();
        $wantsSse = $this->wantsModuleMatrixSse();

        if ($moduleName === '') {
            if ($wantsSse) {
                $this->layoutType = null;
                $sse = new SseWriter();
                $sse->start();
                $sse->sendError((string)__('缺少模块名'), 400);
                $sse->complete(['success' => false]);

                return;
            }

            return $this->asyncJsonResponse(false, (string)__('缺少模块名'));
        }

        if ($wantsSse) {
            $this->layoutType = null;
            $this->moduleWorkspace->streamModuleLocaleMatrix(
                new SseWriter(),
                $moduleName,
                $names,
            );

            return;
        }

        $bundle = $this->moduleWorkspace->getModuleLocaleMatrixBundle($moduleName);
        $locales = $bundle['locales'];
        foreach ($locales as &$localeRow) {
            $code = (string)($localeRow['code'] ?? '');
            $localeRow['name'] = $names[$code] ?? $code;
        }
        unset($localeRow);

        $source = $bundle['source'] ?? ['locale' => '', 'word_count' => 0];
        $sourceLocale = (string)($source['locale'] ?? '');
        if ($sourceLocale !== '') {
            $source['name'] = $names[$sourceLocale] ?? $sourceLocale;
        }

        return $this->asyncJsonResponse(true, '', [
            'module' => $moduleName,
            'source' => $source,
            'locales' => $locales,
        ]);
    }

    /**
     * EventSource sends Accept: text/event-stream; also allow ?sse=1.
     */
    private function wantsModuleMatrixSse(): bool
    {
        if ((string)$this->request->getGet('sse', '') === '1') {
            return true;
        }
        $acceptRaw = $this->request->getHeader('Accept');
        if (is_array($acceptRaw)) {
            $accept = strtolower(implode(',', array_map('strval', $acceptRaw)));
        } else {
            $accept = strtolower((string)($acceptRaw ?? ''));
        }
        if ($accept === '') {
            $accept = strtolower((string)($this->request->getServer('HTTP_ACCEPT') ?? ''));
        }

        return str_contains($accept, 'text/event-stream');
    }

    /**
     * @return array<string, string>
     */
    private function resolveLocaleDisplayNames(): array
    {
        $displayLocale = \Weline\Framework\Http\Cookie::getLangLocal();
        $names = [];
        foreach ($this->config->getInstalledActiveLocaleCodes() as $localeCode) {
            $localeCode = trim((string)$localeCode);
            if ($localeCode === '') {
                continue;
            }
            $displayName = $localeCode;
            try {
                $displayName = (string)$this->i18n->getLocaleName($localeCode, $displayLocale);
            } catch (\Throwable) {
            }
            $names[$localeCode] = $displayName !== '' ? $displayName : $localeCode;
        }

        return $names;
    }

    /**
     * EventSource (GET + sse=1) streams translate/writeback progress.
     */
    public function getModuleTranslateAndWriteback()
    {
        return $this->dispatchModuleTranslateAndWriteback(true);
    }

    public function postModuleTranslateAndWriteback()
    {
        return $this->dispatchModuleTranslateAndWriteback(false);
    }

    private function dispatchModuleTranslateAndWriteback(bool $fromGet)
    {
        $isAsyncRequest = $this->isAsyncRequest();
        $wantsSse = $this->wantsModuleMatrixSse();

        if ($fromGet) {
            $moduleName = trim((string)$this->request->getGet(
                'module_name',
                (string)$this->request->getPost('module_name', ''),
            ));
            $localeCodes = $this->resolveLocaleCodesFromRequest(true);
        } else {
            if (!$this->request->isPost()) {
                if ($wantsSse) {
                    $this->layoutType = null;
                    $sse = new SseWriter();
                    $sse->start();
                    $sse->sendError((string)__('请求方式错误。'), 405);
                    $sse->complete(['success' => false]);

                    return;
                }
                if ($isAsyncRequest) {
                    return $this->asyncJsonResponse(false, (string)__('请求方式错误。'));
                }
                MessageManager::error(__('请求方式错误。'));

                return $this->redirect('*/backend/ai-translation', ['tab' => 'modules']);
            }
            $moduleName = trim((string)$this->request->getPost('module_name', ''));
            $localeCodes = $this->resolveLocaleCodesFromRequest(false);
        }

        if ($wantsSse) {
            $this->layoutType = null;
            $this->moduleWorkspace->streamTranslateAndWriteback(
                new SseWriter(),
                $moduleName,
                $localeCodes,
                $this->resolveLocaleDisplayNames(),
            );

            return;
        }

        if ($fromGet) {
            return $this->asyncJsonResponse(false, (string)__('请使用 SSE（Accept: text/event-stream 或 ?sse=1）'));
        }

        try {
            $result = $this->moduleWorkspace->translateAndWriteback($moduleName, $localeCodes);
            $message = (string)($result['message'] ?? __('操作完成'));
            $ok = !empty($result['success']);
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse($ok, $message, $result);
            }
            if ($ok) {
                MessageManager::success($message);
            } else {
                MessageManager::warning($message);
            }
            foreach ((array)($result['errors'] ?? []) as $error) {
                MessageManager::warning((string)$error);
            }
        } catch (\Throwable $throwable) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('模块一键翻译并写回失败：%{1}', [$throwable->getMessage()]));
            }
            MessageManager::error(__('模块一键翻译并写回失败：%{1}', [$throwable->getMessage()]));
        }

        $redirectParams = ['tab' => 'modules'];
        if ($moduleName !== '') {
            $redirectParams['module_q'] = $moduleName;
        }

        return $this->redirect('*/backend/ai-translation', $redirectParams);
    }

    /**
     * @return list<string>
     */
    private function resolveLocaleCodesFromRequest(bool $fromGet): array
    {
        $localeCodes = $fromGet
            ? $this->request->getGet('locale_codes', $this->request->getGet('locale_codes[]', []))
            : $this->request->getPost('locale_codes', []);

        if (is_string($localeCodes)) {
            $localeCodes = str_contains($localeCodes, ',')
                ? explode(',', $localeCodes)
                : [$localeCodes];
        }
        if (!is_array($localeCodes)) {
            $localeCodes = $localeCodes === '' || $localeCodes === null
                ? []
                : [trim((string)$localeCodes)];
        }

        return array_values(array_filter(array_map(
            static fn ($code): string => trim((string)$code),
            $localeCodes,
        )));
    }

    public function postSave()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        if (!$this->request->isPost()) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请求方式错误。'));
            }
            MessageManager::error(__('请求方式错误。'));
            return $this->redirect('*/backend/ai-translation');
        }

        try {
            $config = $this->config->saveFromPost((array)$this->request->getPost());
            $queueIds = [];
            $localModelQueueId = 0;
            if (!empty($config['enabled'])) {
                $queueIds = $this->queueService->enqueueEnabledLocales('config_save');
                $localModelQueueId = $this->localModelQueueService->enqueue('config_save');
            }

            $message = (string)__('AI翻译配置已保存，词典已入队 %{1} 个语言，LocalModel 队列：%{2}。', [
                (string)count($queueIds),
                $localModelQueueId > 0 ? '#' . $localModelQueueId : (string)__('无需入队'),
            ]);
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, $message, [
                    'queue_count' => count($queueIds),
                    'local_model_queue_id' => $localModelQueueId,
                ]);
            }
            MessageManager::success($message);
        } catch (\Throwable $throwable) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('保存 AI翻译配置失败：%{1}', [$throwable->getMessage()]));
            }
            MessageManager::error(__('保存 AI翻译配置失败：%{1}', [$throwable->getMessage()]));
        }

        return $this->redirect('*/backend/ai-translation');
    }

    public function postEnqueue()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        if (!$this->request->isPost()) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请求方式错误。'));
            }
            MessageManager::error(__('请求方式错误。'));
            return $this->redirect('*/backend/ai-translation');
        }

        try {
            $localeCode = trim((string)$this->request->getPost('locale_code', ''));
            if ($localeCode === '') {
                $queueIds = $this->queueService->enqueueEnabledLocales('manual');
                $localModelQueueId = $this->localModelQueueService->enqueue('manual');
                $message = (string)__('已为 %{1} 个启用语言创建 AI 翻译队列，LocalModel 队列：%{2}。', [
                    (string)count($queueIds),
                    $localModelQueueId > 0 ? '#' . $localModelQueueId : (string)__('无需入队'),
                ]);
                if ($isAsyncRequest) {
                    return $this->asyncJsonResponse(true, $message, [
                        'queue_count' => count($queueIds),
                        'local_model_queue_id' => $localModelQueueId,
                    ]);
                }
                MessageManager::success($message);
            } else {
                $queueId = $this->queueService->enqueueLocale($localeCode, [], 'manual', true);
                if ($queueId > 0) {
                    $message = (string)__('AI 翻译队列已创建：#%{1}', [(string)$queueId]);
                    if ($isAsyncRequest) {
                        return $this->asyncJsonResponse(true, $message, ['queue_id' => $queueId, 'locale_code' => $localeCode]);
                    }
                    MessageManager::success($message);
                } else {
                    if ($isAsyncRequest) {
                        return $this->asyncJsonResponse(false, (string)__('语言 %{1} 未安装启用或为源语言，未创建队列。', [$localeCode]));
                    }
                    MessageManager::warning(__('语言 %{1} 未安装启用或为源语言，未创建队列。', [$localeCode]));
                }
            }
        } catch (\Throwable $throwable) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('创建 AI 翻译队列失败：%{1}', [$throwable->getMessage()]));
            }
            MessageManager::error(__('创建 AI 翻译队列失败：%{1}', [$throwable->getMessage()]));
        }

        return $this->redirect('*/backend/ai-translation');
    }

    public function postExportModules()
    {
        $isAsyncRequest = $this->isAsyncRequest();
        if (!$this->request->isPost()) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('请求方式错误。'));
            }
            MessageManager::error(__('请求方式错误。'));
            return $this->redirect('*/backend/ai-translation');
        }

        $localeCode = trim((string)$this->request->getPost('locale_code', ''));
        $moduleName = trim((string)$this->request->getPost('module_name', ''));
        try {
            if ($moduleName !== '' && $moduleName !== '*') {
                $result = $this->exportService->exportModuleTranslations($moduleName, $localeCode, true);
                $message = (string)($result['message'] ?? __('模块 %{1} 已写回 %{2} 条到 CSV，跳过 %{3} 条。', [
                    $moduleName,
                    (string)($result['exported'] ?? 0),
                    (string)($result['skipped'] ?? 0),
                ]));
            } else {
                $result = $this->exportService->exportAiTranslationsToModules($localeCode);
                $modules = (array)($result['modules'] ?? []);
                $moduleCount = count($modules);
                $modulePreview = implode(', ', array_slice(array_keys($modules), 0, 8));
                if ($moduleCount > 8) {
                    $modulePreview .= ', …';
                }
                $message = (string)__('已增量导出 %{1} 条 AI 译文到 %{2} 个模块语言包，跳过 %{3} 条。', [
                    (string)($result['exported'] ?? 0),
                    (string)$moduleCount,
                    (string)($result['skipped'] ?? 0),
                ]);
                if ($modulePreview !== '') {
                    $message .= ' ' . (string)__('涉及模块：%{1}', [$modulePreview]);
                }
            }

            if ($isAsyncRequest) {
                $ok = empty($result['errors']);
                return $this->asyncJsonResponse($ok, $message, $result);
            }
            if (!empty($result['errors'])) {
                MessageManager::warning($message);
            } else {
                MessageManager::success($message);
            }
            foreach ((array)($result['errors'] ?? []) as $error) {
                MessageManager::warning($error);
            }
        } catch (\Throwable $throwable) {
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(false, (string)__('导出 AI 译文到模块失败：%{1}', [$throwable->getMessage()]));
            }
            MessageManager::error(__('导出 AI 译文到模块失败：%{1}', [$throwable->getMessage()]));
        }

        return $this->redirect('*/backend/ai-translation');
    }


    public function postExportGlobal()
    {
        if (!$this->request->isPost()) {
            MessageManager::error(__('请求方式错误。'));
            return $this->redirect('*/backend/ai-translation');
        }

        $localeCode = trim((string)$this->request->getPost('locale_code', ''));
        try {
            $path = $this->exportService->exportGlobalLanguagePack($localeCode);
            $content = (string)file_get_contents($path);
            $filename = 'i18n-global-' . str_replace('-', '_', $localeCode) . '-' . date('YmdHis') . '.csv';
            $response = $this->request->getResponse();
            $response->setHeader('Content-Type', 'text/csv; charset=utf-8');
            $response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->setHeader('Content-Length', (string)strlen($content));
            $response->setBody($content);
            @unlink($path);

            return $content;
        } catch (\Throwable $throwable) {
            MessageManager::error(__('导出全局语言包失败：%{1}', [$throwable->getMessage()]));
            return $this->redirect('*/backend/ai-translation');
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private function buildLocaleRows(array $config): array
    {
        $rows = [];
        $displayLocale = \Weline\Framework\Http\Cookie::getLangLocal();
        $sourceLocale = (string)($config['source_locale'] ?? AiTranslationConfig::DEFAULT_SOURCE_LOCALE);
        foreach ($this->config->getTranslationCandidateLocaleCodes() as $localeCode) {
            $displayName = $localeCode;
            try {
                $displayName = $this->i18n->getLocaleName($localeCode, $displayLocale);
            } catch (\Throwable) {
            }

            $localeConfig = $config['locales'][$localeCode] ?? [];
            $isSource = $localeCode === $sourceLocale;
            $websiteAssigned = $this->config->isWebsiteAssignedLocale($localeCode);
            $tracksUnion = $this->config->tracksWebsiteLocaleUnion();
            $installed = $this->config->isInstalledActiveLocale($localeCode);
            if ($isSource) {
                $enabled = false;
            } elseif ($tracksUnion) {
                $enabled = $websiteAssigned;
            } else {
                $enabled = !empty($localeConfig['enabled']);
            }
            $skippedFromUnion = $tracksUnion && !$isSource && !$websiteAssigned;

            $translated = (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->count();
            $aiTranslated = (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->count();
            $pending = $isSource
                ? 0
                : $this->translationService->countUntranslatedWords($localeCode, $sourceLocale);
            $queue = $this->getLatestQueue($localeCode);
            $queueStatus = strtolower(trim((string)($queue['status'] ?? '')));
            $queueResult = trim((string)($queue['result'] ?? ''));
            $queueIsError = in_array($queueStatus, ['error', 'failed', 'fail'], true);
            $queueSummary = $this->summarizeQueueResult(
                $this->boundQueueResultForDisplay($queueResult)
            );

            $rows[] = [
                'code' => $localeCode,
                'name' => $displayName ?: $localeCode,
                'enabled' => $enabled,
                'is_source' => $isSource,
                'website_assigned' => $websiteAssigned,
                'skipped_from_union' => $skippedFromUnion,
                'installed' => $installed,
                'translated' => $translated,
                'ai_translated' => $aiTranslated,
                'pending' => $pending,
                'queue_id' => (int)($queue['queue_id'] ?? 0),
                'queue_status' => (string)($queue['status'] ?? ''),
                'queue_result' => $this->boundQueueResultForDisplay($queueResult),
                'queue_result_summary' => $queueSummary,
                'queue_is_error' => $queueIsError,
                'export_modules' => $isSource ? [] : $this->exportService->listAiSourceModules($localeCode),
            ];
        }

        return $rows;
    }

    private function boundQueueResultForDisplay(string $result): string
    {
        $result = trim($result);
        if ($result === '') {
            return '';
        }
        $max = self::QUEUE_RESULT_DISPLAY_MAX_BYTES;
        if (strlen($result) <= $max) {
            return $result;
        }
        $notice = '[truncated ' . strlen($result) . ' bytes → last ' . $max . "]\n";
        $budget = max(0, $max - strlen($notice));

        return $notice . substr($result, -$budget);
    }

    private function summarizeQueueResult(string $result): string
    {
        $result = trim(preg_replace('/\s+/u', ' ', $result) ?? $result);
        if ($result === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($result) > 180 ? (mb_substr($result, 0, 180) . '…') : $result;
        }

        return strlen($result) > 180 ? (substr($result, 0, 180) . '…') : $result;
    }



    /**
     * @return array<string, mixed>|null
     */
    private function getLatestQueue(string $localeCode): ?array
    {
        try {
            $queue = w_query('queue', 'getByBizKey', [
                'biz_key' => $this->queueService->buildBizKey($localeCode),
            ]);
        } catch (\Throwable) {
            return null;
        }

        return $this->normalizeQueueRow($queue);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeQueueRow(mixed $queue): ?array
    {
        if (is_array($queue)) {
            return $queue;
        }

        if (is_object($queue) && method_exists($queue, 'getData')) {
            $data = $queue->getData();
            return is_array($data) ? $data : null;
        }

        return null;
    }
}
