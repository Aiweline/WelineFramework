<?php
declare(strict_types=1);

namespace Weline\App\Console\App;

use Weline\App\Model\AppBuild;
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
        $platformArg = \trim((string)($args['platform'] ?? ''));
        // 本机协助编译：站点地址（线上域名）在这里显式指定，避免打包进本机测试 Host
        $baseUrl = \trim((string)($args['base-url'] ?? $args['domain'] ?? ''));
        $dry = isset($args['dry-run']) || isset($args['d']);
        if (isset($args['all'])) {
            if ($websiteId || $platformArg !== '') {
                $this->printer->note('--all 优先，忽略 --website/--platform');
            }
            if ($baseUrl !== '') {
                $this->printer->note('--all 按各站点已绑定域名打包，忽略 --base-url');
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
            $platforms = $this->resolvePlatforms($websiteId, $platformArg);
        } catch (\InvalidArgumentException $e) {
            $this->printer->error($e->getMessage());
            return CommandResult::shortCircuit(1);
        }
        if ($platforms === []) {
            $this->printer->error(__('没有可打包的平台：站点 %{1} 的「App 打包配置」未启用任何平台', [$websiteId]));
            return CommandResult::shortCircuit(1);
        }

        // 只渲染配置（不装依赖、不打包）：线上没有编译环境时也能核对站点地址与平台
        if ($dry) {
            $configs = [];
            foreach ($platforms as $platform) {
                try {
                    $configs[] = (new ConfigRenderer())->render($websiteId, $platform, $baseUrl !== '' ? $baseUrl : null);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $this->printer->error($e->getMessage());
                    return CommandResult::shortCircuit(1);
                }
            }
            echo json_encode(count($configs) === 1 ? $configs[0] : $configs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
            return 0;
        }

        // 逐平台构建：单个平台失败不阻断其余平台（避免又变成「只发 Android」）
        $failed = [];
        $built = [];
        foreach ($platforms as $platform) {
            if (count($platforms) > 1) {
                $this->printer->note(__('平台 %{1}（%{2}/%{3}）', [$platform, count($built) + count($failed) + 1, count($platforms)]));
            }
            if ($this->buildPlatform($websiteId, $platform, $baseUrl, $args) !== 0) {
                $failed[] = $platform;
                continue;
            }
            $built[] = $platform;
        }

        $checkOnly = isset($args['check-only']);
        if (count($platforms) > 1) {
            echo ($checkOnly ? 'platforms_ready=' : 'platforms_built=') . implode(',', $built), PHP_EOL;
            if ($failed !== []) {
                echo 'platforms_failed=' . implode(',', $failed), PHP_EOL;
            }
        }
        if ($failed !== []) {
            $this->printer->error($checkOnly
                ? __('环境未就绪平台：%{1}', [implode(', ', $failed)])
                : __('打包失败平台：%{1}', [implode(', ', $failed)]));
            return CommandResult::shortCircuit(1);
        }
        return 0;
    }

    /**
     * 解析 `--platform`：空 / all = 站点打包配置里的全部可用平台；支持逗号分隔子集。
     *
     * @return list<string>
     */
    private function resolvePlatforms(int $websiteId, string $platformArg): array
    {
        if ($platformArg === '' || \strtolower($platformArg) === 'all') {
            return $this->sitePlatforms($websiteId);
        }
        $platforms = [];
        foreach (\explode(',', $platformArg) as $platform) {
            $platform = \strtolower(\trim($platform));
            if ($platform === '') {
                continue;
            }
            if (!\in_array($platform, ConfigRenderer::PLATFORMS, true)) {
                throw new \InvalidArgumentException(__('非法平台：%{1}（允许 android|ios|web|all）', [$platform]));
            }
            if (!\in_array($platform, $platforms, true)) {
                $platforms[] = $platform;
            }
        }
        return $platforms;
    }

    /**
     * 站点配置（后台「App 打包配置」）里的可用平台，保持配置顺序。
     *
     * @return list<string>
     */
    private function sitePlatforms(int $websiteId): array
    {
        try {
            /** @var AppBuild $model */
            $model = ObjectManager::getInstance(AppBuild::class);
            $found = $model->clear()
                ->where($model::schema_fields_website_id, $websiteId)
                ->find()
                ->fetch();
            if ($found && $found->getData('id')) {
                $configured = \json_decode((string)$found->getData('platforms'), true) ?: [];
                return \array_values(\array_filter(
                    $configured,
                    static fn($platform): bool => \in_array($platform, ConfigRenderer::PLATFORMS, true)
                ));
            }
        } catch (\Throwable) {
            // 读配置失败时交给 ConfigRenderer 的默认口径
        }
        return ['android', 'web'];
    }

    private function buildPlatform(int $websiteId, string $platform, string $baseUrl, array $args): int
    {
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
                return 1;
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
                return 1;
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
            $cfg = (new ConfigRenderer())->render($websiteId, $platform, $baseUrl !== '' ? $baseUrl : null);
        } catch (\InvalidArgumentException $e) {
            $this->printer->error($e->getMessage());
            return 1;
        } catch (\RuntimeException $e) {
            $this->printer->error($e->getMessage());
            return str_contains($e->getMessage(), '云编译') ? 2 : 1;
        }
        $this->printer->note(__('站点地址：%{1}', [$cfg['base_url'] !== '' ? $cfg['base_url'] : '（空：将拒绝构建）']));
        /** @var Queue $queue */
        $queue = ObjectManager::getInstance(Queue::class);
        $queue->clear()->setData([
            Queue::schema_fields_type_id => 34,
            Queue::schema_fields_module => 'Weline_App',
            Queue::schema_fields_name => 'App 打包 ' . $websiteId . '-' . $platform,
            Queue::schema_fields_content => json_encode([
                'website_id' => $websiteId,
                'platform' => $platform,
                'base_url' => $baseUrl,
            ]),
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
        // 队列进程 exit=0 不代表出包成功（配置/编译失败只写队列结果），以登记结果为准
        $result = '';
        try {
            /** @var Queue $finished */
            $finished = ObjectManager::getInstance(Queue::class);
            $finished->clear()->load($queueId);
            $result = (string)$finished->getData(Queue::schema_fields_result);
        } catch (\Throwable) {
            $result = '';
        }
        if ($exit !== 0 || !str_starts_with($result, 'released:')) {
            $this->printer->error(__('打包失败：%{1}', [$result !== '' ? $result : ('queue exit ' . $exit)]));
            return $exit !== 0 ? $exit : 1;
        }
        echo 'queue_id=' . $queueId, PHP_EOL;
        $buildId = substr($result, strlen('released:'));
        if ($buildId !== '') {
            echo 'build_id=' . $buildId, PHP_EOL;
            echo 'artifact=' . 'pub/static/weline-app/' . $buildId, PHP_EOL;
            $this->printer->note(__('产物已落本机 pub/static/weline-app/%{1}；上传到目标站后在目标机执行：php bin/w app:artifact:import --path=static/weline-app/%{1}', [$buildId]));
        }
        return 0;
    }

    public function tip(): string
    {
        return __('按站点渲染并打包 App：--base-url=https://线上域名 指定站点地址（本机构建必须显式给线上域名）；--website 选站点，--platform 省略/取 all 时按站点打包配置的全部可用平台（android/ios/web）逐个出包，也可用 android,web 指定子集；--dry-run 只打印配置，--check-only 只检测环境，--async 只投递，--no-install 禁止自动安装');
    }
}
