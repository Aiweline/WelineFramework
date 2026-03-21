<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

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
        // 移除命令名
        array_shift($args);

        $area = 'frontend'; // 默认生成 frontend 预览图
        $force = false;     // 默认不强制重新生成

        // 解析参数
        foreach ($args as $arg) {
            if ($arg === '-a' || $arg === '--area') {
                $area = 'both'; // 生成 frontend 和 backend
            } elseif ($arg === '-f' || $arg === '--force') {
                $force = true; // 强制重新生成
            } elseif ($arg === '-h' || $arg === '--help') {
                $this->printing->printing($this->help());
                return;
            }
        }

        $this->printing->setup(__('=== 批量生成主题预览图 ==='));
        $this->printing->printing("\n");

        // 获取所有主题
        $themes = $this->welineTheme->select()->fetch()->getItems();

        if (empty($themes)) {
            $this->printing->warning(__('系统中没有已安装的主题'));
            return;
        }

        $total = count($themes);
        $success = 0;
        $failed = 0;
        $messages = [];

        $this->printing->note(__('找到 %{1} 个主题，开始生成预览图...', [$total]));
        $this->printing->printing("\n");

        foreach ($themes as $theme) {
            $themeId = is_object($theme) ? $theme->getId() : ($theme['id'] ?? 0);
            $themeName = is_object($theme) ? $theme->getName() : ($theme['name'] ?? 'Unknown');

            if (!$themeId) {
                continue;
            }

            // 确定要生成的区域
            $areas = $area === 'both' ? ['frontend', 'backend'] : [$area];

            $themeSuccess = false;
            foreach ($areas as $areaItem) {
                try {
                    $this->printing->note(__('正在生成 [%{1}] %{2} 区域的预览图...', [$themeId, $areaItem]));

                    $imagePath = ThemePreviewGenerator::generatePreviewImage($theme, $areaItem, $force);

                    if ($imagePath) {
                        $relativePath = ThemePreviewGenerator::normalizePreviewRelativePath($imagePath);
                        
                        // 更新数据库
                        $themeObj = clone $this->welineTheme;
                        $themeObj->load($themeId);
                        if ($areaItem === 'backend') {
                            $themeObj->setBackendPreviewImage($relativePath);
                        } else {
                            $themeObj->setFrontendPreviewImage($relativePath)
                                ->setPreviewImage($relativePath);
                        }
                        $themeObj->save();

                        $this->printing->success(__('✓ %{1} [%{2}] 区域预览图生成成功', [$themeName, $areaItem]));
                        $themeSuccess = true;
                    } else {
                        $this->printing->warning(__('⚠ %{1} [%{2}] 区域预览图生成失败', [$themeName, $areaItem]));
                    }
                } catch (\Exception $e) {
                    $this->printing->error(__('✗ %{1} [%{2}] 区域预览图生成失败： %{3}', [
                        $themeName,
                        $areaItem,
                        $e->getMessage()
                    ]));
                    $messages[] = __('%{1} [%{2}]: %{3}', [$themeName, $areaItem, $e->getMessage()]);
                }
            }

            if ($themeSuccess) {
                $success++;
            } else {
                $failed++;
            }
        }

        $this->printing->printing("\n");
        $this->printing->note(__('=== 生成完成 ==='));
        $this->printing->printing(sprintf(
            __('总共: %{1} | 成功: %{2} | 失败: %{3}'),
            $total,
            $success,
            $failed
        ));
        $this->printing->printing("\n");

        if ($failed > 0 && !empty($messages)) {
            $this->printing->printing("\n");
            $this->printing->warning(__('失败详情：'));
            foreach ($messages as $msg) {
                $this->printing->printing('  - ' . $msg . "\n");
            }
        }

        // 提示用户刷新浏览器页面
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
            '批量生成所有主题的预览图',
            [
                '-a, --area' => '生成 frontend 和 backend 两个区域的预览图',
                '-f, --force' => '强制重新生成已存在的预览图',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '生成 frontend 预览图' => 'php bin/w theme:generate-previews',
                '生成两个区域预览图' => 'php bin/w theme:generate-previews --area',
                '强制重新生成' => 'php bin/w theme:generate-previews --force',
            ]
        );
    }
}
