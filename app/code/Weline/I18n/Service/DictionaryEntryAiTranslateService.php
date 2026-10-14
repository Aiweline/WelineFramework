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
        $pending = [];
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
            $pending[] = $localeCode;
        }

        // Pack pending locales into model calls by config batch_size + ~2000 input chars
        // (one word → many locales JSON). Never one-locale-per-request.
        $chunks = $this->chunkLocalesForMultiTranslate($word, $pending);
        $doneCount = count($skipped);
        $chunkIndex = 0;
        foreach ($chunks as $chunkLocales) {
            if (!$sse->isAlive()) {
                return;
            }
            $chunkIndex++;
            $doneCount += count($chunkLocales);
            $percent = (int)round(($doneCount / max(1, $total)) * 100);
            $sse->sendEvent('progress', [
                'locales' => $chunkLocales,
                'index' => $doneCount,
                'total' => $total,
                'percent' => $percent,
                'status' => 'translating',
                'message' => (string)__(
                    '正在批量翻译 %{1} 个语种（第 %{2}/%{3} 批）…',
                    [count($chunkLocales), $chunkIndex, count($chunks)]
                ),
            ]);

            $strategy = $this->config->getStrategy((string)($chunkLocales[0] ?? 'en_US'));
            $result = $this->adapter->translateWordToLocales(
                $word,
                $chunkLocales,
                $sourceLocale,
                $strategy,
            );
            $batchMap = is_array($result['translations'] ?? null) ? $result['translations'] : [];
            $batchErrors = array_values(array_map('strval', (array)($result['errors'] ?? [])));
            $chunkFailed = $batchErrors !== [] && $batchMap === [];

            foreach ($chunkLocales as $localeCode) {
                $translation = trim((string)($batchMap[$localeCode] ?? ''));
                $isEcho = $this->shouldRejectOtherLocaleEcho($word, $translation, $sourceLocale, $localeCode);
                if ($translation === ''
                    || I18nCsvCodec::isJunkTranslation($translation)
                    || I18nCsvCodec::isGarbledText($translation)
                    || $isEcho
                ) {
                    $msg = $chunkFailed && $batchErrors !== []
                        ? implode('; ', $batchErrors)
                        : ($isEcho
                            ? (string)__('语种 %{1} 仍返回原文，疑似 AI 未真正翻译', [$localeCode])
                            : (string)__('语种 %{1} 未返回可用译文', [$localeCode]));
                    $errors[] = $msg;
                    $sse->sendEvent('progress', [
                        'locale' => $localeCode,
                        'index' => $doneCount,
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
                    'index' => $doneCount,
                    'total' => $total,
                    'percent' => $percent,
                    'status' => 'ok',
                    'translation' => $translation,
                    'message' => (string)__('已译：%{1}', [$localeCode]),
                ]);
            }
        }

        $success = $drafts !== [] || ($errors === [] && $skipped !== []);
        $echoOnlyFailure = $drafts === [] && $errors !== [];
        // Prefer concrete provider errors (balance / API key / unreachable) over the
        // generic「仍返回原文」copy — empty drafts usually mean the provider never answered.
        $failureMessage = (string)__('其他语言翻译失败');
        if ($echoOnlyFailure) {
            $failureMessage = $this->resolveOtherLocalesFailureMessage($errors);
        }
        $successMessage = ($drafts === [] && $skipped !== [])
            ? (string)__('其他语言均已有译文，已跳过')
            : (string)__('其他语言草稿已就绪，确认后保存');
        $payload = [
            'success' => $success,
            'message' => $success ? $successMessage : $failureMessage,
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
            $isEchoSave = $this->shouldRejectOtherLocaleEcho($word, $translation, $sourceLocale, $localeCode);
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

    /**
     * Pack locales for one-word multi-locale model calls.
     * Cap by AiTranslationConfig batch_size and ~2000 input characters (prompt + word + locale list).
     *
     * @param list<string> $locales
     * @return list<list<string>>
     */
    private function chunkLocalesForMultiTranslate(string $word, array $locales): array
    {
        if ($locales === []) {
            return [];
        }

        $batchCap = max(1, min(200, $this->config->getBatchSize()));
        // Keep room for prompt scaffold; user budget ≈ 2000 chars of packed payload.
        $charBudget = 2000;
        $promptOverhead = 480;
        $base = $promptOverhead + $this->unicodeLen($word);
        $chunks = [];
        $current = [];
        $size = $base;

        foreach ($locales as $locale) {
            $locale = trim((string)$locale);
            if ($locale === '') {
                continue;
            }
            $add = $this->unicodeLen($locale) + 24;
            $wouldExceed = $current !== [] && (
                count($current) >= $batchCap || ($size + $add) > $charBudget
            );
            if ($wouldExceed) {
                $chunks[] = $current;
                $current = [];
                $size = $base;
            }
            $current[] = $locale;
            $size += $add;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Pick a user-facing failure for empty drafts.
     * Provider/API errors always win; only true echo-shaped rows use the「仍返回原文」copy.
     *
     * @param list<string> $errors
     */
    private function resolveOtherLocalesFailureMessage(array $errors): string
    {
        $joined = implode("\n", array_map('strval', $errors));
        foreach ($errors as $raw) {
            $human = $this->humanizeProviderTranslateError((string)$raw);
            if ($human !== '') {
                return $human;
            }
        }
        $hasTrueEcho = str_contains($joined, '仍返回原文') || str_contains($joined, '未真正翻译');
        if ($hasTrueEcho) {
            return (string)__('AI 未能译出有效译文（多语种仍返回原文），请检查翻译服务后重试');
        }
        if (str_contains($joined, '未返回可用译文') || str_contains($joined, '未返回任何语种')) {
            return (string)__('AI 未返回可用译文，请检查翻译模型、API 余额或密钥后重试');
        }
        $first = trim((string)($errors[0] ?? ''));

        return $first !== '' ? $first : (string)__('其他语言翻译失败');
    }

    private function humanizeProviderTranslateError(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $lower = strtolower($raw);
        if (str_contains($lower, 'insufficient balance')
            || str_contains($raw, '余额不足')
            || str_contains($lower, 'insufficient_quota')
        ) {
            return (string)__('翻译 API 余额不足（Insufficient Balance），请充值 DeepSeek/当前供应商后重试');
        }
        if (str_contains($lower, 'missing api key')
            || str_contains($raw, '缺少') && str_contains($lower, 'api key')
            || str_contains($raw, '未配置') && str_contains($lower, 'api')
        ) {
            return (string)__('翻译模型缺少 API 密钥，请到 AI 供应商账户配置后重试');
        }
        if (str_contains($lower, 'could not connect')
            || str_contains($lower, 'connection refused')
            || str_contains($raw, '无法连接')
        ) {
            return (string)__('无法连接翻译服务，请检查网络或本地模型是否在运行');
        }
        // Keep long provider diagnostics when they look like real generation failures.
        if (str_contains($raw, 'AI generation failed')
            || str_contains($raw, 'API返回错误')
            || str_contains($raw, 'API调用失败')
        ) {
            return $raw;
        }

        return '';
    }

    /**
     * Cross-locale identity is usually echo; Latin brand/loanword kept identical is acceptable.
     */
    private function shouldRejectOtherLocaleEcho(
        string $word,
        string $translation,
        string $sourceLocale,
        string $targetLocale,
    ): bool {
        if (!I18nCsvCodec::isUntranslatedEcho($word, $translation, $sourceLocale, $targetLocale)) {
            return false;
        }
        $word = trim($word);
        $translation = trim($translation);
        if ($word === '' || $word !== $translation) {
            return true;
        }
        // ASCII/Latin-only tokens (Store, SKU, PayPal) may stay unchanged across locales.
        if (preg_match('/^[\x20-\x7E]+$/u', $word) === 1) {
            return false;
        }

        return true;
    }

    private function unicodeLen(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($value, 'UTF-8');
        }

        return strlen($value);
    }

    private function normalizeLocale(string $localeCode): string
    {
        return trim(str_replace('-', '_', $localeCode));
    }
}
