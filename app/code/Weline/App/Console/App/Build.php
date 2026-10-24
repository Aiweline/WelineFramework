<?php
declare(strict_types=1);

namespace Weline\App\Console\App;

use Weline\App\Service\Build\ConfigRenderer;
use Weline\App\Service\Build\VersionDrift;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandResult;

class Build extends CommandAbstract
{
    public const ALIASES = ['app:build'];

    public function execute(array $args = [], array $data = [])
    {
        $websiteId = (int)($args['website'] ?? 0);
        $platform = (string)($args['platform'] ?? '');
        $dry = isset($args['dry-run']) || isset($args['d']);
        if (isset($args['all'])) {
            if ($websiteId || $platform) {
                $this->printer->note('--all 优先，忽略 --website/--platform');
            }
            $targets = [];
            foreach ((new VersionDrift())->enabledBuildTargets() as $website) {
                foreach ($website['platforms'] as $platform) {
                    $targets[] = ['website_id' => $website['website_id'], 'platform' => $platform];
                }
            }
            if ($dry) {
                echo json_encode($targets, JSON_UNESCAPED_UNICODE), PHP_EOL;
                return 0;
            }
            $count = 0;
            foreach ($targets as $target) {
                /** @var Queue $queue */
                $queue = ObjectManager::getInstance(Queue::class);
                $queue->clear()->setData([
                    Queue::schema_fields_type_id => 34,
                    Queue::schema_fields_module => 'Weline_App',
                    Queue::schema_fields_name => 'App 批量打包 ' . $target['website_id'] . '-' . $target['platform'],
                    Queue::schema_fields_content => json_encode($target),
                    Queue::schema_fields_auto => 0,
                    Queue::schema_fields_pid => 0,
                    Queue::schema_fields_start_at => date('Y-m-d H:i:s'),
                    Queue::schema_fields_finished => 0,
                    Queue::schema_fields_result => '',
                ])->save();
                $count++;
            }
            echo 'queued=' . $count, PHP_EOL;
            return 0;
        }
        try {
            $cfg = (new ConfigRenderer())->render($websiteId, $platform);
        } catch (\InvalidArgumentException $e) {
            $this->printer->error($e->getMessage());
            return CommandResult::shortCircuit(1);
        } catch (\RuntimeException $e) {
            $this->printer->error($e->getMessage());
            return CommandResult::shortCircuit(str_contains($e->getMessage(), '云编译') ? 2 : 1);
        }
        if (!$dry) {
            $this->printer->error('实跑经 Queue 执行（V1 契约 §4）：php bin/w queue:run 或后台任务投递后出包；CLI 仅支持 --dry-run');
            return CommandResult::shortCircuit(2);
        }
        $artifactRoot = (defined('VAR_DIR') ? VAR_DIR : BP . 'var') . DS . 'pub' . DS . 'app';
        if (!is_dir($artifactRoot)) {
            @mkdir($artifactRoot, 0755, true);
        }
        echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        return 0;
    }

    public function tip(): string
    {
        return __('按站点渲染并打包 App（--dry-run 查看渲染配置；实跑经队列）');
    }
}
