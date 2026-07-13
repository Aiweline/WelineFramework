<?php
declare(strict_types=1);

namespace Weline\I18n\Controller\Backend;

use Weline\Framework\Manager\MessageManager;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locale;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\AiTranslationExportService;
use Weline\I18n\Service\AiTranslationQueueService;
use Weline\I18n\Service\AiTranslationService;

class AiTranslation extends BaseController
{
    public function __construct(
        Locale $locale,
        I18n $i18n,
        private readonly AiTranslationConfig $config,
        private readonly AiTranslationQueueService $queueService,
        private readonly AiTranslationService $translationService,
        private readonly AiTranslationExportService $exportService,
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
        $config = $this->config->getConfig();
        $this->assign('config', $config);
        $this->assign('locales', $this->buildLocaleRows($config));
        $this->assign('stats', [
            'total_words' => (int)$this->dictionary->clear()->reset()->count(),
            'enabled_locales' => count($this->config->getEnabledLocaleCodes()),
            'ai_translated' => (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->count(),
        ]);

        return $this->fetch();
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
            if (!empty($config['enabled'])) {
                $queueIds = $this->queueService->enqueueEnabledLocales('config_save');
            }

            $message = (string)__('AI翻译配置已保存，已入队 %{1} 个语言。', [(string)count($queueIds)]);
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, $message, ['queue_count' => count($queueIds)]);
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
                $message = (string)__('已为 %{1} 个启用语言创建 AI 翻译队列。', [(string)count($queueIds)]);
                if ($isAsyncRequest) {
                    return $this->asyncJsonResponse(true, $message, ['queue_count' => count($queueIds)]);
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
        try {
            $result = $this->exportService->exportAiTranslationsToModules($localeCode);
            $moduleCount = count((array)($result['modules'] ?? []));
            $message = (string)__('已增量导出 %{1} 条 AI 译文到 %{2} 个模块语言包，跳过 %{3} 条。', [
                (string)($result['exported'] ?? 0),
                (string)$moduleCount,
                (string)($result['skipped'] ?? 0),
            ]);
            if ($isAsyncRequest) {
                return $this->asyncJsonResponse(true, $message, $result);
            }
            MessageManager::success($message);
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
        foreach ($this->config->getInstalledActiveLocaleCodes() as $localeCode) {
            $displayName = $localeCode;
            try {
                $displayName = $this->i18n->getLocaleName($localeCode, $displayLocale);
            } catch (\Throwable) {
            }

            $localeConfig = $config['locales'][$localeCode] ?? [];
            $enabled = !empty($localeConfig['enabled']);

            $translated = (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->count();
            $aiTranslated = (int)$this->localeDictionary->clear()->reset()
                ->where(LocaleDictionary::schema_fields_LOCALE_CODE, $localeCode)
                ->where(LocaleDictionary::schema_fields_IS_AI, 1)
                ->where(LocaleDictionary::schema_fields_TRANSLATE, '', '!=')
                ->count();
            $pending = $this->translationService->countUntranslatedWords(
                $localeCode,
                (string)($config['source_locale'] ?? AiTranslationConfig::DEFAULT_SOURCE_LOCALE)
            );
            $queue = $this->getLatestQueue($localeCode);

            $rows[] = [
                'code' => $localeCode,
                'name' => $displayName ?: $localeCode,
                'enabled' => $enabled,
                'is_source' => $localeCode === (string)($config['source_locale'] ?? ''),
                'translated' => $translated,
                'ai_translated' => $aiTranslated,
                'pending' => $pending,
                'queue_id' => (int)($queue['queue_id'] ?? 0),
                'queue_status' => (string)($queue['status'] ?? ''),
                'queue_result' => (string)($queue['result'] ?? ''),
            ];
        }

        return $rows;
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
