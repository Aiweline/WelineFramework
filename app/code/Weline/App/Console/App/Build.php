<?php
declare(strict_types=1);

namespace Weline\App\Console\App;

use Weline\App\Service\Build\ConfigRenderer;
use Weline\App\Service\Build\VersionDrift;
use Weline\App\Service\Toolchain\DepInstaller;
use Weline\App\Service\Toolchain\EnvProbe;
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
        // 1) 环境检测 → 缺什么装什么（--no-install 可跳过自动安装；--check-only 只报告不改环境）
        $installer = new DepInstaller();
        $missing = $installer->missingFor($platform);
        if (isset($args['check-only'])) {
            $probe = (new EnvProbe())->probe();
            $plan = $missing === [] ? [] : $installer->plan();
            echo json_encode([
                'platform' => $platform,
                'site_website_id' => $websiteId,
                'ready' => $missing === [],
                'missing' => $missing,
                'install_plan' => array_map(static fn(array $s): string => $s['command'], $plan['steps'] ?? []),
                'flutter' => $probe['flutter'],
                'java' => $probe['java'],
                'android_sdk' => $probe['android_sdk'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
            return 0;
        }
        if ($missing !== []) {
            $this->printer->note(__('环境检测：缺少 %{1}', [implode(', ', $missing)]));
            if (isset($args['no-install'])) {
                $this->printer->error(__('缺少依赖且指定了 --no-install：php bin/w app:install-deps --yes'));
                return CommandResult::shortCircuit(1);
            }
            $result = $installer->install('auto', function (string $line): void {
                $this->printer->note($line);
            });
            foreach ($result['steps'] as $step) {
                if ($step['exit'] !== 0) {
                    $this->printer->error(__('安装 %{1} 失败（exit %{2}）：%{3}', [$step['target'], $step['exit'], $step['output']]));
                    break;
                }
            }
            $missing = $installer->missingFor($platform, $result['probe']);
            if ($missing !== []) {
                $this->printer->error(__('依赖仍缺失：%{1}；请手动执行 php bin/w app:install-deps --yes', [implode(', ', $missing)]));
                return CommandResult::shortCircuit(1);
            }
            $this->printer->success(__('环境检测通过：%{1}', [$platform]));
        }
        // JAVA_HOME 供本进程派生的 flutter/gradle 使用（brew keg-only 的 JDK 不自动上 PATH）
        $probe = (new EnvProbe())->probe();
        if (!empty($probe['java']['home']) && (getenv('JAVA_HOME') ?: '') === '') {
            putenv('JAVA_HOME=' . $probe['java']['home']);
            $_SERVER['JAVA_HOME'] = $probe['java']['home'];
        }
        if (!empty($probe['android_sdk']['path']) && (getenv('ANDROID_HOME') ?: '') === '') {
            putenv('ANDROID_HOME=' . $probe['android_sdk']['path']);
            putenv('ANDROID_SDK_ROOT=' . $probe['android_sdk']['path']);
            $_SERVER['ANDROID_HOME'] = $probe['android_sdk']['path'];
            $_SERVER['ANDROID_SDK_ROOT'] = $probe['android_sdk']['path'];
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
            /** @var Queue $queue */
            $queue = ObjectManager::getInstance(Queue::class);
            $queue->clear()->setData([
                Queue::schema_fields_type_id => 34,
                Queue::schema_fields_module => 'Weline_App',
                Queue::schema_fields_name => 'App 打包 ' . $websiteId . '-' . $platform,
                Queue::schema_fields_content => json_encode(['website_id' => $websiteId, 'platform' => $platform]),
                Queue::schema_fields_auto => 0,
                Queue::schema_fields_pid => 0,
                Queue::schema_fields_start_at => date('Y-m-d H:i:s'),
                Queue::schema_fields_finished => 0,
                Queue::schema_fields_result => '',
            ])->save();
            $queueId = (int)$queue->getId();
            $this->printer->note(__('已投递打包任务 queue_id=%{1}', [$queueId]));
            if (isset($args['async'])) {
                $this->printer->note('异步模式：php bin/w queue:run --id=' . $queueId);
                echo 'queue_id=' . $queueId, PHP_EOL;
                return 0;
            }
            // 同步出包：默认跑完再返回，exit code 跟随队列结果
            $lines = [];
            $exit = 0;
            exec('php bin/w queue:run --id=' . $queueId . ' 2>&1', $lines, $exit);
            echo implode(PHP_EOL, $lines), PHP_EOL;
            if ($exit !== 0) {
                $this->printer->error(__('打包失败（queue exit %{1}）', [$exit]));
                return CommandResult::shortCircuit($exit === 0 ? 1 : $exit);
            }
            echo 'queue_id=' . $queueId, PHP_EOL;
            return 0;
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
        return __('按站点渲染并打包 App：先做环境检测（缺 JDK/Android SDK 自动安装），再投递队列并同步出包；--dry-run 只打印配置，--check-only 只检测环境，--async 只投递，--no-install 禁止自动安装');
    }
}
