<?php

declare(strict_types=1);

namespace Weline\Cron\Observer;

use Weline\Cron\Console\Cron\Install as CronInstallCommand;
use Weline\Cron\Console\Cron\Task\Collect as CronTaskCollectCommand;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;

class SetupUpgradeSchedulerMaintenance implements ObserverInterface
{
    private static bool $hasMaintained = false;

    public function __construct(
        private Printing $printing
    ) {
    }

    public function execute(Event &$event): void
    {
        if (self::$hasMaintained) {
            return;
        }
        self::$hasMaintained = true;

        RegistryProgress::run(function (): void {
            RegistryProgress::section('setup:upgrade cron maintenance');

            $this->runStep('cron:task:collect', function (): void {
                /** @var CronTaskCollectCommand $command */
                $command = ObjectManager::getInstance(CronTaskCollectCommand::class);
                $command->execute([], []);
            });

            $this->runStep('cron:install', function (): void {
                /** @var CronInstallCommand $command */
                $command = ObjectManager::getInstance(CronInstallCommand::class);
                $command->execute([], ['module' => 'Weline_Cron']);
            });
        });
    }

    private function runStep(string $name, callable $callback): void
    {
        try {
            RegistryProgress::log($name . ' started');
            $callback();
            RegistryProgress::log($name . ' finished');
        } catch (\Throwable $throwable) {
            w_log_warning('setup:upgrade cron maintenance failed: ' . $name, [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ], 'setup/scheduler_maintenance.log');
            RegistryProgress::log($name . ' failed: ' . $throwable->getMessage());
            $this->printing->warning(__('系统定时任务维护失败：%{1}，已跳过，不影响本次系统更新。错误：%{2}', [
                $name,
                $throwable->getMessage(),
            ]));
        }
    }
}
