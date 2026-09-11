<?php
declare(strict_types=1);

namespace Weline\I18n\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Console\Console\I18n\Collect as I18nCollectCommand;

/**
 * 定时词典收集：直接复用 `php bin/w i18n:collect`（Framework Collect），不自写扫描逻辑。
 * 编译链路经 dictionary_compile / dictionary_register 写入 DB 后，由 i18n_ai_translation cron 入队翻译。
 */
class DictionaryCollect implements CronTaskInterface
{
    public function __construct(
        private readonly I18nCollectCommand $collectCommand,
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
        return '每小时复用 i18n:collect：编译模块 CSV 语言包并清理翻译缓存；新词入 DB 后由 AI 翻译 cron 入队。';
    }

    public function cron_time(): string
    {
        // Full collect is heavier than AI enqueue (*/5); run hourly at :10 to stagger.
        return '10 * * * *';
    }

    public function execute(): string
    {
        $startTime = microtime(true);

        try {
            // Same entry as CLI: php bin/w i18n:collect（全部模块）
            $this->collectCommand->execute([], []);
            $duration = round(microtime(true) - $startTime, 2);

            return 'I18n 词典收集完成（复用 i18n:collect），耗时 ' . $duration . ' 秒';
        } catch (\Throwable $throwable) {
            return 'I18n 词典收集异常: ' . $throwable->getMessage();
        }
    }

    public function unlock_timeout(int $minute = 30): int
    {
        // Collect can run long on large codebases.
        return 120;
    }
}
