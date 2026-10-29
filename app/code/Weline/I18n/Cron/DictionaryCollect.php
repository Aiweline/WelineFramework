<?php
declare(strict_types=1);

namespace Weline\I18n\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Cron\CronTaskPreflightInterface;
use Weline\Framework\Console\Console\I18n\Collect as I18nCollectCommand;
use Weline\I18n\Service\I18nCollectFingerprint;

/**
 * 定时词典收集：按模块增量复用 `php bin/w i18n:collect --module=<模块> --no-rewrite-csv`
 * （Framework Collect），不自写扫描逻辑。只有 {@see I18nCollectFingerprint} 判定指纹变化的模块才会被重扫，
 * 无变化时前置检查直接跳过派发，不再每分钟付全量收集的成本。
 *
 * **职责边界（HARD）**：本 cron 只做「源码扫描 → 词典编译写入 DB」，**不回写模块 `i18n/*.csv`**。
 * 词条落盘到 CSV 由开发者在国际化后台（词典收集 SSE / `postCollectWordsStream`）手动触发，
 * 定时链路不参与，避免无人值守的批量改写污染模块语言包。
 * 编译链路经 dictionary_compile / dictionary_register 写入 DB 后，由 i18n_ai_translation cron 入队翻译。
 */
class DictionaryCollect implements CronTaskInterface, CronTaskPreflightInterface
{
    public function __construct(
        private readonly I18nCollectCommand $collectCommand,
        private readonly I18nCollectFingerprint $fingerprint,
    ) {
    }

    public function name(): string
    {
        return 'I18n 词典定时收集';
    }

    public function execute_name(): string
    {
        return 'i18n_dictionary_collect';
    }

    public function tip(): string
    {
        return '每小时按模块增量收集：只对源码指纹变化的模块复用 i18n:collect --module --no-rewrite-csv；'
            . '只写词典库、不回写模块 i18n/*.csv（CSV 由开发者在国际化后台手动收集）；'
            . '无变化直接跳过；单模块失败不影响其它模块。';
    }

    public function cron_time(): string
    {
        // Full collect is heavier than AI enqueue (*/5); run hourly at :10 to stagger.
        return '10 * * * *';
    }

    /**
     * 无模块指纹变化时不派生收集子进程（只做 stat 级目录遍历，不读文件内容）。
     */
    public function hasPendingWork(): bool
    {
        return $this->fingerprint->changedModules() !== [];
    }

    public function execute(): string
    {
        $startTime = microtime(true);
        $prevMem = (string)\ini_get('memory_limit');
        $raw = \trim($prevMem);
        if ($raw !== '' && $raw !== '-1') {
            $unit = \strtoupper(\substr($raw, -1));
            $number = (float)$raw;
            $bytes = match ($unit) {
                'G' => (int)($number * 1024 * 1024 * 1024),
                'M' => (int)($number * 1024 * 1024),
                'K' => (int)($number * 1024),
                default => (int)$number,
            };
            if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
                @\ini_set('memory_limit', '512M');
            }
        }

        try {
            // 增量入口：只为指纹变化的模块调 i18n:collect --module，避免全量重扫
            $modules = $this->fingerprint->changedModules();
            if ($modules === []) {
                return 'skip:no_changed_modules';
            }

            $succeeded = [];
            $failed = [];
            foreach ($modules as $module) {
                // 同 CLI：php bin/w i18n:collect --module=<模块> --no-rewrite-csv
                // 定时任务只做「源码扫描 → 词典编译入 DB」，不回写模块 i18n/*.csv：
                // 词条写入 CSV 由开发者在国际化后台（词典收集 SSE）手动触发，cron 不参与。
                $exitCode = $this->collectCommand->execute(['module' => $module, '--no-rewrite-csv'], []);
                if ((int)$exitCode === 75) {
                    // 单飞锁：已有收集进行中，记下已成功的模块后立即停止本轮
                    $this->fingerprint->remember($succeeded);
                    $duration = round(microtime(true) - $startTime, 2);

                    return 'I18n 词典收集跳过：已有收集进行中（单飞锁），耗时 ' . $duration . ' 秒';
                }
                if ((int)$exitCode === 0) {
                    $succeeded[] = (string)$module;
                    continue;
                }
                // 单模块失败不拖累其它模块，指纹不记录 → 下一轮重试
                $failed[(string)$module] = (int)$exitCode;
            }

            $this->fingerprint->remember($succeeded);
            $duration = round(microtime(true) - $startTime, 2);
            $summary = 'I18n 词典收集完成（按模块增量）：待收集 ' . \count($modules) . ' 个模块，成功 '
                . \count($succeeded) . ' 个，耗时 ' . $duration . ' 秒';
            if ($failed !== []) {
                $details = [];
                foreach ($failed as $module => $exitCode) {
                    $details[] = $module . '(exit=' . $exitCode . ')';
                }
                $summary .= '；失败 ' . \count($failed) . ' 个：' . \implode('、', \array_slice($details, 0, 10));
            }

            return $summary;
        } catch (\Throwable $throwable) {
            return 'I18n 词典收集异常: ' . $throwable->getMessage();
        } finally {
            if ($prevMem !== '') {
                @\ini_set('memory_limit', $prevMem);
            }
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        // Collect can run long on large codebases.
        return 120;
    }
}
