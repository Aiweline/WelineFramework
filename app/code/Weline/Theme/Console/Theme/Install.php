<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Console\Theme;

use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Register\Register;
use Weline\Theme\Register\Installer;
use Weline\Theme\Register\TypeInterface;

class Install extends AbstractConsole
{
    /**
     * @var System
     */
    private System $system;

    public function __construct(
        \Weline\Theme\Model\WelineTheme $welineTheme,
        \Weline\Framework\Output\Cli\Printing $printing,
        System $system
    ) {
        parent::__construct($welineTheme, $printing);
        $this->system = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 移除命令名（第一个参数）
        array_shift($args);

        $themeName = '';
        $autoActivate = false;

        // 解析参数
        foreach ($args as $key => $arg) {
            if (!is_numeric($key)) {
                if ($key === 'theme' || $key === 't') {
                    $themeName = $arg;
                } elseif ($key === 'activate' || $key === 'a') {
                    $autoActivate = true;
                }
                continue;
            }

            switch ($arg) {
                case '-t':
                case '--theme':
                    if (!isset($args[$key + 1])) {
                        throw new \Weline\Framework\Console\ConsoleException(__('设置了 -t/--theme 参数，但没有提供主题名称！'));
                    }
                    $themeName = $args[$key + 1];
                    break;
                case '-a':
                case '--activate':
                    $autoActivate = true;
                    break;
                case '-h':
                case '--help':
                    $this->printing->printing($this->help());
                    return;
            }
        }

        // 如果没有指定主题名，显示选择菜单
        if (empty($themeName)) {
            $themeName = $this->selectThemeToInstall();
            if (empty($themeName)) {
                $this->printing->note(__('已取消操作'));
                return;
            }
        }

        // 安装主题
        $this->installTheme($themeName, $autoActivate);
    }

    /**
     * 选择要安装的主题
     */
    private function selectThemeToInstall(): ?string
    {
        $this->printing->setup(__('=== 主题安装 ==='));
        $this->printing->printing("\n");

        // 扫描主题目录
        $themes = $this->scanAvailableThemes();

        if (empty($themes)) {
            $this->printing->warning(__('未找到可安装的主题'));
            $this->printing->note(__('主题应该位于：%{1}', [Env::path_CODE_DESIGN]));
            return null;
        }

        // 获取已安装的主题
        $installedThemes = [];
        $dbThemes = $this->welineTheme->clearData()->select()->fetch()->getItems();
        foreach ($dbThemes as $theme) {
            $installedThemes[$theme['name']] = true;
        }

        // 显示可安装的主题列表
        $this->printing->note(__('找到以下可安装的主题：'));
        $index = 1;
        $themeList = [];
        foreach ($themes as $themeName => $themeInfo) {
            $status = '';
            if (isset($installedThemes[$themeName])) {
                $status = ' ' . $this->colorizeText(__('[已安装]'), 'Yellow');
            } else {
                $status = ' ' . $this->colorizeText(__('[未安装]'), 'Green');
            }

            $themeNameBold = $this->boldText($themeName);
            $relativePath = str_replace(BP, '', $themeInfo['path']);
            $relativePath = ltrim(str_replace('\\', DS, $relativePath), DS);

            // 路径着色
            if (strpos($relativePath, 'app' . DS . 'design') === 0) {
                $coloredPath = $this->colorizeText($relativePath, 'Blue');
            } else {
                $coloredPath = $relativePath;
            }

            $this->printing->printing(sprintf(
                "  %d. %s%s (%s)\n",
                $index,
                $themeNameBold,
                $status,
                $coloredPath
            ));

            $themeList[$index] = [
                'name' => $themeName,
                'path' => $themeInfo['path'],
                'installed' => isset($installedThemes[$themeName])
            ];
            $index++;
        }

        $this->printing->printing("\n");
        $this->printing->note(__('请选择要安装的主题（输入序号，或输入 0 取消）'));
        $choice = (int)trim($this->system->input());

        if ($choice === 0) {
            return null;
        }

        if (!isset($themeList[$choice])) {
            $this->printing->error(__('无效选择'));
            return $this->selectThemeToInstall();
        }

        $selectedTheme = $themeList[$choice];
        if ($selectedTheme['installed']) {
            $this->printing->warning(__('主题 %{1} 已经安装', [$selectedTheme['name']]));
            $this->printing->note(__('是否重新安装？(y/n，默认：n)'));
            $confirm = trim(strtolower($this->system->input()));
            if ($confirm !== 'y' && $confirm !== 'yes') {
                return null;
            }
        }

        return $selectedTheme['name'];
    }

    /**
     * 扫描可用的主题
     */
    private function scanAvailableThemes(): array
    {
        $themes = [];
        $designPath = Env::path_CODE_DESIGN;

        if (!is_dir($designPath)) {
            return $themes;
        }

        try {
            // 扫描 app/design 目录
            $dirIterator = new \RecursiveDirectoryIterator($designPath, \RecursiveDirectoryIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $file) {
                // 目录层级示例（以 app/design 为根）：
                // depth 0: app/design/Vendor
                // depth 1: app/design/Vendor/ThemeName  ← 主题目录 (包含 register.php)
                // depth 2+: 主题内部文件/目录
                if ($file->isDir() && $iterator->getDepth() === 1) {
                    $themePath = $file->getPathname();
                    $registerFile = $themePath . DS . 'register.php';

                    if (is_file($registerFile)) {
                        // 尝试读取 register.php 获取主题信息
                        $themeInfo = $this->parseThemeRegister($registerFile);
                        if ($themeInfo) {
                            $themes[$themeInfo['name']] = [
                                'name' => $themeInfo['name'],
                                'path' => $themePath,
                                'module_name' => $themeInfo['module_name'] ?? '',
                                'version' => $themeInfo['version'] ?? '1.0.0',
                                'description' => $themeInfo['description'] ?? ''
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->printing->warning(__('扫描主题目录时出错：%{1}', [$e->getMessage()]));
        }

        return $themes;
    }

    /**
     * 解析主题注册文件
     */
    private function parseThemeRegister(string $registerFile): ?array
    {
        try {
            $content = file_get_contents($registerFile);
            
            // 提取主题信息
            $info = [];
            
            // 提取 name
            if (preg_match("/'name'\s*=>\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                $info['name'] = $matches[1];
            }
            
            // 提取 module_name (第二个参数)
            if (preg_match("/Register::register\s*\(\s*[^,]+,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                $info['module_name'] = $matches[1];
            }
            
            // 提取 version
            if (preg_match("/['\"](\d+\.\d+\.\d+)['\"]/", $content, $matches)) {
                $info['version'] = $matches[1];
            }
            
            // 提取 description (最后一个参数)
            if (preg_match("/,\s*['\"]([^'\"]+)['\"]\s*\)\s*;/s", $content, $matches)) {
                $info['description'] = $matches[1];
            }
            
            if (empty($info['name'])) {
                return null;
            }
            
            return $info;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 安装主题
     */
    private function installTheme(string $themeName, bool $autoActivate = false): void
    {
        $this->printing->setup(__('正在安装主题：%{1}', [$themeName]));

        // 获取主题信息
        $themes = $this->scanAvailableThemes();
        if (!isset($themes[$themeName])) {
            $this->printing->error(__('未找到主题：%{1}', [$themeName]));
            return;
        }

        $themeInfo = $themes[$themeName];
        $themePath = $themeInfo['path'];
        $registerFile = $themePath . DS . 'register.php';

        if (!file_exists($registerFile)) {
            $this->printing->error(__('主题注册文件不存在：%{1}', [$registerFile]));
            return;
        }

        try {
            // 执行注册文件
            require_once $registerFile;

            // 检查是否安装成功
            $this->welineTheme->clearData();
            $this->welineTheme->load('name', $themeName);

            if ($this->welineTheme->getId()) {
                $this->printing->success(__('主题 %{1} 安装成功！', [$themeName]));

                // 如果指定了自动激活
                if ($autoActivate) {
                    $this->activateTheme($themeName);
                } else {
                    $this->printing->note(__('提示：使用以下命令激活主题：'));
                    $this->printing->printing("  php bin/w theme:activate {$themeName}\n");
                }
            } else {
                $this->printing->warning(__('主题安装可能未完成，请检查 register.php 文件'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('安装主题时出错：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 激活主题
     */
    private function activateTheme(string $themeName): void
    {
        try {
            $this->welineTheme->clearData();
            $this->welineTheme->load('name', $themeName);

            if (!$this->welineTheme->getId()) {
                $this->printing->error(__('主题 %{1} 未安装，无法激活', [$themeName]));
                return;
            }

            // 先取消激活所有主题
            $this->welineTheme->clearQuery();
            $this->welineTheme->update(['is_active' => 0])->fetch();

            // 激活指定主题
            $this->welineTheme->clearData();
            $this->welineTheme->load('name', $themeName);
            $this->welineTheme->setIsActive(1);
            $this->welineTheme->save();

            $this->printing->success(__('主题 %{1} 已激活！', [$themeName]));
        } catch (\Exception $e) {
            $this->printing->error(__('激活主题时出错：%{1}', [$e->getMessage()]));
        }
    }

    /**
     * 加粗文本
     */
    private function boldText(string $text): string
    {
        if (PHP_SAPI === 'cli') {
            return "\033[1m{$text}\033[0m";
        }
        return $text;
    }

    /**
     * 为文本添加颜色
     */
    private function colorizeText(string $text, string $color): string
    {
        if (PHP_SAPI !== 'cli') {
            return $text;
        }

        $colors = [
            'Blue' => "\033[34m",
            'Green' => "\033[32m",
            'Yellow' => "\033[33m",
            'Red' => "\033[31m",
        ];

        $colorCode = $colors[$color] ?? '';
        $reset = "\033[0m";

        return $colorCode . $text . $reset;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('安装主题到系统');
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:install',
            '安装主题到系统',
            [
                '-t, --theme <theme>' => '指定要安装的主题名称',
                '-a, --activate' => '安装后自动激活主题',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '交互式安装' => 'php bin/w theme:install',
                '安装指定主题' => 'php bin/w theme:install -t demo',
                '安装并激活' => 'php bin/w theme:install -t demo --activate',
            ]
        );
    }
}

