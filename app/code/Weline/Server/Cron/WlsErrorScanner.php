<?php
declare(strict_types=1);

namespace Weline\Server\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Server\Console\Server\WlsErrorScanner as WlsErrorScannerCommand;

/**
 * WLS 错误扫描定时任务
 *
 * 每分钟扫描 var/log/wls 下的关键错误日志，检测 Fatal/ParseError/TypeError 等，
 * 去重后写入 var/log/wls/wls_monitor.log 并触发 CursorSupervisor 自动修复任务。
 */
class WlsErrorScanner implements CronTaskInterface
{
    public function name(): string
    {
        return 'wls_error_scanner';
    }

    public function execute_name(): string
    {
        return __('WLS错误扫描');
    }

    public function tip(): string
    {
        return __('扫描 WLS 日志中的 Fatal/ParseError/TypeError 等关键错误，生成修复任务');
    }

    public function cron_time(): string
    {
        return '* * * * *';
    }

    public function locked(): bool
    {
        return false;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 5;
    }

    public function execute(): string
    {
        $scanner = new WlsErrorScannerCommand();
        $scanner->execute(['v' => false], []);
        return '';
    }
}
