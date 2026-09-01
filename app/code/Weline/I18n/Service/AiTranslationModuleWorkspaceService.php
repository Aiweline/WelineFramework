<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * AI 翻译页「按模块」工作台：跨语言查看某模块待办，并支持一键仅翻译该模块后写回 CSV。
 */
final class AiTranslationModuleWorkspaceService
{
    private const MAX_TRANSLATE_ROUNDS = 30;

    public function __construct(
        private readonly AiTranslationConfig $aiConfig,
        private readonly DictionaryModuleCatalogService $moduleCatalog,
        private readonly AiTranslationExportService $exportService,
    ) {
    }

    /**
     * Module list from system active modules that have an i18n directory.
     *
     * @return list<array{
     *     module: string,
     *     pending_export_total: int,
     *     locales_total: int,
     *     locales_pending: int,
     *     words_pending: int,
     *     source_words: int,
     *     has_untranslated: bool,
     *     has_work: bool
     * }>
     */
    public function listModulesLite(string $search = ''): array
    {
        $search = trim($search);
        $locales = $this->targetLocales();
        $pendingByModule = $this->moduleCatalog->countAiPendingExportTotalsByModule($locales);
        $rows = [];

        foreach ($this->iterI18nModules($search) as $moduleName) {
            $pendingExportTotal = (int)($pendingByModule[$moduleName] ?? 0);
            $overview = $this->moduleCatalog->summarizeModuleWorkOverview($moduleName, $locales);
            $localesPending = (int)($overview['locales_pending'] ?? 0);
            $wordsPending = (int)($overview['words_pending'] ?? 0);
            $hasUntranslated = $localesPending > 0 || $wordsPending > 0;

            $rows[] = [
                'module' => $moduleName,
                'pending_export_total' => $pendingExportTotal,
                'locales_total' => (int)($overview['locales_total'] ?? count($locales)),
                'locales_pending' => $localesPending,
                'words_pending' => $wordsPending,
                'source_words' => (int)($overview['source_words'] ?? 0),
                'has_untranslated' => $hasUntranslated,
                'has_work' => $hasUntranslated || $pendingExportTotal > 0,
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if (($a['has_work'] ?? false) !== ($b['has_work'] ?? false)) {
                    return ($a['has_work'] ?? false) ? -1 : 1;
                }
                $pendingCmp = ((int)($b['words_pending'] ?? 0)) <=> ((int)($a['words_pending'] ?? 0));
                if ($pendingCmp !== 0) {
                    return $pendingCmp;
                }

                return strcmp((string)$a['module'], (string)$b['module']);
            },
        );

        return $rows;
    }

    /**
     * @return array{
     *     source: array{locale: string, word_count: int},
     *     locales: list<array{
     *         code: string,
     *         untranslated: int,
     *         translated: int,
     *         word_count: int,
     *         gap: int,
     *         ai_pending_export: int,
     *         has_work: bool
     *     }>
     * }
     */
    public function getModuleLocaleMatrixBundle(string $moduleName): array
    {
        $moduleName = trim($moduleName);
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        if ($moduleName === '') {
            return [
                'source' => ['locale' => $sourceLocale, 'word_count' => 0],
                'locales' => [],
            ];
        }

        $sourceWords = $this->moduleCatalog->countModuleSourceWords($moduleName, true);
        $rows = [];
        foreach ($this->targetLocales() as $localeCode) {
            $stats = $this->moduleCatalog->summarizeModuleLocaleAlignment($moduleName, $localeCode, true);
            $pendingExport = $this->moduleCatalog->countAiPendingExportPublic($moduleName, $localeCode);
            $untranslated = (int)($stats['untranslated'] ?? 0);
            $rows[] = [
                'code' => $localeCode,
                'untranslated' => $untranslated,
                'translated' => (int)($stats['translated'] ?? 0),
                'word_count' => (int)($stats['word_count'] ?? 0),
                'gap' => (int)($stats['gap'] ?? $untranslated),
                'ai_pending_export' => $pendingExport,
                'has_work' => $untranslated > 0 || $pendingExport > 0,
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if (($a['has_work'] ?? false) !== ($b['has_work'] ?? false)) {
                    return ($a['has_work'] ?? false) ? -1 : 1;
                }

                return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
            },
        );

        return [
            'source' => [
                'locale' => $sourceLocale,
                'word_count' => $sourceWords,
            ],
            'locales' => $rows,
        ];
    }

    /**
     * @return list<array{
     *     code: string,
     *     untranslated: int,
     *     ai_pending_export: int,
     *     has_work: bool
     * }>
     */
    public function getModuleLocaleMatrix(string $moduleName): array
    {
        return $this->getModuleLocaleMatrixBundle($moduleName)['locales'];
    }

    /**
     * Stream one locale progress event at a time (SSE).
     * Syncs source-locale CSV once first, then reports alignment diffs.
     *
     * @param array<string, string> $localeNames
     */
    public function streamModuleLocaleMatrix(
        SseWriter $sse,
        string $moduleName,
        array $localeNames = [],
    ): void {
        $moduleName = trim($moduleName);
        $sse->setRetryInterval(60000);
        $sse->start();
        if ($moduleName === '') {
            $sse->sendError((string)__('缺少模块名'), 400);
            $sse->complete(['success' => false]);

            return;
        }

        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $sse->sendEvent('start', [
            'module' => $moduleName,
            'node' => 'collect',
            'message' => (string)__('正在收集并回写源语言 CSV…'),
        ]);

        $sync = ['success' => true, 'added_source' => 0, 'collected' => 0];
        try {
            $sync = $this->moduleCatalog->syncModuleCollectedWords($moduleName);
        } catch (\Throwable $throwable) {
            $sse->sendEvent('progress', [
                'module' => $moduleName,
                'node' => 'collect',
                'message' => (string)__('源语言收集失败：%{1}', [$throwable->getMessage()]),
            ]);
        }

        $sourceWords = $this->moduleCatalog->countModuleSourceWords($moduleName, false);
        $sse->sendEvent('source', [
            'module' => $moduleName,
            'locale' => $sourceLocale,
            'name' => $localeNames[$sourceLocale] ?? $sourceLocale,
            'word_count' => $sourceWords,
            'added_source' => (int)($sync['added_source'] ?? 0),
            'collected' => (int)($sync['collected'] ?? 0),
            'message' => (string)__(
                '源语言 %{1}：词数 %{2}（本次新增 %{3}）',
                [
                    $localeNames[$sourceLocale] ?? $sourceLocale,
                    (string)$sourceWords,
                    (string)($sync['added_source'] ?? 0),
                ],
            ),
        ]);

        $targets = $this->targetLocales();
        $total = count($targets);
        $sse->sendEvent('progress', [
            'module' => $moduleName,
            'total' => $total,
            'node' => 'translate',
            'message' => (string)__('正在对照源语言统计各语言差额…'),
        ]);

        $pendingByLocale = [];
        try {
            $pendingByLocale = $this->moduleCatalog->countAiPendingExportByLocales($moduleName, $targets);
        } catch (\Throwable) {
            $pendingByLocale = [];
        }

        $rows = [];
        $index = 0;
        foreach ($targets as $localeCode) {
            if (!$sse->isAlive()) {
                return;
            }
            $index++;
            $sse->sendEvent('progress', [
                'module' => $moduleName,
                'code' => $localeCode,
                'name' => $localeNames[$localeCode] ?? $localeCode,
                'index' => $index,
                'total' => $total,
                'message' => (string)__('正在统计 %{1}（%{2}/%{3}）', [
                    $localeNames[$localeCode] ?? $localeCode,
                    (string)$index,
                    (string)$total,
                ]),
            ]);

            try {
                $pendingExport = (int)($pendingByLocale[$localeCode] ?? 0);
                // Matrix expand already synced source; CSV-only alignment keeps SSE responsive.
                $stats = $this->moduleCatalog->summarizeModuleLocaleAlignment(
                    $moduleName,
                    $localeCode,
                    false,
                );
            } catch (\Throwable $throwable) {
                $sse->sendEvent('locale', [
                    'code' => $localeCode,
                    'name' => $localeNames[$localeCode] ?? $localeCode,
                    'untranslated' => 0,
                    'translated' => 0,
                    'word_count' => 0,
                    'gap' => 0,
                    'ai_pending_export' => 0,
                    'has_work' => false,
                    'index' => $index,
                    'total' => $total,
                    'error' => $throwable->getMessage(),
                ]);
                continue;
            }

            $untranslated = (int)($stats['untranslated'] ?? 0);
            $row = [
                'code' => $localeCode,
                'name' => $localeNames[$localeCode] ?? $localeCode,
                'untranslated' => $untranslated,
                'translated' => (int)($stats['translated'] ?? 0),
                'word_count' => (int)($stats['word_count'] ?? 0),
                'gap' => (int)($stats['gap'] ?? $untranslated),
                'ai_pending_export' => $pendingExport,
                'has_work' => $untranslated > 0 || $pendingExport > 0,
                'index' => $index,
                'total' => $total,
            ];
            $rows[] = $row;
            $sse->sendEvent('locale', $row);
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                if (($a['has_work'] ?? false) !== ($b['has_work'] ?? false)) {
                    return ($a['has_work'] ?? false) ? -1 : 1;
                }

                return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
            },
        );

        $sse->complete([
            'success' => true,
            'module' => $moduleName,
            'source' => [
                'locale' => $sourceLocale,
                'name' => $localeNames[$sourceLocale] ?? $sourceLocale,
                'word_count' => $sourceWords,
            ],
            'locales' => $rows,
            'message' => (string)__('语言进度已加载'),
        ]);
    }

    /**
     * SSE stream for translate + writeback with per-stage / per-batch progress.
     *
     * @param list<string> $localeCodes
     * @param array<string, string> $localeNames
     */
    public function streamTranslateAndWriteback(
        SseWriter $sse,
        string $moduleName,
        array $localeCodes = [],
        array $localeNames = [],
    ): void {
        $sse->setRetryInterval(60000);
        $sse->start();

        $alive = static function () use ($sse): bool {
            return $sse->isAlive();
        };
        $emit = static function (string $event, array $payload) use ($sse): void {
            if ($sse->isAlive()) {
                $sse->sendEvent($event, $payload);
            }
        };

        $result = $this->runTranslateAndWriteback($moduleName, $localeCodes, $localeNames, $emit, $alive);
        if (!$sse->isAlive()) {
            return;
        }
        if (empty($result['success']) && !empty($result['fatal'])) {
            $sse->sendEvent('failed', [
                'message' => (string)($result['message'] ?? __('操作失败')),
                'success' => false,
            ]);
            $sse->close();

            return;
        }

        $sse->complete($result);
    }

    /**
     * Only translate + write back this module for selected locales (or all locales with work).
     *
     * @param list<string> $localeCodes empty = auto locales that still have work
     * @return array<string, mixed>
     */
    public function translateAndWriteback(string $moduleName, array $localeCodes = []): array
    {
        return $this->runTranslateAndWriteback($moduleName, $localeCodes);
    }

    /**
     * @param list<string> $localeCodes
     * @param array<string, string> $localeNames
     * @param null|callable(string, array<string, mixed>): void $emit
     * @param null|callable(): bool $alive
     * @return array<string, mixed>
     */
    private function runTranslateAndWriteback(
        string $moduleName,
        array $localeCodes = [],
        array $localeNames = [],
        ?callable $emit = null,
        ?callable $alive = null,
    ): array {
        $moduleName = trim($moduleName);
        $notify = static function (string $event, array $payload = []) use ($emit): void {
            if ($emit !== null) {
                $emit($event, $payload);
            }
        };
        $isAlive = static function () use ($alive): bool {
            return $alive === null || $alive();
        };

        if ($moduleName === '') {
            return [
                'success' => false,
                'fatal' => true,
                'message' => (string)__('缺少模块名'),
            ];
        }

        if (!$this->moduleCatalog->isDevEnvironment()) {
            return [
                'success' => false,
                'fatal' => true,
                'message' => (string)__('写回模块 CSV 仅允许在开发环境执行'),
            ];
        }

        $notify('start', [
            'module' => $moduleName,
            'stage' => 'sync',
            'node' => 'collect',
            'message' => (string)__('开始处理模块 %{1}：收集源语言词条…', [$moduleName]),
        ]);
        $notify('progress', [
            'module' => $moduleName,
            'stage' => 'sync',
            'node' => 'collect',
            'message' => (string)__('正在收集并回写源语言 CSV…'),
        ]);

        $sync = $this->moduleCatalog->syncModuleCollectedWords($moduleName);
        if (!$isAlive()) {
            return ['success' => false, 'message' => (string)__('连接已中断'), 'aborted' => true];
        }

        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $sourceWords = $this->moduleCatalog->countModuleSourceWords($moduleName, false);
        $notify('source', [
            'module' => $moduleName,
            'stage' => 'sync',
            'node' => 'collect',
            'locale' => $sourceLocale,
            'word_count' => $sourceWords,
            'added_source' => (int)($sync['added_source'] ?? 0),
            'collected' => (int)($sync['collected'] ?? 0),
            'message' => (string)__(
                '源语言 %{1} 已写回：词数 %{2}（本次新增 %{3}）',
                [
                    $sourceLocale,
                    (string)$sourceWords,
                    (string)($sync['added_source'] ?? 0),
                ],
            ),
        ]);
        $notify('progress', [
            'module' => $moduleName,
            'stage' => 'sync',
            'node' => 'collect',
            'message' => (string)($sync['message'] ?? __('源语言收集完成')),
        ]);

        $selected = [];
        foreach ($localeCodes as $localeCode) {
            $localeCode = $this->normalizeLocale((string)$localeCode);
            if ($localeCode !== '' && $localeCode !== $sourceLocale) {
                $selected[] = $localeCode;
            }
        }
        $selected = array_values(array_unique($selected));

        if ($selected === []) {
            foreach ($this->getModuleLocaleMatrix($moduleName) as $localeRow) {
                if (!empty($localeRow['has_work'])) {
                    $selected[] = (string)$localeRow['code'];
                }
            }
        }

        if ($selected === []) {
            $empty = [
                'success' => true,
                'message' => (string)($sync['message'] ?? __('模块 %{1} 没有待翻译或待写回的语言', [$moduleName])),
                'module' => $moduleName,
                'sync' => $sync,
                'source' => ['locale' => $sourceLocale, 'word_count' => $sourceWords],
                'locales' => [],
                'translated' => 0,
                'exported' => 0,
                'errors' => [],
            ];
            $notify('progress', [
                'module' => $moduleName,
                'stage' => 'done',
                'node' => 'finish',
                'message' => (string)$empty['message'],
            ]);

            return $empty;
        }

        $localesTotal = count($selected);
        $notify('progress', [
            'module' => $moduleName,
            'stage' => 'translate',
            'node' => 'translate',
            'locales_total' => $localesTotal,
            'message' => (string)__('开始按源语言翻译模块 %{1}（%{2} 种语言）', [
                $moduleName,
                (string)$localesTotal,
            ]),
        ]);

        $localeResults = [];
        $translatedTotal = 0;
        $exportedTotal = 0;
        $errors = [];
        $verifyFailed = 0;

        foreach ($selected as $localeIndex => $localeCode) {
            if (!$isAlive()) {
                return [
                    'success' => false,
                    'message' => (string)__('连接已中断'),
                    'aborted' => true,
                    'module' => $moduleName,
                    'sync' => $sync,
                    'translated' => $translatedTotal,
                    'exported' => $exportedTotal,
                    'locales' => $localeResults,
                    'errors' => $errors,
                ];
            }

            $localeName = $localeNames[$localeCode] ?? $localeCode;
            $localeOrdinal = $localeIndex + 1;
            $alignmentBefore = $this->moduleCatalog->summarizeModuleLocaleAlignment(
                $moduleName,
                $localeCode,
                false,
            );
            $gapBefore = (int)($alignmentBefore['gap'] ?? 0);
            $pendingExport = $this->moduleCatalog->countAiPendingExportPublic($moduleName, $localeCode);
            $translated = 0;
            $exported = 0;
            $remaining = $gapBefore;

            $notify('progress', [
                'module' => $moduleName,
                'stage' => 'translate',
                'node' => 'translate',
                'locale' => $localeCode,
                'locale_name' => $localeName,
                'locale_index' => $localeOrdinal,
                'locales_total' => $localesTotal,
                'gap' => $gapBefore,
                'translated_total' => 0,
                'remaining' => $gapBefore,
                'message' => (string)__('翻译阶段：%{1}（%{2}/%{3}）· 差额 %{4}', [
                    $localeName,
                    (string)$localeOrdinal,
                    (string)$localesTotal,
                    (string)$gapBefore,
                ]),
            ]);

            // 先把词典里已有译文补进 CSV 差额，避免「翻译 0 / 写回 0」空跑完成。
            if ($gapBefore > 0 || $pendingExport > 0) {
                $notify('progress', [
                    'module' => $moduleName,
                    'stage' => 'writeback',
                    'node' => 'writeback',
                    'locale' => $localeCode,
                    'locale_name' => $localeName,
                    'locale_index' => $localeOrdinal,
                    'locales_total' => $localesTotal,
                    'message' => (string)__('先写回词典已有译文：%{1}…', [$localeName]),
                ]);
                $prefill = $this->exportService->exportModuleCsvGaps($moduleName, $localeCode);
                $exported += (int)($prefill['exported'] ?? 0);
                foreach ((array)($prefill['errors'] ?? []) as $error) {
                    $errors[] = (string)$error;
                }
                $gapBefore = (int)($prefill['gap_after'] ?? $gapBefore);
                $remaining = $gapBefore;
            }

            for ($round = 0; $round < self::MAX_TRANSLATE_ROUNDS; $round++) {
                if (!$isAlive()) {
                    break 2;
                }
                if ($remaining <= 0) {
                    break;
                }
                // 源语言已在流水线开头收集，此处不再扫描模板。
                $batch = $this->moduleCatalog->translateModule($moduleName, $localeCode, 0, false);
                $batchTranslated = (int)($batch['translated'] ?? 0);
                $batchFailed = (int)($batch['failed'] ?? 0);
                $batchSkipped = (int)($batch['skipped'] ?? 0);
                // 仅整批硬失败（0 成功且确有失败）才中断该语言；单条乱码/跳过不拖死整批。
                if (empty($batch['success']) && $batchTranslated <= 0 && $batchFailed > 0) {
                    $errors[] = (string)($batch['message'] ?? __('模块 %{1}/%{2} 翻译失败', [$moduleName, $localeCode]));
                    $notify('progress', [
                        'module' => $moduleName,
                        'stage' => 'translate',
                        'node' => 'translate',
                        'locale' => $localeCode,
                        'locale_name' => $localeName,
                        'locale_index' => $localeOrdinal,
                        'locales_total' => $localesTotal,
                        'round' => $round + 1,
                        'message' => (string)($batch['message'] ?? __('翻译失败')),
                    ]);
                    break;
                }
                $translated += $batchTranslated;
                $remaining = (int)($batch['remaining'] ?? 0);
                $notify('progress', [
                    'module' => $moduleName,
                    'stage' => 'translate',
                    'node' => 'translate',
                    'locale' => $localeCode,
                    'locale_name' => $localeName,
                    'locale_index' => $localeOrdinal,
                    'locales_total' => $localesTotal,
                    'round' => $round + 1,
                    'batch_translated' => $batchTranslated,
                    'batch_skipped' => $batchSkipped,
                    'translated_total' => $translated,
                    'remaining' => $remaining,
                    'message' => (string)__(
                        '翻译 %{1} 第 %{2} 批：本批 %{3}，累计 %{4}，剩余 %{5}',
                        [
                            $localeName,
                            (string)($round + 1),
                            (string)$batchTranslated,
                            (string)$translated,
                            (string)$remaining,
                        ],
                    ),
                ]);
                if ($batchTranslated <= 0) {
                    break;
                }
            }

            if (!$isAlive()) {
                break;
            }

            $notify('progress', [
                'module' => $moduleName,
                'stage' => 'writeback',
                'node' => 'writeback',
                'locale' => $localeCode,
                'locale_name' => $localeName,
                'locale_index' => $localeOrdinal,
                'locales_total' => $localesTotal,
                'translated_total' => $translated,
                'remaining' => $remaining,
                'message' => (string)__('写回阶段：%{1}（%{2}/%{3}）…', [
                    $localeName,
                    (string)$localeOrdinal,
                    (string)$localesTotal,
                ]),
            ]);

            // 新 AI 译文增量写回 + 再扫一遍 CSV 差额，确保词典落到文件。
            $exportAi = $this->exportService->exportModuleTranslations($moduleName, $localeCode, true);
            $exported += (int)($exportAi['exported'] ?? 0);
            foreach ((array)($exportAi['errors'] ?? []) as $error) {
                $errors[] = (string)$error;
            }
            $exportGaps = $this->exportService->exportModuleCsvGaps($moduleName, $localeCode);
            $exported += (int)($exportGaps['exported'] ?? 0);
            foreach ((array)($exportGaps['errors'] ?? []) as $error) {
                $errors[] = (string)$error;
            }

            $alignmentAfter = $this->moduleCatalog->summarizeModuleLocaleAlignment(
                $moduleName,
                $localeCode,
                false,
            );
            $gapAfter = (int)($alignmentAfter['gap'] ?? (int)($exportGaps['gap_after'] ?? 0));
            $verified = $gapAfter === 0;
            if (!$verified) {
                $verifyFailed++;
                $errors[] = (string)__('校验失败：%{1} 仍有差额 %{2}', [$localeName, (string)$gapAfter]);
            }

            $notify('progress', [
                'module' => $moduleName,
                'stage' => 'verify',
                'node' => 'verify',
                'locale' => $localeCode,
                'locale_name' => $localeName,
                'locale_index' => $localeOrdinal,
                'locales_total' => $localesTotal,
                'gap' => $gapAfter,
                'verified' => $verified,
                'message' => $verified
                    ? (string)__('校验通过：%{1} 已与源语言对齐', [$localeName])
                    : (string)__('校验未通过：%{1} 剩余差额 %{2}', [$localeName, (string)$gapAfter]),
            ]);

            $translatedTotal += $translated;
            $exportedTotal += $exported;
            $status = $verified
                ? (($translated > 0 || $exported > 0) ? 'completed' : 'aligned')
                : 'failed';
            $localeRow = [
                'locale' => $localeCode,
                'locale_name' => $localeName,
                'translated' => $translated,
                'remaining' => $gapAfter,
                'gap' => $gapAfter,
                'exported' => $exported,
                'verified' => $verified,
                'status' => $status,
            ];
            $localeResults[] = $localeRow;
            $statusLabel = match ($status) {
                'aligned' => (string)__('已对齐'),
                'failed' => (string)__('未对齐'),
                default => (string)__('已完成'),
            };
            $notify('locale_done', array_merge($localeRow, [
                'module' => $moduleName,
                'stage' => 'verify',
                'node' => 'verify',
                'locale_index' => $localeOrdinal,
                'locales_total' => $localesTotal,
                'message' => (string)__(
                    '%{1} %{2}：翻译 %{3}，写回 %{4}，差额 %{5}',
                    [
                        $statusLabel,
                        $localeName,
                        (string)$translated,
                        (string)$exported,
                        (string)$gapAfter,
                    ],
                ),
            ]));
        }

        $finalOk = $errors === [] && $verifyFailed === 0;
        $final = [
            'success' => $finalOk,
            'message' => (string)__(
                '模块 %{1}：源语言词数 %{2}（新增 %{3}），翻译 %{4} 条，写回 %{5} 条，覆盖 %{6} 个语言%{7}。',
                [
                    $moduleName,
                    (string)$sourceWords,
                    (string)($sync['added_source'] ?? 0),
                    (string)$translatedTotal,
                    (string)$exportedTotal,
                    (string)count($localeResults),
                    $verifyFailed > 0
                        ? (string)__('，%{1} 个语言校验未通过', [(string)$verifyFailed])
                        : (string)__('，校验全部通过'),
                ],
            ),
            'module' => $moduleName,
            'sync' => $sync,
            'source' => ['locale' => $sourceLocale, 'word_count' => $sourceWords],
            'translated' => $translatedTotal,
            'exported' => $exportedTotal,
            'locales' => $localeResults,
            'errors' => $errors,
            'verify_failed' => $verifyFailed,
        ];
        $notify('progress', [
            'module' => $moduleName,
            'stage' => 'done',
            'node' => 'finish',
            'verified' => $finalOk,
            'message' => (string)$final['message'],
        ]);

        return $final;
    }

    /**
     * @return list<string>
     */
    private function targetLocales(): array
    {
        $sourceLocale = $this->normalizeLocale($this->aiConfig->getSourceLocale());
        $locales = [];
        foreach ($this->aiConfig->getInstalledActiveLocaleCodes() as $localeCode) {
            $localeCode = $this->normalizeLocale($localeCode);
            if ($localeCode !== '' && $localeCode !== $sourceLocale) {
                $locales[] = $localeCode;
            }
        }

        return $locales;
    }

    /**
     * @return \Generator<int, string>
     */
    private function iterI18nModules(string $search): \Generator
    {
        foreach (Env::getInstance()->getActiveModules() as $moduleMeta) {
            if (!is_array($moduleMeta)) {
                continue;
            }
            $moduleName = trim((string)($moduleMeta['name'] ?? ''));
            $basePath = rtrim((string)($moduleMeta['base_path'] ?? ''), "\\/");
            if ($moduleName === '' || $basePath === '' || !is_dir($basePath . DS . 'i18n')) {
                continue;
            }
            if ($search !== '' && stripos($moduleName, $search) === false) {
                continue;
            }

            yield $moduleName;
        }
    }

    private function normalizeLocale(string $locale): string
    {
        return trim(str_replace('-', '_', $locale));
    }
}
