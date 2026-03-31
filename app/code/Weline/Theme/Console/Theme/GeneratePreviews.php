<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme;

use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemePreviewGenerator;

/**
 * 批量生成主题预览图命令
 */
class GeneratePreviews extends AbstractConsole
{
    public function __construct(
        WelineTheme $welineTheme,
        Printing $printing
    ) {
        parent::__construct($welineTheme, $printing);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        array_shift($args);

        $area = 'frontend';
        $force = false;
        $concurrency = ThemePreviewGenerator::DEFAULT_CONCURRENCY;

        foreach ($args as $arg) {
            if ($arg === '-a' || $arg === '--area') {
                $area = 'both';
            } elseif ($arg === '-f' || $arg === '--force') {
                $force = true;
            } elseif ($arg === '-c' || $arg === '--concurrency') {
                $concurrency = null;
            } elseif ($arg === '-h' || $arg === '--help') {
                $this->printing->printing($this->help());
                return;
            } elseif ($concurrency === null && is_numeric($arg)) {
                $concurrency = (int)$arg;
            }
        }

        if ($concurrency === null) {
            $concurrency = ThemePreviewGenerator::DEFAULT_CONCURRENCY;
        }

        $this->printing->setup(__('=== 批量生成主题预览图 ==='));
        $this->printing->printing("\n");
        $this->printing->note(__('并发数量: %{1}', [$concurrency]));

        $themes = $this->welineTheme->select()->fetch()->getItems();

        if (empty($themes)) {
            $this->printing->warning(__('系统中没有已安装的主题'));
            return;
        }

        // 构建任务列表
        $tasks = [];
        $taskCount = 0;
        foreach ($themes as $theme) {
            $themeId = is_object($theme) ? $theme->getId() : ($theme['id'] ?? 0);
            if (!$themeId) {
                continue;
            }

            $areas = $area === 'both' ? ['frontend', 'backend'] : [$area];
            foreach ($areas as $areaItem) {
                $tasks[] = [
                    'theme' => $theme,
                    'area' => $areaItem,
                    'force' => $force,
                ];
                $taskCount++;
            }
        }

        if (empty($tasks)) {
            $this->printing->warning(__('没有需要生成的任务'));
            return;
        }

        $this->printing->note(__('找到 %{1} 个主题，%{2} 个任务，开始并发生成预览图...', [count($themes), count($tasks)]));
        $this->printing->printing("\n");

        $startTime = microtime(true);

        // 使用并发批量生成
        $result = ThemePreviewGenerator::generatePreviewImagesBatch(
            $tasks,
            $concurrency,
            function(int $completed, int $total, string $message) {
                $this->printing->note("[{$completed}/{$total}] {$message}");
            }
        );

        $elapsed = round(microtime(true) - $startTime, 2);

        // 更新数据库
        $successCount = 0;
        $failedCount = 0;
        foreach ($result['results'] as $item) {
            if ($item['success']) {
                try {
                    $themeObj = clone $this->welineTheme;
                    $themeObj->load($item['theme_id']);
                    if ($item['area'] === 'backend') {
                        $themeObj->setBackendPreviewImage($item['path']);
                    } else {
                        $themeObj->setFrontendPreviewImage($item['path'])
                            ->setPreviewImage($item['path']);
                    }
                    $themeObj->save();
                    $successCount++;
                } catch (\Exception $e) {
                    $this->printing->warning(__('保存预览图路径失败: %{1}', [$e->getMessage()]));
                }
            } else {
                $failedCount++;
            }
        }

        $this->printing->printing("\n");
        $this->printing->note(__('=== 生成完成 ==='));
        $this->printing->printing(sprintf(
            __('耗时: %{1}秒 | 成功: %{2} | 失败: %{3}'),
            $elapsed,
            $successCount,
            $failedCount
        ));
        $this->printing->printing("\n");

        if ($failedCount > 0) {
            $this->printing->warning(__('失败详情：'));
            foreach ($result['results'] as $item) {
                if (!$item['success']) {
                    $this->printing->printing('  - ' . __('主题ID: %{1} [%{2}]: %{3}', [
                        $item['theme_id'],
                        $item['area'],
                        $item['error'] ?? __('未知错误')
                    ]) . "\n");
                }
            }
        }

        $this->printing->printing("\n");
        $this->printing->note(__('提示：请在后台主题列表页面刷新浏览器页面以查看新生成的预览图'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('批量生成所有主题的预览图');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:generate-previews',
            '批量生成所有主题的预览图（支持并发）',
            [
                '-a, --area' => '生成 frontend 和 backend 两个区域的预览图',
                '-f, --force' => '强制重新生成已存在的预览图',
                '-c, --concurrency <num>' => '并发数量（默认: ' . ThemePreviewGenerator::DEFAULT_CONCURRENCY . '，建议 2-4）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '生成 frontend 预览图' => 'php bin/w theme:generate-previews',
                '生成两个区域预览图' => 'php bin/w theme:generate-previews --area',
                '强制重新生成' => 'php bin/w theme:generate-previews --force',
                '并发生成（4个并发）' => 'php bin/w theme:generate-previews -c 4',
            ]
        );
    }
}
