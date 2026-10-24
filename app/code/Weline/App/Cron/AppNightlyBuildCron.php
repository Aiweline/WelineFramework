<?php
declare(strict_types=1);

namespace Weline\App\Cron;

use Throwable;
use Weline\App\Service\Build\VersionDrift;
use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;

class AppNightlyBuildCron implements CronTaskInterface
{
    public function name(): string
    {
        return 'App 闲时重打包';
    }

    public function execute_name(): string
    {
        return 'app_nightly_build';
    }

    public function tip(): string
    {
        return __('检测系统/模块版本漂移，自动投递 Weline App 打包任务（闲时窗口）');
    }

    public function cron_time(): string
    {
        return '0 3 * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 60;
    }

    public function execute(): string
    {
        try {
            $drift = (new VersionDrift())->drifted();
            if (!$drift['drift']) {
                return '无版本漂移，跳过';
            }
            $count = 0;
            $skipped = [];
            foreach ((new VersionDrift())->websitesToBuild() as $website) {
                foreach ($website['platforms'] as $platform) {
                    $probe = (new \Weline\App\Service\Toolchain\EnvProbe())->probe();
                    $ready = match ($platform) {
                        'web' => !empty($probe['flutter']['installed']),
                        'android' => !empty($probe['flutter']['installed']) && !empty($probe['java']['installed']) && !empty($probe['android_sdk']['installed']),
                        'ios' => false, // iOS 走平台云编译，cron 不投递
                        default => false,
                    };
                    if (!$ready) {
                        $skipped[] = $website['website_id'] . '-' . $platform;
                        continue;
                    }
                    /** @var Queue $queue */
                    $queue = ObjectManager::getInstance(Queue::class);
                    $queue->clear()->setData([
                        Queue::schema_fields_type_id => 34,
                        Queue::schema_fields_module => 'Weline_App',
                        Queue::schema_fields_name => 'App 自动重打包 ' . $website['website_id'] . '-' . $platform,
                        Queue::schema_fields_content => json_encode([
                            'website_id' => $website['website_id'],
                            'platform' => $platform,
                        ]),
                        Queue::schema_fields_auto => 1,
                        Queue::schema_fields_pid => 0,
                        Queue::schema_fields_start_at => date('Y-m-d H:i:s'),
                        Queue::schema_fields_finished => 0,
                        Queue::schema_fields_result => '',
                    ])->save();
                    $count++;
                }
            }
            $message = '已投递 ' . $count . ' 个打包任务（drift: ' . ($drift['latest_artifact_version'] ?? 'null') . ' → ' . $drift['current_version'] . '）';
            if ($skipped) {
                $message .= ' 跳过(' . count($skipped) . ')：' . implode(', ', $skipped);
            }
            return $message;
        } catch (Throwable $e) {
            w_log_error('App cron 投递失败: ' . $e->getMessage());
            return '投递失败：' . $e->getMessage();
        }
    }
}
