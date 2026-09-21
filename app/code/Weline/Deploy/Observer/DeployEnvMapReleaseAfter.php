<?php

declare(strict_types=1);

namespace Weline\Deploy\Observer;

use Weline\Deploy\Service\DeployEnvMapService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 发布完成后：若项目根存在 deploy.env-map.php 则按档位扭转 SystemConfig。
 */
class DeployEnvMapReleaseAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $deployRoot = '';
        if (is_object($data) && method_exists($data, 'getData')) {
            $deployRoot = (string)$data->getData('deploy_root');
        }

        try {
            /** @var DeployEnvMapService $service */
            $service = ObjectManager::getInstance(DeployEnvMapService::class);
            $result = $service->apply([
                'deploy_root' => $deployRoot,
                'dry_run' => false,
            ]);
            $status = (string)($result['status'] ?? '');
            if ($status === 'failed') {
                w_log_error('deploy.env-map apply failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            } elseif ($status === 'applied') {
                w_log_info('deploy.env-map applied: ' . json_encode([
                    'target_level' => $result['target_level'] ?? '',
                    'changes' => count((array)($result['changes'] ?? [])),
                    'map_path' => $result['map_path'] ?? '',
                ], JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable $e) {
            w_log_error('deploy.env-map observer error: ' . $e->getMessage());
        }
    }
}
