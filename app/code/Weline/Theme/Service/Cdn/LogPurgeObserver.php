<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 把观测事件写成一行 JSON（默认走 `w_log`，可注入写入口）。
 *
 * 结构化是刻意的：一条事件一个 JSON，便于 `grep '"[stage]":"purge"'` 或接日志系统，
 * 不需要再解析人类可读文案。
 */
final class LogPurgeObserver implements PurgeObserverInterface
{
    /** @param callable(string):void|null $writer */
    public function __construct(
        private readonly mixed $writer = null,
        private readonly string $channel = 'theme/cdn_purge',
    ) {
    }

    public function record(array $event): void
    {
        $line = (string)\json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $writer = $this->writer;
        if (\is_callable($writer)) {
            $writer($line);

            return;
        }
        if (\function_exists('w_log')) {
            \w_log($line, $this->channel);
        }
    }
}
