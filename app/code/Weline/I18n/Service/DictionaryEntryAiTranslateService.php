<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Http\Sse\SseWriter;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 词典编辑弹窗 · AI 一键翻译编排（薄 Service）。
 *
 * 落点约定（confirm 后落库）：
 * - 当前语：preview / SSE 仅算译文，**不写库**（用户再点「保存」走既有 postEdit）。
 * - 其他语：SSE 只产出 draft 译文 map（仍不写库）；前端 confirm 后调
 *   {@see saveOtherLocales()} 再 upsert + {@see AiTranslationPublisher::publishLocale}
 *   （与 InlineEdit 写后发布同源）。
 *
 * 禁止直调 Weline_Ai\*；机器翻译只经 {@see I18nAiTranslationAdapter::translateBatch}。
 */
final class DictionaryEntryAiTranslateService
{
    public function __construct(
        private readonly I18nAiTranslationAdapter $adapter,
        private readonly AiTranslationConfig $config,
        private readonly LocaleDictionary $localeDictionary,
        private readonly AiTranslationPublisher $publisher,
    ) {
    }

    /**
     * 当前目标语预览：Adapter 翻译，不写库。
     *
     * @return array{
     *     success: bool,
     *     translation?: string,
     *     source_locale?: string,
     *     target_locale?: string,
     *     message?: string,
     *     errors?: list<string>
     * }
     */
    public function previewCurrentLocale(string $word, string $targetLocale): array
    {
        $word = trim($word);
        $targetLocale = $this->normalizeLocale($targetLocale);
        $sourceLocale = $this->normalizeLocale($this->config->getSourceLocale());

        if ($word === '') {
            return [
                'success' => false,
                'message' => (string)__('原文不能为空'),
                'errors' => [(string)__('原文不能为空')],
            ];
        }
        if ($targetLocale === '') {
            return [
                'success' => false,
                'message' => (string)__('目标语言不能为空'),
                'errors' => [(string)__('目标语言不能为空')],
            ];
        }
        if ($targetLocale === $sourceLocale) {
            return [
                'success' => true,
                'translation' => $word,
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
                'message' => (string)__('目标语与源语相同，直接回填原文'),
            ];
        }

        try {
            WordDictionary::assertWord($word);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [$e->getMessage()],
            ];
        }

        $result = $this->adapter->translateBatch(
            [$word],
            $sourceLocale,
            $targetLocale,
            $this->config->getStrategy($targetLocale),
        );

        $translation = trim((string)($result['translations'][$word] ?? ''));
        $errors = array_values(array_map('strval', (array)($result['errors'] ?? [])));

        $isEcho = I18nCsvCodec::isUntranslatedEcho($word, $translation, $sourceLocale, $targetLocale);
        if ($translation === ''
            || I18nCsvCodec::isJunkTranslation($translation)
            || I18nCsvCodec::isGarbledText($translation)
            || $isEcho
        ) {
            $message = $errors !== []
                ? implode('; ', $errors)
                : ($isEcho
                    ? (string)__('AI 仍返回原文，未真正译成目标语（%{1}）', [$targetLocale])
                    : (string)__('AI 未返回可用译文'));

            return [
                'success' => false,
                'message' => $message,
                'errors' => $errors !== [] ? $errors : [$message],
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
            ];
        }

        return [
            'success' => true,
            'translation' => $translation,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'message' => (string)__('翻译完成（未保存）'),
            'errors' => $errors,
        ];
    }

    /**
     * SSE：当前语预览。事件 start / progress / complete|failed；complete 含 translation。不写库。
     */
    public function streamCurrentLocale(SseWriter $sse, string $word, string $targetLocale): void
    {
        $sse->start();
        $word = trim($word);
        $targetLocale = $this->normalizeLocale($targetLocale);

        $sse->sendEvent('start', [
            'message' => (string)__('开始 AI 翻译当前语言…'),
            'locale' => $targetLocale,
            'index' => 0,
            'total' => 1,
        ]);

        if (!$sse->isAlive()) {
            return;
        }

        $sse->sendEvent('progress', [
            'message' => (string)__('正在翻译…'),
            'locale' => $targetLocale,
            'index' => 1,
            'total' => 1,
            'percent' => 50,
        ]);

        $preview = $this->previewCurrentLocale($word, $targetLocale);
        if (empty($preview['success'])) {
            $payload = [
                'success' => false,
                'message' => (string)($preview['message'] ?? __('AI 翻译失败')),
                'errors' => $preview['errors'] ?? [],
                'locale' => $targetLocale,
            ];
            $sse->sendEvent('failed', $payload);
            $sse->complete($payload);

            return;
        }

        $payload = [
            'success' => true,
            'message' => (string)($preview['message'] ?? __('翻译完成（未保存）')),
            'translation' => (string)$preview['translation'],
            'locale' => $targetLocale,
            'source_locale' => (string)($preview['source_locale'] ?? ''),
            'target_locale' => (string)($preview['target_locale'] ?? $targetLocale),
            'index' => 1,
            'total' => 1,
            'percent' => 100,
        ];
        $sse->sendEvent('complete', $payload);
        $sse->complete($payload);
    }

    /**
     * SSE：其他语种草稿翻译。目标集 = AiTranslationConfig::getDictionaryPublishLocaleCodes()
     *（WebsiteLanguage∩已安装激活，含源语再排除）减去 currentLocale 与源语。
     *
     * 不写库；complete 含 drafts + locales，供前端 confirm 后调 {@see saveOtherLocales()}。
     *
     * @param bool $skipExisting 默认 true：已有非 junk 译文则跳过该语种（仍列入 skipped）
     */
    public function streamOtherLocales(
        SseWriter $sse,
        string $word,
        string $currentLocale,
        bool $skipExisting = true,
    ): void {
        $sse->start();
        $word = trim($word);
        $currentLocale = $this->normalizeLocale($currentLocale);
        $sourceLocale = $this->normalizeLocale($this->config->getSourceLocale());
        $targets = $this->resolveOtherLocales($currentLocale);

        $sse->sendEvent('start', [
            'message' => (string)__('开始翻译其他语言（草稿，确认后才保存）…'),
            'locales' => $targets,
            'total' => count($targets),
            'current_locale' => $currentLocale,
            'source_locale' => $sourceLocale,
        ]);

        if ($word === '') {
            $payload = [
                'success' => false,
                'message' => (string)__('原文不能为空'),
                'drafts' => [],
                'locales' => [],
            ];
            $sse->sendEvent('failed', $payload);
            $sse->complete($payload);

            return;
        }

        try {
            WordDictionary::assertWord($word);
        } catch (\Throwable $e) {
            $payload = [
                'success' => false,
                'message' => $e->getMessage(),
                'drafts' => [],
                'locales' => [],
            ];
            $sse->sendEvent('failed', $payload);
            $sse->complete($payload);

            return;
        }

        if ($targets === []) {
            $payload = [
                'success' => true,
                'message' => (string)__('没有需要翻译的其他语言'),
                'drafts' => [],
                'locales' => [],
                'skipped' => [],
                'errors' => [],
            ];
            $sse->sendEvent('complete', $payload);
            $sse->complete($payload);

            return;
        }

        $drafts = [];
        $skipped = [];
        $errors = [];
        $total = count($targets);
        $index = 0;

        foreach ($targets as $localeCode) {
            if (!$sse->isAlive()) {
                return;
            }
            $index++;
            $percent = (int)round(($index / max(1, $total)) * 100);

            if ($skipExisting) {
                $existing = $this->loadExistingTranslate($word, $localeCode);
                if ($existing !== null
                    && $existing !== ''
                    && !I18nCsvCodec::isJunkTranslation($existing)
                    && !I18nCsvCodec::isGarbledText($existing)
                ) {
                    $skipped[] = $localeCode;
                    $sse->sendEvent('progress', [
                        'locale' => $localeCode,
                        'index' => $index,
                        'total' => $total,
                        'percent' => $percent,
                        'status' => 'skipped',
                        'message' => (string)__('已有译文，跳过：%{1}', [$localeCode]),
                    ]);
                    continue;
                }
            }

            $sse->sendEvent('progress', [
                'locale' => $localeCode,
                'index' => $index,
                'total' => $total,
                'percent' => $percent,
                'status' => 'translating',
                'message' => (string)__('正在翻译：%{1}', [$localeCode]),
            ]);

            $result = $this->adapter->translateBatch(
                [$word],
                $sourceLocale,
                $localeCode,
                $this->config->getStrategy($localeCode),
            );
            $translation = trim((string)($result['translations'][$word] ?? ''));
            $batchErrors = array_values(array_map('strval', (array)($result['errors'] ?? [])));

            $isEcho = I18nCsvCodec::isUntranslatedEcho($word, $translation, $sourceLocale, $localeCode);
            if ($translation === ''
                || I18nCsvCodec::isJunkTranslation($translation)
                || I18nCsvCodec::isGarbledText($translation)
                || $isEcho
            ) {
                $msg = $batchErrors !== []
                    ? implode('; ', $batchErrors)
                    : ($isEcho
                        ? (string)__('语种 %{1} 仍返回原文，疑似 AI 未真正翻译', [$localeCode])
                        : (string)__('语种 %{1} 未返回可用译文', [$localeCode]));
                $errors[] = $msg;
                $sse->sendEvent('progress', [
                    'locale' => $localeCode,
                    'index' => $index,
                    'total' => $total,
                    'percent' => $percent,
                    'status' => 'error',
                    'message' => $msg,
                ]);
                continue;
            }

            $drafts[$localeCode] = $translation;
            $sse->sendEvent('progress', [
                'locale' => $localeCode,
                'index' => $index,
                'total' => $total,
                'percent' => $percent,
                'status' => 'ok',
                'translation' => $translation,
                'message' => (string)__('已译：%{1}', [$localeCode]),
            ]);
        }

        $success = $drafts !== [] || ($errors === [] && $skipped !== []);
        $echoOnlyFailure = $drafts === [] && $errors !== [];
        // Prefer concrete provider errors (unreachable model, missing API key, etc.) over the
        // generic「仍返回原文」copy — empty drafts often mean the provider never answered.
        $failureMessage = (string)__('其他语言翻译失败');
        if ($echoOnlyFailure) {
            $firstError = trim((string)($errors[0] ?? ''));
            $isEchoShaped = $firstError !== '' && (
                str_contains($firstError, '仍返回原文')
                || str_contains($firstError, '未真正翻译')
                || str_contains($firstError, '未返回可用译文')
            );
            $failureMessage = ($firstError !== '' && !$isEchoShaped)
                ? $firstError
                : (string)__('AI 未能译出有效译文（多语种仍返回原文），请检查翻译服务后重试');
        }
        $payload = [
            'success' => $success,
            'message' => $success
                ? (string)__('其他语言草稿已就绪，确认后保存')
                : $failureMessage,
            'drafts' => $drafts,
            'translations' => $drafts,
            'locales' => array_keys($drafts),
            'skipped' => $skipped,
            'errors' => $errors,
            'word' => $word,
            'current_locale' => $currentLocale,
            'source_locale' => $sourceLocale,
        ];

        if (!$success) {
            $sse->sendEvent('failed', $payload);
            $sse->complete($payload);

            return;
        }

        $sse->sendEvent('complete', $payload);
        $sse->complete($payload);
    }

    /**
     * 确认后落库：upsert 各地区词典行 + publishLocale（对齐 InlineEdit）。
     *
     * @param array<string, string> $drafts locale_code => translation
     * @return array{
     *     success: bool,
     *     message: string,
     *     saved?: list<string>,
     *     published?: list<string>,
     *     skipped?: list<string>,
     *     errors?: list<string>
     * }
     */
    public function saveOtherLocales(string $word, array $drafts, string $excludeLocale = ''): array
    {
        $word = trim($word);
        $excludeLocale = $this->normalizeLocale($excludeLocale);
        $sourceLocale = $this->normalizeLocale($this->config->getSourceLocale());
        // Allowlist = publish 口径 − 源语 − 当前弹窗语（与 streamOtherLocales 一致）。
        $allowed = array_fill_keys(
            $excludeLocale !== ''
                ? $this->resolveOtherLocales($excludeLocale)
                : $this->resolveOtherLocales(''),
            true,
        );

        if ($word === '') {
            return [
                'success' => false,
                'message' => (string)__('原文不能为空'),
                'errors' => [(string)__('原文不能为空')],
            ];
        }

        try {
            WordDictionary::assertWord($word);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [$e->getMessage()],
            ];
        }

        if ($drafts === []) {
            return [
                'success' => false,
                'message' => (string)__('没有可保存的译文草稿'),
                'errors' => [(string)__('没有可保存的译文草稿')],
            ];
        }

        $saved = [];
        $skipped = [];
        $errors = [];
        $toPublish = [];

        foreach ($drafts as $localeCode => $translation) {
            $localeCode = $this->normalizeLocale((string)$localeCode);
            $translation = trim((string)$translation);
            if ($localeCode === '' || $localeCode === $sourceLocale || $localeCode === $excludeLocale) {
                $skipped[] = $localeCode;
                continue;
            }
            if ($allowed !== [] && !isset($allowed[$localeCode])) {
                $skipped[] = $localeCode;
                $errors[] = (string)__('语种不在可翻译集合内：%{1}', [$localeCode]);
                continue;
            }
            $isEchoSave = I18nCsvCodec::isUntranslatedEcho($word, $translation, $sourceLocale, $localeCode);
            if ($translation === ''
                || I18nCsvCodec::isJunkTranslation($translation)
                || I18nCsvCodec::isGarbledText($translation)
                || $isEchoSave
            ) {
                $skipped[] = $localeCode;
                $errors[] = $isEchoSave
                    ? (string)__('跳过未真正翻译的原文回声：%{1}', [$localeCode])
                    : (string)__('跳过无效译文：%{1}', [$localeCode]);
                continue;
            }

            try {
                if (!$this->localeDictionary->upsert($word, $localeCode, $translation)) {
                    $errors[] = (string)__('保存失败：%{1}', [$localeCode]);
                    continue;
                }
                $saved[] = $localeCode;
                $toPublish[$localeCode] = true;
            } catch (\Throwable $e) {
                $errors[] = (string)__('保存失败 %{1}：%{2}', [$localeCode, $e->getMessage()]);
            }
        }

        $published = [];
        foreach (array_keys($toPublish) as $localeCode) {
            try {
                if ($this->publisher->publishLocale($localeCode)) {
                    $published[] = $localeCode;
                } else {
                    $errors[] = (string)__('已写入词典，但发布语言包失败：%{1}', [$localeCode]);
                }
            } catch (\Throwable $e) {
                $errors[] = (string)__('发布失败 %{1}：%{2}', [$localeCode, $e->getMessage()]);
            }
        }

        $success = $saved !== [];

        return [
            'success' => $success,
            'message' => $success
                ? (string)__('已保存并发布 %{1} 个语种', [(string)count($saved)])
                : (string)__('保存失败'),
            'saved' => $saved,
            'published' => $published,
            'skipped' => array_values(array_filter($skipped)),
            'errors' => $errors,
        ];
    }

    /**
     * 其他语种目标列表：AiTranslationConfig 词典发布口径 − 源语 − 当前弹窗目标语。
     *
     * @return list<string>
     */
    public function resolveOtherLocales(string $currentLocale): array
    {
        $currentLocale = $this->normalizeLocale($currentLocale);
        $sourceLocale = $this->normalizeLocale($this->config->getSourceLocale());
        $out = [];
        foreach ($this->config->getDictionaryPublishLocaleCodes() as $code) {
            $code = $this->normalizeLocale((string)$code);
            if ($code === '' || $code === $sourceLocale || $code === $currentLocale) {
                continue;
            }
            $out[] = $code;
        }

        return array_values(array_unique($out));
    }

    private function loadExistingTranslate(string $word, string $localeCode): ?string
    {
        try {
            $entry = $this->localeDictionary->getEntry($word, $localeCode);
        } catch (\Throwable) {
            return null;
        }
        if ($entry === null) {
            return null;
        }

        return trim((string)$entry->translation);
    }

    private function normalizeLocale(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }
}
