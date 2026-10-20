<?php
declare(strict_types=1);

namespace Weline\Server\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Capacity\CapacityAdvisorConfigService;
use Weline\Server\Service\Capacity\CapacitySampleStore;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Pulls the Master's in-memory capacity sample and appends the history file.
 * File IO stays in this process so the Master control loop does not touch disk or the database.
 */
class CapacitySampleFlush implements CronTaskInterface
{
    public function name(): string
    {
        return 'server_capacity_sample_flush';
    }

    public function execute_name(): string
    {
        return '容量采样落盘';
    }

    public function tip(): string
    {
        return __('从 Master 读取最新容量采样并追加到历史文件');
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
        return 10;
    }

    public function execute(): string
    {
        try {
            /** @var ServerInstanceManager $instances */
            $instances = ObjectManager::getInstance(ServerInstanceManager::class);
            /** @var IpcControlGateway $gateway */
            $gateway = ObjectManager::getInstance(IpcControlGateway::class);
            $store = new CapacitySampleStore();
            $retainDays = (int)(new CapacityAdvisorConfigService())->get()['sample_retain_days'];
            $appended = 0;
            foreach ($instances->listPersistedInstanceNames() as $name) {
                if (!$instances->isInstanceIpcControllable($name)) {
                    continue;
                }
                $pull = $gateway->capacitySamplePull($name, 0.1);
                $sample = $pull['data']['sample'] ?? null;
                if (($pull['success'] ?? false) !== true || !\is_array($sample) || $sample === []) {
                    continue;
                }
                if ($store->appendIfNew($name, $sample)) {
                    $appended++;
                }
            }
            $removed = $store->cleanup($retainDays);

            return (string)__('容量采样已追加 %{1} 条，清理 %{2} 条', [(string)$appended, (string)$removed]);
        } catch (\Throwable $e) {
            return (string)__('容量采样落盘异常: %{1}', [$e->getMessage()]);
        }
    }
}
