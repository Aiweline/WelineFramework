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
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Compress;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\Uninstall\UninstallService;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Register\Installer;

class Create implements CommandInterface
{
    private WelineTheme $welineTheme;
    private Printing $printing;
    private File $file;
    private System $system;
    private string $configFile = '';
    private array $themeConfig = [];

    public function __construct(
        WelineTheme $welineTheme,
        Printing    $printing,
        File        $file,
        System      $system
    )
    {
        $this->welineTheme = $welineTheme;
        $this->printing    = $printing;
        $this->file        = $file;
        $this->system      = $system;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 移除命令名（第一个参数）
        array_shift($args);

        $themeName = '';
        $parentTheme = '';
        $version = '1.0.0';
        $description = '';

        // 解析参数
        foreach ($args as $key => $arg) {
            if (!is_numeric($key)) {
                continue;
            }

            switch ($arg) {
                case '-n':
                case '--name':
                    if (!isset($args[$key + 1])) {
                        throw new ConsoleException(__('设置了 -n/--name 参数，但没有提供主题名称！'));
                    }
                    $themeName = $args[$key + 1];
                    break;
                case '-p':
                case '--parent':
                    if (!isset($args[$key + 1])) {
                        throw new ConsoleException(__('设置了 -p/--parent 参数，但没有提供父主题名称！'));
                    }
                    $parentTheme = $args[$key + 1];
                    break;
                case '-v':
                case '--version':
                    if (!isset($args[$key + 1])) {
                        throw new ConsoleException(__('设置了 -v/--version 参数，但没有提供版本号！'));
                    }
                    $version = $args[$key + 1];
                    break;
                case '-d':
                case '--description':
                    if (!isset($args[$key + 1])) {
                        throw new ConsoleException(__('设置了 -d/--description 参数，但没有提供描述信息！'));
                    }
                    $description = $args[$key + 1];
                    break;
                default:
                    // 如果没有参数标识，第一个参数作为主题名称或模块名称
                    if (empty($themeName) && !str_starts_with($arg, '-')) {
                        $themeName = $arg;
                    }
                    break;
            }
        }

        // 标准化主题名称：支持多种输入格式
        if (!empty($themeName)) {
            $themeName = $this->normalizeThemeName($themeName);
        }

        // 如果指定了主题名，检查主题是否已存在
        if (!empty($themeName)) {
            // 验证主题名称格式
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $themeName)) {
                throw new ConsoleException(__('主题名称只能包含字母、数字、下划线和连字符！'));
            }

            // 检查主题是否已存在（数据库或目录）
            $existingTheme = $this->welineTheme->clearData()->load('name', $themeName);
            $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
            $isDirExists = is_dir($themePath);
            $isThemeRegistered = $existingTheme->getId() > 0;
            
            // 如果主题已存在，直接进入二次操作模式
            if ($isThemeRegistered || $isDirExists) {
                // 获取配置文件路径（优先主题目录，兼容旧数据）
                $this->configFile = $this->getConfigFile($themeName);
                if (file_exists($this->configFile)) {
                    $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
                } else {
                    // 创建默认配置
                    if ($isDirExists) {
                        $this->configFile = $themePath . DS . '.theme-config.json';
                    } else {
                        $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
                    }
                    
                    $this->themeConfig = [
                        'theme_name' => $themeName,
                        'version' => $version ?: '1.0.0',
                        'description' => $description,
                        'parent_theme' => $parentTheme,
                        'create_template' => false,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'status' => 'in_progress'
                    ];
                    $this->saveConfig(true);
                }
                
                // 直接进入二次操作模式，不询问
                $this->handleSecondaryOperation($themeName);
                return;
            }
            
            // 主题不存在，初始化配置准备创建
            $this->configFile = $this->getConfigFile($themeName);
            if (!file_exists($this->configFile)) {
                // 如果主题目录已存在，使用主题目录；否则使用 var/theme_config
                if (is_dir($themePath)) {
                    $this->configFile = $themePath . DS . '.theme-config.json';
                } else {
                    $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
                }
                
                $this->themeConfig = [
                    'theme_name' => $themeName,
                    'version' => $version,
                    'description' => $description,
                    'parent_theme' => $parentTheme,
                    'create_template' => false,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'status' => 'in_progress'
                ];
            } else {
                $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
            }
        }

        // 如果没有提供参数，显示主题选择菜单
        if (empty($themeName)) {
            $this->showThemeSelectionMenu();
            return;
        }

        // 验证主题名称格式（只允许字母、数字、下划线和连字符）
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $themeName)) {
            throw new ConsoleException(__('主题名称只能包含字母、数字、下划线和连字符！'));
        }

        // 检查父主题是否存在
        if (!empty($parentTheme)) {
            $parent = $this->welineTheme->load('name', $parentTheme);
            if (!$parent->getId()) {
                throw new ConsoleException(__('父主题 "%{1}" 不存在！请先创建父主题或使用已存在的主题名称。', [$parentTheme]));
            }
        }

        // 检查主题是否已存在或目录是否已存在（这部分代码应该不会执行，因为上面已经处理了）
        // 但为了代码完整性，保留检查逻辑
        $existingTheme = $this->welineTheme->clearData()->load('name', $themeName);
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $isDirExists = is_dir($themePath);
        $isThemeRegistered = $existingTheme->getId() > 0;
        
        if ($isThemeRegistered || $isDirExists) {
            // 检测到主题已存在，直接进入二次操作模式（不询问）
            // 加载或创建配置
            if (empty($this->themeConfig)) {
                $this->configFile = $this->getConfigFile($themeName);
                if (file_exists($this->configFile)) {
                    $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
                } else {
                    // 如果主题目录存在，使用主题目录；否则使用 var/theme_config
                    if ($isDirExists) {
                        $this->configFile = $themePath . DS . '.theme-config.json';
                    } else {
                        $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
                    }
                    
                    $this->themeConfig = [
                        'theme_name' => $themeName,
                        'version' => $version,
                        'description' => $description,
                        'parent_theme' => $parentTheme,
                        'create_template' => false,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'status' => 'in_progress'
                    ];
                    $this->saveConfig(true);
                }
            }
            
            // 直接进入二次操作模式，不询问
            $this->handleSecondaryOperation($themeName);
            return;
        }

        // 更新配置
        if (empty($this->configFile)) {
            // 如果主题目录已存在，使用主题目录；否则使用 var/theme_config
            if (is_dir($themePath)) {
                $this->configFile = $themePath . DS . '.theme-config.json';
            } else {
                $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
            }
        }
        $this->themeConfig['theme_name'] = $themeName;
        $this->themeConfig['version'] = $version;
        $this->themeConfig['description'] = $description;
        $this->themeConfig['parent_theme'] = $parentTheme;
        $this->themeConfig['status'] = 'in_progress';
        $this->saveConfig(true);

        // 创建主题目录
        $this->printing->warning(__('正在创建主题目录结构...'));
        $this->createThemeStructure($themePath, $themeName, $parentTheme, $version, $description, false);

        // 显示创建信息
        $this->displayThemeInfo($themeName, $themePath, $parentTheme, $version, $description);

        $this->printing->success(__('主题 "%{1}" 创建成功！', [$themeName]));
        
        // 更新状态为已完成
        $this->themeConfig['status'] = 'completed';
        $this->themeConfig['theme_path'] = $themePath;
        
        // 将配置文件移动到主题目录
        $this->moveConfigToTheme($themeName, $themePath);
        
        // 保存配置（静默保存，因为前面已经显示过成功信息）
        $this->saveConfig(true);
    }

    /**
     * 获取配置文件路径
     * 优先从主题目录读取，其次从 var/theme_config 读取（兼容旧数据）
     */
    private function getConfigFile(string $themeName): string
    {
        // 优先检查主题目录下的配置文件
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $themeConfigFile = $themePath . DS . '.theme-config.json';
        
        if (file_exists($themeConfigFile)) {
            return $themeConfigFile;
        }
        
        // 兼容旧数据：检查 var/theme_config 目录
        $oldConfigFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
        if (file_exists($oldConfigFile)) {
            // 如果主题目录存在，迁移配置文件
            if (is_dir($themePath)) {
                $this->migrateConfigToTheme($oldConfigFile, $themeConfigFile);
                return $themeConfigFile;
            }
            return $oldConfigFile;
        }
        
        // 如果主题目录存在，使用主题目录
        if (is_dir($themePath)) {
            return $themeConfigFile;
        }
        
        // 否则使用 var/theme_config 目录
        return $oldConfigFile;
    }
    
    /**
     * 迁移配置文件到主题目录
     */
    private function migrateConfigToTheme(string $oldConfigFile, string $newConfigFile): void
    {
        if (!file_exists($oldConfigFile)) {
            return;
        }
        
        $configDir = dirname($newConfigFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        
        // 复制配置文件
        if (copy($oldConfigFile, $newConfigFile)) {
            // 删除旧配置文件
            @unlink($oldConfigFile);
            $this->printing->note(__('配置文件已迁移到主题目录：%{1}', [$newConfigFile]));
        }
    }
    
    /**
     * 保存配置
     * 
     * @param bool $silent 是否静默保存（不输出提示信息）
     */
    private function saveConfig(bool $silent = false): void
    {
        if (empty($this->configFile)) {
            return;
        }

        $configDir = dirname($this->configFile);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $isUpdate = file_exists($this->configFile);
        $this->themeConfig['updated_at'] = date('Y-m-d H:i:s');
        
        // 如果没有状态，设置为进行中
        if (!isset($this->themeConfig['status'])) {
            $this->themeConfig['status'] = 'in_progress';
        }
        
        file_put_contents($this->configFile, json_encode($this->themeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if (!$silent) {
            if ($isUpdate) {
                $this->printing->note(__('配置已更新：%{1}', [$this->configFile]));
            } else {
                $this->printing->success(__('配置已保存到：%{1}', [$this->configFile]));
            }
        }
    }
    
    /**
     * 将配置文件移动到主题目录
     */
    private function moveConfigToTheme(string $themeName, string $themePath): void
    {
        if (empty($this->configFile) || !file_exists($this->configFile)) {
            return;
        }
        
        // 如果配置文件已经在主题目录，不需要移动
        if (str_contains($this->configFile, $themePath)) {
            return;
        }
        
        $newConfigFile = $themePath . DS . '.theme-config.json';
        
        // 确保主题目录存在
        if (!is_dir($themePath)) {
            mkdir($themePath, 0755, true);
        }
        
        $oldConfigFile = $this->configFile;
        
        // 移动配置文件
        if (rename($oldConfigFile, $newConfigFile)) {
            $this->configFile = $newConfigFile;
            $this->printing->note(__('配置文件已移动到主题目录：%{1}', [$newConfigFile]));
        } else {
            // 如果移动失败，尝试复制
            if (copy($oldConfigFile, $newConfigFile)) {
                $this->configFile = $newConfigFile;
                @unlink($oldConfigFile); // 删除旧文件
                $this->printing->note(__('配置文件已复制到主题目录：%{1}', [$newConfigFile]));
            }
        }
    }

    /**
     * 显示主题配置摘要
     */
    private function showThemeConfigSummary(): void
    {
        $this->printing->printing("\n");
        $this->printing->note(__('=== 已配置内容摘要 ==='));
        
        $themeName = $this->themeConfig['theme_name'] ?? '';
        if ($themeName) {
            $this->printing->printing("主题名称: {$themeName}\n");
        }
        
        $status = $this->themeConfig['status'] ?? 'unknown';
        $statusText = [
            'in_progress' => '进行中',
            'completed' => '已完成',
            'cancelled' => '已取消'
        ];
        $this->printing->printing("状态: " . ($statusText[$status] ?? $status) . "\n");
        
        $version = $this->themeConfig['version'] ?? '';
        if ($version) {
            $this->printing->printing("版本: {$version}\n");
        }
        
        $description = $this->themeConfig['description'] ?? '';
        if ($description) {
            $this->printing->printing("描述: {$description}\n");
        }
        
        $parentTheme = $this->themeConfig['parent_theme'] ?? '';
        if ($parentTheme) {
            $this->printing->printing("父主题: {$parentTheme}\n");
        } else {
            $this->printing->printing("父主题: 无（独立主题）\n");
        }
        
        $createTemplate = $this->themeConfig['create_template'] ?? false;
        $this->printing->printing("创建默认模板: " . ($createTemplate ? '是' : '否') . "\n");
        
        $this->printing->printing("\n");
    }

    /**
     * 从上次中断的地方继续
     */
    private function continueFromLastStep(string $themeName): void
    {
        $this->printing->note(__('=== 继续之前的操作 ==='));
        
        // 检查哪些步骤已完成，哪些未完成
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $isDirExists = is_dir($themePath);
        $hasTemplate = false;
        if ($isDirExists) {
            $templatePath = $themePath . DS . 'Aiweline' . DS . 'Demo' . DS . 'view' . DS . 'templates' . DS . 'Index' . DS . 'index.phtml';
            $hasTemplate = file_exists($templatePath);
        }
        
        $this->printing->note(__('当前状态：'));
        $this->printing->note(__('  主题目录：%{1}', [$isDirExists ? __('已创建') : __('未创建')]));
        $this->printing->note(__('  默认模板：%{1}', [$hasTemplate ? __('已创建') : __('未创建')]));
        $this->printing->printing("\n");
        
        // 如果目录不存在，需要创建
        if (!$isDirExists) {
            $this->printing->note(__('主题目录不存在，需要创建主题'));
            $parentTheme = $this->themeConfig['parent_theme'] ?? '';
            $version = $this->themeConfig['version'] ?? '1.0.0';
            $description = $this->themeConfig['description'] ?? '';
            $createTemplate = $this->themeConfig['create_template'] ?? false;
            
            $this->createTheme($themeName, $themePath, $parentTheme, $version, $description, $createTemplate);
        } elseif (!$hasTemplate && ($this->themeConfig['create_template'] ?? false)) {
            // 目录存在但模板不存在，且配置要求创建模板
            $this->printing->note(__('主题目录已存在，但默认模板未创建'));
            $this->printing->note(__('是否现在创建默认模板？(y/n，默认：y)'));
            $create = trim(strtolower($this->system->input()));
            if ($create === '' || $create === 'y' || $create === 'yes') {
                $this->createDefaultTemplateFile($themeName);
            }
        } else {
            $this->printing->success(__('主题已完整创建！'));
            $this->printing->note(__('您可以：'));
            $this->printing->note(__('1. 运行 php bin/w module:install 安装主题'));
            $this->printing->note(__('2. 运行 php bin/w theme:activate %{1} 激活主题', [$themeName]));
        }
        
        // 进入操作菜单
        $this->printing->printing("\n");
        $this->printing->note(__('是否进入操作菜单？(y/n，默认：n)'));
        $enterMenu = trim(strtolower($this->system->input()));
        if ($enterMenu === 'y' || $enterMenu === 'yes') {
            $this->handleSecondaryOperation($themeName);
        }
    }

    /**
     * 处理二次操作
     */
    private function handleSecondaryOperation(string $themeName): void
    {
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        
        while (true) {
            $this->printing->printing("\n");
            $this->printing->note(__('=== 主题操作菜单 ==='));
            $this->printing->note(__('主题：%{1}', [$themeName]));
            
            // 显示当前状态
            $isDirExists = is_dir($themePath);
            $status = $this->themeConfig['status'] ?? 'unknown';
            $statusText = [
                'in_progress' => '进行中',
                'completed' => '已完成',
                'cancelled' => '已取消'
            ];
            $this->printing->note(__('状态：%{1}', [$statusText[$status] ?? $status]));
            $this->printing->note(__('目录：%{1}', [$isDirExists ? __('已创建') : __('未创建')]));
            
            $this->printing->printing("\n");
            $this->printing->note(__('请选择操作：'));
            $this->printing->note(__('1. 重新创建主题（根据配置重新创建所有文件）'));
            $this->printing->note(__('2. 创建默认模板文件'));
            $this->printing->note(__('3. 查看主题配置'));
            $this->printing->note(__('4. 修改主题信息'));
            $this->printing->note(__('5. 继续完成创建流程'));
            $this->printing->note(__('6. 提取并翻译i18n词典（高级选项）'));
            $this->printing->note(__('7. 继承模块（从其他模块拷贝view目录）'));
            $this->printing->note(__('8. 检查主题完整性'));
            $this->printing->note(__('9. 注册主题到数据库'));
            $this->printing->note(__('10. 卸载主题（从数据库移除）'));
            $this->printing->note(__('11. 重装主题（卸载后重新安装）'));
            $this->printing->note(__('0. 退出'));

            $choice = trim($this->system->input());

            switch ($choice) {
                case '1':
                    $this->recreateTheme($themeName);
                    break;
                case '2':
                    $this->createDefaultTemplateFile($themeName);
                    break;
                case '3':
                    $this->showThemeConfig();
                    break;
                case '4':
                    $this->modifyThemeInfo($themeName);
                    break;
                case '5':
                    $this->continueFromLastStep($themeName);
                    break;
                case '6':
                    $this->extractAndTranslateI18n($themeName);
                    break;
                case '7':
                    $this->inheritModule($themeName);
                    break;
                case '8':
                    $this->checkThemeIntegrity($themeName);
                    break;
                case '9':
                    $this->registerTheme($themeName);
                    break;
                case '10':
                    $this->uninstallTheme($themeName);
                    break;
                case '11':
                    $this->reinstallTheme($themeName);
                    break;
                case '0':
                    $this->printing->success(__('退出操作'));
                    return;
                default:
                    $this->printing->warning(__('无效选项，请重新选择'));
            }
        }
    }

    /**
     * 重新创建主题
     */
    private function recreateTheme(string $themeName): void
    {
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $parentTheme = $this->themeConfig['parent_theme'] ?? '';
        $version = $this->themeConfig['version'] ?? '1.0.0';
        $description = $this->themeConfig['description'] ?? '';
        $createTemplate = $this->themeConfig['create_template'] ?? false;

        $this->printing->note(__('确认重新创建主题？这将覆盖现有文件。(y/n，默认：n)'));
        $confirm = trim(strtolower($this->system->input()));
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printing->note(__('已取消操作'));
            return;
        }

        $this->createTheme($themeName, $themePath, $parentTheme, $version, $description, $createTemplate);
    }

    /**
     * 创建默认模板文件
     */
    private function createDefaultTemplateFile(string $themeName): void
    {
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        if (!is_dir($themePath)) {
            $this->printing->error(__('主题目录不存在：%{1}', [$themePath]));
            return;
        }

        $this->createDefaultTemplate($themePath, $themeName);
        $this->themeConfig['create_template'] = true;
        $this->saveConfig(true);
    }

    /**
     * 显示主题配置
     */
    private function showThemeConfig(): void
    {
        $this->showThemeConfigSummary();
        $this->printing->printing("\n");
        $this->printing->note(__('完整配置：'));
        $this->printing->printing(json_encode($this->themeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * 修改主题信息
     */
    private function modifyThemeInfo(string $themeName): void
    {
        $this->printing->note(__('=== 修改主题信息 ==='));
        
        // 修改版本号
        $this->printing->note(__('当前版本：%{1}', [$this->themeConfig['version'] ?? '1.0.0']));
        $this->printing->note(__('请输入新版本号（留空不修改）'));
        $version = trim($this->system->input());
        if (!empty($version)) {
            $this->themeConfig['version'] = $version;
        }

        // 修改描述
        $this->printing->note(__('当前描述：%{1}', [$this->themeConfig['description'] ?? '无']));
        $this->printing->note(__('请输入新描述（留空不修改）'));
        $description = trim($this->system->input());
        if (!empty($description)) {
            $this->themeConfig['description'] = $description;
        }

        // 修改父主题
        $this->printing->note(__('当前父主题：%{1}', [$this->themeConfig['parent_theme'] ?? '无']));
        $this->printing->note(__('是否修改父主题？(y/n，默认：n)'));
        $modifyParent = trim(strtolower($this->system->input()));
        if ($modifyParent === 'y' || $modifyParent === 'yes') {
            $allThemes = $this->welineTheme->clearData()->select()->fetch();
            $themeList = [];
            foreach ($allThemes as $theme) {
                if ($theme->getName() !== $themeName) {
                    $themeList[] = $theme->getName();
                }
            }

            if (!empty($themeList)) {
                $this->printing->note(__('可用的父主题：'));
                foreach ($themeList as $index => $theme) {
                    $this->printing->note(__('  %{1}. %{2}', [$index + 1, $theme]));
                }
                $this->printing->note(__('请输入父主题名称（留空表示无父主题）'));
            } else {
                $this->printing->note(__('请输入父主题名称（留空表示无父主题）'));
            }

            $parentInput = trim($this->system->input());
            if (!empty($parentInput)) {
                $parent = $this->welineTheme->clearData()->load('name', $parentInput);
                if (!$parent->getId()) {
                    $this->printing->error(__('父主题 "%{1}" 不存在！', [$parentInput]));
                    // 保存用户输入的父主题名称（即使验证失败）
                    $this->themeConfig['parent_theme'] = $parentInput;
                    $this->saveConfig(true);
                    return;
                }
                $this->themeConfig['parent_theme'] = $parentInput;
            } else {
                $this->themeConfig['parent_theme'] = '';
            }
        }

        $this->saveConfig(true);
        $this->printing->success(__('主题信息已更新'));
    }

    /**
     * 命令别名
     */
    public const ALIASES = ['theme:create', 't:create', 'theme:crea'];

    /**
     * 创建主题目录结构
     *
     * @param string $themePath 主题路径
     * @param string $themeName 主题名称
     * @param string $parentTheme 父主题名称
     * @param string $version 版本号
     * @param string $description 描述
     * @param bool $createTemplate 是否创建默认模板文件
     * @return void
     */
    private function createThemeStructure(
        string $themePath,
        string $themeName,
        string $parentTheme,
        string $version,
        string $description,
        bool $createTemplate = false
    ): void {
        // 创建主目录
        if (!is_dir($themePath)) {
            mkdir($themePath, 0755, true);
        }

        // 创建 register.php 文件
        $this->createRegisterFile($themePath, $themeName, $parentTheme, $version, $description);

        // 创建基础目录结构（可选，根据实际需求）
        $directories = [
            'view' . DS . 'templates',
            'view' . DS . 'statics' . DS . 'css',
            'view' . DS . 'statics' . DS . 'js',
            'view' . DS . 'statics' . DS . 'images',
        ];

        foreach ($directories as $dir) {
            $fullPath = $themePath . DS . $dir;
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
        }

        // 创建 README.md 文件
        $this->createReadmeFile($themePath, $themeName, $parentTheme, $version, $description);

        // 如果选择创建默认模板文件
        if ($createTemplate) {
            $this->createDefaultTemplate($themePath, $themeName);
        }
    }

    /**
     * 创建默认模板文件
     *
     * @param string $themePath 主题路径
     * @param string $themeName 主题名称
     * @return void
     */
    private function createDefaultTemplate(string $themePath, string $themeName): void
    {
        // 创建默认模板目录结构：Aiweline/Demo/view/templates/Index/index.phtml
        $templateDir = $themePath . DS . 'Aiweline' . DS . 'Demo' . DS . 'view' . DS . 'templates' . DS . 'Index';
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0755, true);
        }

        $templateFile = $templateDir . DS . 'index.phtml';
        $templateContent = <<<PHP
<?php
/**@var \Weline\Framework\View\Template \$this */
?>
<div class="helloworld-container">
    <h1><?= __('Hello World!') ?></h1>
    <p><?= __('欢迎使用 %{1} 主题', ['{$themeName}']) ?></p>
    <p><?= __('这是一个默认的模板文件，您可以在此基础上进行开发。') ?></p>
</div>
PHP;

        $this->file->open($templateFile, File::mode_w);
        $this->file->write($templateContent);
        $this->file->close();
        $this->printing->success(__('已创建默认模板文件: %{1}', [$templateFile]));
    }

    /**
     * 创建 register.php 文件
     *
     * @param string $themePath 主题路径
     * @param string $themeName 主题名称
     * @param string $parentTheme 父主题名称
     * @param string $version 版本号
     * @param string $description 描述
     * @return void
     */
    private function createRegisterFile(
        string $themePath,
        string $themeName,
        string $parentTheme,
        string $version,
        string $description
    ): void {
        $moduleName = 'Weline_' . $this->toPascalCase($themeName);
        $registerContent = <<<PHP
<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    \Weline\Theme\Register\TypeInterface::type,
    '{$moduleName}',
    [
        'name' => '{$themeName}',
PHP;

        // 如果有父主题，添加 parent 参数
        if (!empty($parentTheme)) {
            $registerContent .= "\n        'parent' => '{$parentTheme}',";
        }

        $registerContent .= <<<PHP

        'path' => __DIR__,
    ],
    '{$version}',
    '{$description}'
);
PHP;

        $registerFile = $themePath . DS . 'register.php';
        $this->file->open($registerFile, File::mode_w);
        $this->file->write($registerContent);
        $this->file->close();
        $this->printing->note(__('已创建注册文件: %{1}', [$registerFile]));
    }

    /**
     * 创建 README.md 文件
     *
     * @param string $themePath 主题路径
     * @param string $themeName 主题名称
     * @param string $parentTheme 父主题名称
     * @param string $version 版本号
     * @param string $description 描述
     * @return void
     */
    private function createReadmeFile(
        string $themePath,
        string $themeName,
        string $parentTheme,
        string $version,
        string $description
    ): void {
        $readmeContent = <<<MD
# {$themeName} 主题

## 主题信息

- **主题名称**: {$themeName}
- **版本**: {$version}
- **描述**: {$description}
MD;

        if (!empty($parentTheme)) {
            $readmeContent .= "\n- **父主题**: {$parentTheme}";
        }

        $readmeContent .= <<<MD

## 目录结构

```
{$themeName}/
├── register.php              # 主题注册文件
├── view/                     # 视图文件目录
│   ├── templates/           # 模板文件
│   └── statics/             # 静态资源
│       ├── css/             # 样式文件
│       ├── js/              # JavaScript文件
│       └── images/          # 图片资源
└── README.md                # 本文件
```

## 使用说明

1. 主题已自动注册，可以通过以下命令安装：
   ```bash
   php bin/w module:install
   ```

2. 激活主题：
   ```bash
   php bin/w theme:activate {$themeName}
   ```

## 开发说明

- 主题路径: `app/design/Weline/{$themeName}/`
- 模块名称: `Weline_{$this->toPascalCase($themeName)}`
MD;

        if (!empty($parentTheme)) {
            $readmeContent .= "\n- 继承自: `{$parentTheme}` 主题\n";
        }

        $readmeFile = $themePath . DS . 'README.md';
        $this->file->open($readmeFile, File::mode_w);
        $this->file->write($readmeContent);
        $this->file->close();
    }

    /**
     * 显示主题信息
     *
     * @param string $themeName 主题名称
     * @param string $themePath 主题路径
     * @param string $parentTheme 父主题名称
     * @param string $version 版本号
     * @param string $description 描述
     * @return void
     */
    private function displayThemeInfo(
        string $themeName,
        string $themePath,
        string $parentTheme,
        string $version,
        string $description
    ): void {
        $this->printing->success(__('═══════════════════════════════════════════════════════'));
        $this->printing->success(__('主题创建成功！'));
        $this->printing->success(__('═══════════════════════════════════════════════════════'));
        $this->printing->note(__('主题名称: %{1}', [$themeName]));
        $this->printing->note(__('主题路径: %{1}', [$themePath]));
        $this->printing->note(__('模块名称: Weline_%{1}', [$this->toPascalCase($themeName)]));
        
        if (!empty($parentTheme)) {
            $this->printing->note(__('继承自: %{1} 主题', [$parentTheme]));
        } else {
            $this->printing->note(__('继承自: 无（独立主题）'));
        }
        
        $this->printing->note(__('版本: %{1}', [$version]));
        
        if (!empty($description)) {
            $this->printing->note(__('描述: %{1}', [$description]));
        }
        
        $this->printing->success(__('═══════════════════════════════════════════════════════'));
        $this->printing->warning(__('下一步操作:'));
        $this->printing->note(__('1. 运行 php bin/w module:install 安装主题'));
        $this->printing->note(__('2. 运行 php bin/w theme:activate %{1} 激活主题', [$themeName]));
        $this->printing->success(__('═══════════════════════════════════════════════════════'));
    }

    /**
     * 标准化主题名称：支持多种输入格式
     * - Weline_Demo -> demo
     * - Weline/Demo -> demo
     * - weline-demo -> weline-demo
     * - demo -> demo
     *
     * @param string $input 输入的主题名称或模块名称
     * @return string 标准化后的主题名称
     */
    private function normalizeThemeName(string $input): string
    {
        // 如果包含下划线（模块名称格式，如 Weline_Demo）
        if (str_contains($input, '_') && !str_contains($input, '-')) {
            // 提取模块名称的最后部分（去掉命名空间前缀）
            $parts = explode('_', $input);
            $lastPart = end($parts);
            // 将 PascalCase 转换为 kebab-case
            return $this->pascalCaseToKebabCase($lastPart);
        }
        
        // 如果包含斜杠（路径格式，如 Weline/Demo）
        if (str_contains($input, '/') || str_contains($input, '\\')) {
            $input = str_replace(['/', '\\'], '/', $input);
            $parts = explode('/', $input);
            $lastPart = end($parts);
            // 将 PascalCase 转换为 kebab-case
            return $this->pascalCaseToKebabCase($lastPart);
        }
        
        // 其他情况直接返回（可能是主题名称格式，如 weline-demo 或 demo）
        return $input;
    }
    
    /**
     * 将 PascalCase 转换为 kebab-case
     * 例如：WelineDemo -> weline-demo, Demo -> demo
     *
     * @param string $string PascalCase 字符串
     * @return string kebab-case 字符串
     */
    private function pascalCaseToKebabCase(string $string): string
    {
        // 在驼峰命名的大写字母前插入连字符
        $string = preg_replace('/([a-z])([A-Z])/', '$1-$2', $string);
        // 转换为小写
        return strtolower($string);
    }

    /**
     * 将字符串转换为 PascalCase
     *
     * @param string $string 输入字符串
     * @return string PascalCase 字符串
     */
    private function toPascalCase(string $string): string
    {
        // 将下划线和连字符替换为空格
        $string = str_replace(['_', '-'], ' ', $string);
        // 首字母大写并移除空格
        return str_replace(' ', '', ucwords($string));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('创建新主题');
    }

    /**
     * 交互式创建主题
     */
    private function interactiveCreate(): void
    {
        $this->printing->setup(__('=== 主题创建向导 ==='));
        $this->printing->printing("\n");

        // 1. 询问主题名称
        $this->printing->note(__('请输入主题名称（只能包含字母、数字、下划线和连字符，例如：my-theme）'));
        $themeName = trim($this->system->input());
        if (empty($themeName)) {
            $this->printing->error(__('主题名称不能为空'));
            return;
        }

        // 验证主题名称格式
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $themeName)) {
            $this->printing->error(__('主题名称只能包含字母、数字、下划线和连字符！'));
            return;
        }

        // 检查是否有之前的操作记录（配置文件）
        $this->configFile = $this->getConfigFile($themeName);
        if (file_exists($this->configFile)) {
            // 恢复现场操作数据
            $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
            
            $this->printing->note(__('检测到主题 %{1} 之前的操作记录', [$themeName]));
            $this->printing->note(__('创建时间：%{1}', [$this->themeConfig['created_at'] ?? '未知']));
            $this->printing->note(__('最后更新：%{1}', [$this->themeConfig['updated_at'] ?? '未知']));
            
            // 显示已配置的内容摘要
            $this->showThemeConfigSummary();
            
            $this->printing->printing("\n");
            $this->printing->note(__('请选择操作：'));
            $this->printing->note(__('1. 继续之前的操作（从上次中断的地方继续）'));
            $this->printing->note(__('2. 重新开始（保留配置但重新走完流程）'));
            $this->printing->note(__('3. 进入二次操作菜单'));
            $this->printing->note(__('4. 取消'));
            
            $choice = trim($this->system->input());
            
            switch ($choice) {
                case '1':
                    // 继续之前的操作，从上次中断的地方继续
                    $this->continueFromLastStep($themeName);
                    return;
                case '2':
                    // 重新开始，但保留配置作为默认值
                    $this->printing->note(__('将使用之前的配置作为默认值，您可以修改'));
                    // 继续执行下面的流程，但会使用已保存的配置作为默认值
                    break;
                case '3':
                    // 进入二次操作模式
                    $this->handleSecondaryOperation($themeName);
                    return;
                case '4':
                default:
                    $this->printing->note(__('已取消操作'));
                    return;
            }
        }

        // 检查主题是否已存在或目录是否已存在
        $existingTheme = $this->welineTheme->clearData()->load('name', $themeName);
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $isDirExists = is_dir($themePath);
        $isThemeRegistered = $existingTheme->getId() > 0;
        
        if ($isThemeRegistered || $isDirExists) {
            // 检测到主题已存在，直接显示操作选项
            $this->printing->warning(__('检测到主题 "%{1}" 已存在！', [$themeName]));
            
            if ($isThemeRegistered) {
                $this->printing->note(__('  - 主题已在数据库中注册'));
            }
            if ($isDirExists) {
                $this->printing->note(__('  - 主题目录已存在: %{1}', [$themePath]));
            }
            
            // 加载或创建配置
            if (empty($this->themeConfig)) {
                $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
                if (file_exists($this->configFile)) {
                    $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
                } else {
                    $this->themeConfig = [
                        'theme_name' => $themeName,
                        'version' => '1.0.0',
                        'description' => '',
                        'parent_theme' => '',
                        'create_template' => false,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'status' => 'in_progress'
                    ];
                    $this->saveConfig(true);
                }
            }
            
            // 显示配置摘要（如果有）
            if (!empty($this->themeConfig['theme_name'])) {
                $this->printing->printing("\n");
                $this->showThemeConfigSummary();
            }
            
            $this->printing->printing("\n");
            $this->printing->note(__('请选择操作：'));
            $this->printing->note(__('1. 进入二次操作菜单（推荐）'));
            $this->printing->note(__('2. 继续完成创建流程'));
            $this->printing->note(__('3. 查看主题配置'));
            $this->printing->note(__('4. 修改主题信息'));
            $this->printing->note(__('0. 退出'));
            
            $choice = trim($this->system->input());
            
            switch ($choice) {
                case '1':
                    $this->handleSecondaryOperation($themeName);
                    return;
                case '2':
                    $this->continueFromLastStep($themeName);
                    return;
                case '3':
                    $this->showThemeConfig();
                    $this->printing->printing("\n");
                    $this->printing->note(__('是否进入操作菜单？(y/n，默认：n)'));
                    $enterMenu = trim(strtolower($this->system->input()));
                    if ($enterMenu === 'y' || $enterMenu === 'yes') {
                        $this->handleSecondaryOperation($themeName);
                    }
                    return;
                case '4':
                    $this->modifyThemeInfo($themeName);
                    $this->printing->printing("\n");
                    $this->printing->note(__('是否进入操作菜单？(y/n，默认：n)'));
                    $enterMenu = trim(strtolower($this->system->input()));
                    if ($enterMenu === 'y' || $enterMenu === 'yes') {
                        $this->handleSecondaryOperation($themeName);
                    }
                    return;
                case '0':
                default:
                    $this->printing->note(__('已退出'));
                    return;
            }
        }

        // 初始化配置
        // 如果主题目录已存在，使用主题目录；否则使用 var/theme_config
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        if (is_dir($themePath)) {
            $this->configFile = $themePath . DS . '.theme-config.json';
        } else {
            $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
        }
        
        if (!isset($this->themeConfig['theme_name'])) {
            $this->themeConfig = [
                'theme_name' => $themeName,
                'version' => '1.0.0',
                'description' => '',
                'parent_theme' => '',
                'create_template' => false,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        } else {
            // 如果是从配置文件恢复的，确保主题名称是最新的
            $this->themeConfig['theme_name'] = $themeName;
        }
        
        // 立即保存配置（保存主题名称）
        $this->saveConfig();

        // 2. 询问版本号
        $this->printing->note(__('请输入版本号（默认：%{1}）', [$this->themeConfig['version'] ?? '1.0.0']));
        $versionInput = trim($this->system->input());
        $version = $versionInput ?: ($this->themeConfig['version'] ?? '1.0.0');
        $this->themeConfig['version'] = $version;
        // 立即保存配置
        $this->saveConfig();

        // 3. 询问描述
        $defaultDescription = $this->themeConfig['description'] ?? '';
        if ($defaultDescription) {
            $this->printing->note(__('请输入主题描述（可选，当前：%{1}）', [$defaultDescription]));
        } else {
            $this->printing->note(__('请输入主题描述（可选）'));
        }
        $description = trim($this->system->input());
        if (!empty($description)) {
            $this->themeConfig['description'] = $description;
        } elseif (empty($this->themeConfig['description'])) {
            $this->themeConfig['description'] = '';
        }
        // 立即保存配置
        $this->saveConfig();

        // 4. 询问父主题
        $this->printing->note(__('是否要继承父主题？(y/n，默认：n)'));
        $hasParent = trim(strtolower($this->system->input()));
        $hasParent = ($hasParent === 'y' || $hasParent === 'yes');

        $parentTheme = '';
        if ($hasParent) {
            // 获取所有已存在的主题
            $allThemes = $this->welineTheme->clearData()->select()->fetch();
            $themeList = [];
            foreach ($allThemes as $theme) {
                $themeList[] = $theme->getName();
            }

            if (!empty($themeList)) {
                $this->printing->note(__('可用的父主题：'));
                foreach ($themeList as $index => $theme) {
                    $this->printing->note(__('  %{1}. %{2}', [$index + 1, $theme]));
                }
                $this->printing->note(__('请输入父主题名称（从列表中选择或直接输入名称）'));
            } else {
                $this->printing->note(__('请输入父主题名称'));
            }

            $parentInput = trim($this->system->input());
            if (!empty($parentInput)) {
                // 验证父主题是否存在
                $parent = $this->welineTheme->clearData()->load('name', $parentInput);
                if (!$parent->getId()) {
                    $this->printing->error(__('父主题 "%{1}" 不存在！', [$parentInput]));
                    // 保存当前配置（即使父主题验证失败）
                    $this->themeConfig['parent_theme'] = $parentInput; // 保存用户输入，即使验证失败
                    $this->saveConfig();
                    $this->printing->note(__('配置已保存，您可以稍后使用 php bin/w theme:create %{1} 继续操作', [$themeName]));
                    return;
                }
                $parentTheme = $parentInput;
            }
        }
        $this->themeConfig['parent_theme'] = $parentTheme;
        // 立即保存配置
        $this->saveConfig();

        // 5. 询问是否创建默认模板文件
        $defaultCreateTemplate = $this->themeConfig['create_template'] ?? false;
        $this->printing->note(__('是否要创建默认模板文件？(y/n，默认：y)'));
        $this->printing->note(__('直接回车将创建默认 helloworld 模板文件'));
        $createTemplate = trim(strtolower($this->system->input()));
        $createTemplate = ($createTemplate === '' || $createTemplate === 'y' || $createTemplate === 'yes');
        $this->themeConfig['create_template'] = $createTemplate;
        // 立即保存配置
        $this->saveConfig();

        // 6. 确认信息
        $this->printing->success(__('═══════════════════════════════════════════════════════'));
        $this->printing->success(__('请确认以下信息：'));
        $this->printing->note(__('主题名称: %{1}', [$themeName]));
        $this->printing->note(__('版本: %{1}', [$version]));
        if (!empty($description)) {
            $this->printing->note(__('描述: %{1}', [$description]));
        }
        if (!empty($parentTheme)) {
            $this->printing->note(__('继承自: %{1} 主题', [$parentTheme]));
        } else {
            $this->printing->note(__('继承自: 无（独立主题）'));
        }
        $this->printing->note(__('创建默认模板: %{1}', [$createTemplate ? __('是') : __('否')]));
        $this->printing->note(__('创建位置: %{1}', [$themePath]));
        $this->printing->success(__('═══════════════════════════════════════════════════════'));

        $this->printing->note(__('确认创建？(y/n，默认：y)'));
        $confirm = trim(strtolower($this->system->input()));
        if ($confirm !== '' && $confirm !== 'y' && $confirm !== 'yes') {
            $this->printing->warning(__('已取消创建'));
            $this->printing->note(__('配置已保存，您可以稍后使用 php bin/w theme:create %{1} 继续操作', [$themeName]));
            // 即使取消创建，也保存当前状态
            $this->themeConfig['status'] = 'cancelled';
            $this->saveConfig();
            return;
        }

        // 创建主题
        $this->createTheme($themeName, $themePath, $parentTheme, $version, $description, $createTemplate);
    }

    /**
     * 创建主题
     */
    private function createTheme(
        string $themeName,
        string $themePath,
        string $parentTheme,
        string $version,
        string $description,
        bool $createTemplate = false
    ): void {
        // 创建主题目录
        $this->printing->warning(__('正在创建主题目录结构...'));
        $this->createThemeStructure($themePath, $themeName, $parentTheme, $version, $description, $createTemplate);

        // 显示创建信息
        $this->displayThemeInfo($themeName, $themePath, $parentTheme, $version, $description);

        $this->printing->success(__('主题 "%{1}" 创建成功！', [$themeName]));
        
        // 将配置文件移动到主题目录
        $this->moveConfigToTheme($themeName, $themePath);
        
        // 更新状态为已完成
        if (!empty($this->themeConfig)) {
            $this->themeConfig['status'] = 'completed';
            $this->themeConfig['theme_path'] = $themePath;
            $this->saveConfig(true);
        }
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        $usage = <<<USAGE
php bin/w theme:create [主题名称] [选项]

如果不提供任何参数，将进入交互式创建模式。

示例:
  php bin/w theme:create                    # 交互式创建
  php bin/w theme:create my-theme           # 快速创建（使用默认值）
  php bin/w theme:create -n my-theme -p default -v 1.0.0 -d "我的主题"
  php bin/w theme:create my_theme --parent default --version 1.0.1
USAGE;

        $help = \Weline\Framework\Console\CommandHelper::formatHelp(
            'theme:create',
            $this->tip(),
            [
                '-n, --name <name>' => '主题名称（必需，只能包含字母、数字、下划线和连字符）',
                '-p, --parent <parent>' => '父主题名称（可选，如果指定，新主题将继承自该主题）',
                '-v, --version <version>' => '主题版本号（可选，默认为 1.0.0）',
                '-d, --description <description>' => '主题描述（可选）',
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '交互式创建主题' => 'php bin/w theme:create',
                '创建独立主题' => 'php bin/w theme:create my-theme',
                '创建继承主题' => 'php bin/w theme:create -n my-theme -p default -v 1.0.0 -d "我的主题"',
                '进入二次操作模式' => 'php bin/w theme:create my-theme',
            ],
            $usage
        );

        // 添加主题特性说明
        $help .= PHP_EOL . '📋 ' . __('主题特性') . ':' . PHP_EOL;
        $help .= '  • ' . __('支持交互式创建模式，不提供参数时自动进入向导') . PHP_EOL;
        $help .= '  • ' . __('支持二次操作模式，记住创建进程，可继续操作') . PHP_EOL;
        $help .= '  • ' . __('主题将创建在: app/design/Weline/<主题名称>/') . PHP_EOL;
        $help .= '  • ' . __('模块名称格式: Weline_<PascalCase主题名称>') . PHP_EOL;
        $help .= '  • ' . __('支持主题继承，可以指定父主题') . PHP_EOL;
        $help .= '  • ' . __('自动生成 register.php 注册文件') . PHP_EOL;
        $help .= '  • ' . __('自动创建基础目录结构（view/templates, view/statics等）') . PHP_EOL;
        $help .= PHP_EOL . '💡 ' . __('二次操作模式') . ':' . PHP_EOL;
        $help .= '  • ' . __('运行 php bin/w theme:create <主题名> 可进入二次操作模式') . PHP_EOL;
        $help .= '  • ' . __('支持重新创建主题、创建模板文件、查看和修改配置等操作') . PHP_EOL;

        return $help;
    }

    /**
     * 提取并翻译i18n词典
     */
    private function extractAndTranslateI18n(string $themeName): void
    {
        $this->printing->note(__('=== 提取并翻译i18n词典 ==='));
        
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        
        if (!is_dir($themePath)) {
            $this->printing->error(__('主题目录不存在：%{1}', [$themePath]));
            return;
        }
        
        // 1. 询问目标语言（支持多个，逗号分隔）
        $this->printing->note(__('请输入目标语言代码（例如：en_US，多个语言用逗号分隔，默认：zh_Hans_CN）'));
        $targetLocalesInput = trim($this->system->input());
        if (empty($targetLocalesInput)) {
            $targetLocales = ['zh_Hans_CN'];
        } else {
            // 解析多个语言（逗号分隔）
            $targetLocales = array_map('trim', explode(',', $targetLocalesInput));
            $targetLocales = array_filter($targetLocales, function($locale) {
                return !empty($locale);
            });
            if (empty($targetLocales)) {
                $targetLocales = ['zh_Hans_CN'];
            }
        }
        
        $this->printing->success(__('将处理以下语言：%{1}', [implode(', ', $targetLocales)]));
        
        // 2. 扫描主题目录下的模块
        $this->printing->note(__('正在扫描主题目录下的模块...'));
        $modules = $this->scanThemeModules($themePath);
        
        if (empty($modules)) {
            $this->printing->warning(__('未找到任何模块，请确保主题目录下有模块（如：Weline/Demo）'));
            return;
        }
        
        $this->printing->success(__('找到 %{1} 个模块', [count($modules)]));
        foreach ($modules as $modulePath => $moduleName) {
            $this->printing->note(__('  - %{1} (%{2})', [$moduleName, $modulePath]));
        }
        
        // 3. 对每个模块提取翻译词并保存到模块的i18n目录
        $allThemeWords = []; // 汇总所有模块的翻译词
        
        foreach ($modules as $modulePath => $moduleName) {
            $this->printing->printing("\n");
            $this->printing->note(__('正在处理模块：%{1}', [$moduleName]));
            
            // 提取模块的翻译词
            $moduleWords = $this->collectModuleWords($modulePath);
            
            if (empty($moduleWords)) {
                $this->printing->warning(__('模块 %{1} 未找到翻译词', [$moduleName]));
                continue;
            }
            
            $this->printing->success(__('模块 %{1} 提取到 %{2} 条翻译词', [$moduleName, count($moduleWords)]));
            
            // 保存到模块的i18n目录（默认语言zh_Hans_CN）
            $moduleI18nDir = $modulePath . DS . 'i18n';
            $this->saveModuleI18nWords($moduleI18nDir, $moduleWords);
            
            // 合并到主题级别的翻译词
            $allThemeWords = array_merge($allThemeWords, $moduleWords);
        }
        
        // 去重
        $allThemeWords = array_unique($allThemeWords);
        
        if (empty($allThemeWords)) {
            $this->printing->warning(__('未找到任何翻译词'));
            return;
        }
        
        $this->printing->printing("\n");
        $this->printing->success(__('所有模块共提取到 %{1} 条翻译词（去重后）', [count($allThemeWords)]));
        
        // 4. 对每个目标语言进行处理
        foreach ($targetLocales as $targetLocale) {
            $this->printing->printing("\n");
            $this->printing->note(__('=== 处理语言：%{1} ===', [$targetLocale]));
            
            // 4.1 尝试从数据库词典中查找已翻译的
            $this->printing->note(__('正在从数据库词典中查找已翻译的词汇...'));
            $translatedWords = [];
            $untranslatedWords = [];
            
            try {
                /** @var LocaleDictionary $localeDictionary */
                $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
                
                foreach ($allThemeWords as $word) {
                    $md5 = LocaleDictionary::generateMd5($word, $targetLocale);
                    $translation = $localeDictionary->clearData()->load(LocaleDictionary::schema_fields_MD5, $md5);
                    
                    if ($translation->getId() && !empty($translation->getData(LocaleDictionary::schema_fields_TRANSLATE))) {
                        $translatedWords[$word] = $translation->getData(LocaleDictionary::schema_fields_TRANSLATE);
                    } else {
                        $untranslatedWords[] = $word;
                    }
                }
            } catch (\Exception $e) {
                $this->printing->warning(__('从数据库词典查找翻译时出错：%{1}', [$e->getMessage()]));
                $untranslatedWords = $allThemeWords;
            }
            
            $this->printing->success(__('从数据库词典中找到 %{1} 条已翻译的词汇', [count($translatedWords)]));
            
            if (empty($untranslatedWords)) {
                $this->printing->success(__('所有词汇都已翻译！'));
                // 保存到主题级别的i18n目录
                $themeI18nDir = $themePath . DS . 'i18n';
                $this->saveI18nTranslationsToDir($themeI18nDir, $targetLocale, $translatedWords);
                continue;
            }
            
            $this->printing->note(__('还有 %{1} 条词汇需要翻译', [count($untranslatedWords)]));
            
            // 4.2 尝试使用AI翻译服务（仅对非zh_Hans_CN语言）
            if ($targetLocale !== 'zh_Hans_CN') {
                $this->printing->note(__('是否尝试使用AI翻译服务？(y/n，默认：y)'));
                $useAi = trim(strtolower($this->system->input()));
                $useAi = ($useAi === '' || $useAi === 'y' || $useAi === 'yes');
                
                if ($useAi) {
                    $aiTranslatedWords = $this->translateWithAi($untranslatedWords, $targetLocale);
                    if (!empty($aiTranslatedWords)) {
                        $translatedWords = array_merge($translatedWords, $aiTranslatedWords);
                        $untranslatedWords = array_diff($untranslatedWords, array_keys($aiTranslatedWords));
                        $this->printing->success(__('AI翻译完成，成功翻译 %{1} 条词汇', [count($aiTranslatedWords)]));
                    }
                }
            } else {
                // zh_Hans_CN 使用原文作为翻译
                foreach ($untranslatedWords as $word) {
                    $translatedWords[$word] = $word;
                }
                $untranslatedWords = [];
            }
            
            // 4.3 保存到主题级别的i18n目录
            $themeI18nDir = $themePath . DS . 'i18n';
            if (!empty($translatedWords)) {
                $this->saveI18nTranslationsToDir($themeI18nDir, $targetLocale, $translatedWords);
            }
            
            // 4.4 处理未翻译的词汇
            if (!empty($untranslatedWords)) {
                $i18nFile = $themeI18nDir . DS . $targetLocale . '.csv';
                
                // 读取现有的CSV文件
                $existingTranslations = [];
                if (file_exists($i18nFile)) {
                    $handle = @fopen($i18nFile, 'r');
                    if ($handle !== false) {
                        while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                            if (isset($data[0]) && isset($data[1])) {
                                $existingTranslations[trim($data[0])] = trim($data[1]);
                            }
                        }
                        fclose($handle);
                    }
                }
                
                // 合并已翻译的词汇
                $allTranslations = array_merge($existingTranslations, $translatedWords);
                
                // 添加未翻译的词汇（使用原文作为占位符）
                foreach ($untranslatedWords as $word) {
                    if (!isset($allTranslations[$word])) {
                        $allTranslations[$word] = $word; // 使用原文作为占位符
                    }
                }
                
                // 确保目录存在
                if (!is_dir($themeI18nDir)) {
                    mkdir($themeI18nDir, 0755, true);
                }
                
                // 写入CSV文件
                $csvFile = @fopen($i18nFile, 'w+');
                if ($csvFile !== false) {
                    foreach ($allTranslations as $word => $translation) {
                        fputcsv($csvFile, [$word, $translation], ',', '"', '\\');
                    }
                    fclose($csvFile);
                    $this->printing->success(__('已保存到：%{1}', [$i18nFile]));
                } else {
                    $this->printing->error(__('无法写入文件：%{1}', [$i18nFile]));
                }
                
                $this->printing->printing("\n");
                $this->printing->warning(__('以下 %{1} 条词汇需要手动翻译：', [count($untranslatedWords)]));
                $this->printing->note(__('请手动编辑文件 %{1}，将以下词汇的占位符替换为正确的翻译：', [$i18nFile]));
                $this->printing->printing("\n");
                foreach (array_slice($untranslatedWords, 0, 20) as $index => $word) {
                    $this->printing->printing(sprintf("%d. %s\n", $index + 1, $word));
                }
                if (count($untranslatedWords) > 20) {
                    $this->printing->note(__('... 还有 %{1} 条词汇未显示', [count($untranslatedWords) - 20]));
                }
            } else {
                $this->printing->success(__('语言 %{1} 的所有词汇都已翻译完成！', [$targetLocale]));
            }
        }
        
        $this->printing->printing("\n");
        $this->printing->success(__('所有语言处理完成！'));
    }
    
    /**
     * 扫描主题目录下的模块
     */
    private function scanThemeModules(string $themePath): array
    {
        $modules = [];
        
        if (!is_dir($themePath)) {
            return $modules;
        }
        
        // 扫描主题目录下的第一级目录（如 Weline/Demo）
        $dirIterator = new \DirectoryIterator($themePath);
        foreach ($dirIterator as $fileInfo) {
            if ($fileInfo->isDir() && !$fileInfo->isDot()) {
                $vendorName = $fileInfo->getFilename();
                $vendorPath = $fileInfo->getPathname();
                
                // 扫描供应商目录下的模块
                $moduleIterator = new \DirectoryIterator($vendorPath);
                foreach ($moduleIterator as $moduleInfo) {
                    if ($moduleInfo->isDir() && !$moduleInfo->isDot()) {
                        $moduleName = $moduleInfo->getFilename();
                        $modulePath = $moduleInfo->getPathname();
                        
                        // 检查是否是有效的模块目录（有view目录或其他模块特征）
                        if (is_dir($modulePath . DS . 'view') || is_dir($modulePath . DS . 'Controller')) {
                            $fullModuleName = $vendorName . '_' . $moduleName;
                            $modules[$modulePath] = $fullModuleName;
                        }
                    }
                }
            }
        }
        
        return $modules;
    }
    
    /**
     * 收集模块目录下的翻译词
     * 使用统一的 I18n 收集服务
     */
    private function collectModuleWords(string $modulePath): array
    {
        $collector = ObjectManager::getInstance(\Weline\I18n\Service\TranslationCollector::class);
        $pathParts = explode(DS, trim($modulePath, DS));
        $moduleName = count($pathParts) >= 2 ? $pathParts[count($pathParts) - 2] . '_' . $pathParts[count($pathParts) - 1] : basename($modulePath);
        $collectedStrings = $collector->collect($modulePath, $moduleName);
        
        // 转换为简单的数组格式（只返回原文）
        return array_keys($collectedStrings);
    }
    
    /**
     * 保存模块的翻译词到i18n目录（默认语言）
     */
    private function saveModuleI18nWords(string $moduleI18nDir, array $words): void
    {
        if (empty($words)) {
            return;
        }
        
        // 确保目录存在
        if (!is_dir($moduleI18nDir)) {
            mkdir($moduleI18nDir, 0755, true);
        }
        
        // 默认保存到 zh_Hans_CN.csv（作为源语言）
        $defaultLocale = 'zh_Hans_CN';
        $csvFile = $moduleI18nDir . DS . $defaultLocale . '.csv';
        
        // 读取现有的翻译
        $existingTranslations = [];
        if (file_exists($csvFile)) {
            $handle = @fopen($csvFile, 'r');
            if ($handle !== false) {
                while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (isset($data[0]) && isset($data[1])) {
                        $existingTranslations[trim($data[0])] = trim($data[1]);
                    }
                }
                fclose($handle);
            }
        }
        
        // 合并翻译词（新词使用原文作为翻译）
        foreach ($words as $word) {
            if (!isset($existingTranslations[$word])) {
                $existingTranslations[$word] = $word;
            }
        }
        
        // 写入CSV文件
        $file = @fopen($csvFile, 'w+');
        if ($file !== false) {
            foreach ($existingTranslations as $word => $translation) {
                fputcsv($file, [$word, $translation], ',', '"', '\\');
            }
            fclose($file);
        }
    }
    
    /**
     * 从指定目录提取i18n词典数据
     */
    private function extractI18nWordsFromDir(string $i18nDir): array
    {
        $words = [];
        
        if (!is_dir($i18nDir)) {
            return $words;
        }
        
        // 优先查找zh_Hans_CN，如果没有则查找第一个CSV文件
        $defaultLocale = 'zh_Hans_CN';
        $defaultFile = $i18nDir . DS . $defaultLocale . '.csv';
        
        if (!file_exists($defaultFile)) {
            // 查找第一个CSV文件
            $files = glob($i18nDir . DS . '*.csv');
            if (empty($files)) {
                return $words;
            }
            $defaultFile = $files[0];
        }
        
        // 读取CSV文件
        $handle = @fopen($defaultFile, 'r');
        if ($handle === false) {
            return $words;
        }
        
        while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
            if (isset($data[0]) && !empty(trim($data[0]))) {
                $word = trim($data[0]);
                // 跳过占位符（原文和译文相同的情况）
                if (!isset($data[1]) || trim($data[1]) === $word) {
                    $words[] = $word;
                }
            }
        }
        fclose($handle);
        
        return array_unique($words);
    }
    
    /**
     * 提取模块的i18n词典数据
     */
    private function extractI18nWords(string $moduleName): array
    {
        $modulePath = BP . 'app' . DS . 'code' . DS . str_replace('_', DS, $moduleName);
        
        if (!is_dir($modulePath)) {
            $this->printing->warning(__('模块目录不存在：%{1}', [$modulePath]));
            return [];
        }
        
        // 查找默认语言的i18n文件（通常是zh_Hans_CN）
        $i18nDir = $modulePath . DS . 'i18n';
        if (!is_dir($i18nDir)) {
            $this->printing->warning(__('模块i18n目录不存在：%{1}', [$i18nDir]));
            return [];
        }
        
        return $this->extractI18nWordsFromDir($i18nDir);
    }
    
    /**
     * 使用AI翻译服务翻译词汇
     */
    private function translateWithAi(array $words, string $targetLocale): array
    {
        $translations = [];
        
        try {
            // 尝试获取AI翻译服务
            if (!class_exists(\Weline\Ai\Service\TranslationService::class)) {
                $this->printing->warning(__('AI翻译服务未找到，请确保AI模块已安装'));
                return $translations;
            }
            
            /** @var \Weline\Ai\Service\TranslationService $translationService */
            $translationService = ObjectManager::getInstance(\Weline\Ai\Service\TranslationService::class);
            
            $this->printing->note(__('正在使用AI翻译服务翻译 %{1} 条词汇...', [count($words)]));
            
            // 批量翻译
            $aiTranslations = $translationService->batchTranslate(
                $words,
                $targetLocale,
                'auto',
                \Weline\Ai\Service\TranslationService::STRATEGY_LIGHT
            );
            
            // 过滤掉翻译失败的（返回原文的）
            foreach ($aiTranslations as $index => $translation) {
                $originalWord = $words[$index];
                if ($translation !== $originalWord && !empty(trim($translation))) {
                    $translations[$originalWord] = $translation;
                }
            }
            
        } catch (\Exception $e) {
            $this->printing->warning(__('AI翻译服务调用失败：%{1}', [$e->getMessage()]));
        }
        
        return $translations;
    }
    
    /**
     * 保存i18n翻译到指定目录的CSV文件
     */
    private function saveI18nTranslationsToDir(string $i18nDir, string $targetLocale, array $translations): void
    {
        $i18nFile = $i18nDir . DS . $targetLocale . '.csv';
        
        // 确保目录存在
        if (!is_dir($i18nDir)) {
            mkdir($i18nDir, 0755, true);
        }
        
        // 读取现有的翻译
        $existingTranslations = [];
        if (file_exists($i18nFile)) {
            $handle = @fopen($i18nFile, 'r');
            if ($handle !== false) {
                while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                    if (isset($data[0]) && isset($data[1])) {
                        $existingTranslations[trim($data[0])] = trim($data[1]);
                    }
                }
                fclose($handle);
            }
        }
        
        // 合并翻译（新翻译覆盖旧的）
        $allTranslations = array_merge($existingTranslations, $translations);
        
        // 写入CSV文件
        $csvFile = @fopen($i18nFile, 'w+');
        if ($csvFile !== false) {
            foreach ($allTranslations as $word => $translation) {
                fputcsv($csvFile, [$word, $translation], ',', '"', '\\');
            }
            fclose($csvFile);
            $this->printing->success(__('已保存翻译到：%{1}', [$i18nFile]));
        } else {
            $this->printing->error(__('无法写入文件：%{1}', [$i18nFile]));
        }
    }
    
    /**
     * 保存i18n翻译到模块目录的CSV文件
     */
    private function saveI18nTranslations(string $moduleName, string $targetLocale, array $translations): void
    {
        $modulePath = BP . 'app' . DS . 'code' . DS . str_replace('_', DS, $moduleName);
        $i18nDir = $modulePath . DS . 'i18n';
        
        $this->saveI18nTranslationsToDir($i18nDir, $targetLocale, $translations);
    }
    
    /**
     * 继承模块（从其他模块拷贝view目录）
     */
    private function inheritModule(string $themeName): void
    {
        $this->printing->note(__('=== 继承模块 ==='));
        
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        
        if (!is_dir($themePath)) {
            $this->printing->error(__('主题目录不存在：%{1}', [$themePath]));
            return;
        }
        
        // 1. 搜索并选择模块
        $this->printing->note(__('请输入要继承的模块名称（例如：Weline_Demo，支持模糊搜索）'));
        $moduleSearch = trim($this->system->input());
        
        if (empty($moduleSearch)) {
            $this->printing->warning(__('模块名称不能为空'));
            return;
        }
        
        // 搜索模块
        $modules = $this->searchModules($moduleSearch);
        
        if (empty($modules)) {
            $this->printing->warning(__('未找到匹配的模块'));
            return;
        }
        
        // 如果只有一个模块，直接使用；否则让用户选择
        $selectedModule = null;
        if (count($modules) === 1) {
            $selectedModule = reset($modules);
            $this->printing->success(__('找到模块：%{1}', [$selectedModule['name']]));
        } else {
            $this->printing->note(__('找到 %{1} 个匹配的模块：', [count($modules)]));
            foreach ($modules as $index => $module) {
                // 将绝对路径转换为相对路径
                $relativePath = str_replace(BP, '', $module['path']);
                $relativePath = ltrim(str_replace(['/', '\\'], DS, $relativePath), DS);
                
                // 判断路径类型并设置颜色
                // 检查是否以 vendor 开头（支持 vendor\ 或 vendor/）
                $isVendor = (strpos($relativePath, 'vendor' . DS) === 0) || 
                           (strpos($relativePath, 'vendor/') === 0) ||
                           (strpos($relativePath, 'vendor\\') === 0);
                $pathColor = $isVendor ? 'Purple' : 'Blue';
                
                // 美化显示：模块名加粗，路径着色
                $moduleNameBold = $this->boldText($module['name']);
                $coloredPath = $this->colorizeText($relativePath, $pathColor);
                
                $this->printing->printing(sprintf(
                    "  %d. %s (%s)\n",
                    $index + 1,
                    $moduleNameBold,
                    $coloredPath
                ));
            }
            $this->printing->note(__('请选择模块（输入序号）'));
            $choice = trim($this->system->input());
            $choiceIndex = intval($choice) - 1;
            
            if (!isset($modules[$choiceIndex])) {
                $this->printing->warning(__('无效的选择'));
                return;
            }
            
            $selectedModule = $modules[$choiceIndex];
        }
        
        // 2. 检查模块的view目录
        $moduleViewPath = $selectedModule['path'] . DS . 'view';
        if (!is_dir($moduleViewPath)) {
            $this->printing->warning(__('模块 %{1} 没有 view 目录', [$selectedModule['name']]));
            return;
        }
        
        // 3. 列出view目录结构
        $this->printing->note(__('模块 %{1} 的 view 目录结构：', [$selectedModule['name']]));
        $viewItems = $this->listViewItems($moduleViewPath);
        
        if (empty($viewItems)) {
            $this->printing->warning(__('view 目录为空'));
            return;
        }
        
        $this->printing->printing("\n");
        foreach ($viewItems as $index => $item) {
            $relativePath = str_replace($moduleViewPath . DS, '', $item['path']);
            $typeLabel = $item['type'] === 'dir' ? __('[目录]') : __('[文件]');
            $this->printing->note(__('  %{1}. %{2} %{3}', [$index + 1, $typeLabel, $relativePath]));
        }
        
        $this->printing->printing("\n");
        $this->printing->note(__('请选择要继承的目录（输入序号，多个用逗号分隔，输入 all 继承全部目录）'));
        $this->printing->note(__('注意：只能选择目录，文件会随目录一起拷贝'));
        $dirChoice = trim($this->system->input());
        
        $selectedDirs = [];
        if (strtolower($dirChoice) === 'all') {
            // 只选择目录
            foreach ($viewItems as $item) {
                if ($item['type'] === 'dir') {
                    $selectedDirs[] = $item['path'];
                }
            }
        } else {
            $choices = array_map('trim', explode(',', $dirChoice));
            foreach ($choices as $choice) {
                $choiceIndex = intval($choice) - 1;
                if (isset($viewItems[$choiceIndex])) {
                    $item = $viewItems[$choiceIndex];
                    // 只允许选择目录
                    if ($item['type'] === 'dir') {
                        $selectedDirs[] = $item['path'];
                    } else {
                        $this->printing->warning(__('跳过文件：%{1}（只能选择目录）', [$item['path']]));
                    }
                }
            }
        }
        
        if (empty($selectedDirs)) {
            $this->printing->warning(__('未选择任何目录'));
            return;
        }
        
        // 4. 确定目标位置（主题目录下的模块目录）
        $moduleNameParts = explode('_', $selectedModule['name'], 2);
        $vendorName = $moduleNameParts[0] ?? 'Weline';
        $moduleDirName = $moduleNameParts[1] ?? $selectedModule['name'];
        
        $targetModulePath = $themePath . DS . $vendorName . DS . $moduleDirName;
        $targetViewPath = $targetModulePath . DS . 'view';
        
        // 5. 确认并拷贝
        $this->printing->printing("\n");
        $this->printing->note(__('将拷贝以下目录到：%{1}', [$targetViewPath]));
        foreach ($selectedDirs as $dir) {
            $relativePath = str_replace($moduleViewPath . DS, '', $dir);
            $this->printing->note(__('  - %{1}', [$relativePath]));
        }
        
        $this->printing->note(__('确认拷贝？(y/n，默认：y)'));
        $confirm = trim(strtolower($this->system->input()));
        if ($confirm !== '' && $confirm !== 'n' && $confirm !== 'no') {
            // 确保目标目录存在
            if (!is_dir($targetModulePath)) {
                mkdir($targetModulePath, 0755, true);
            }
            if (!is_dir($targetViewPath)) {
                mkdir($targetViewPath, 0755, true);
            }
            
            // 拷贝目录
            $copiedCount = 0;
            foreach ($selectedDirs as $sourceDir) {
                $relativePath = str_replace($moduleViewPath . DS, '', $sourceDir);
                $targetDir = $targetViewPath . DS . $relativePath;
                
                if ($this->copyDirectory($sourceDir, $targetDir)) {
                    $copiedCount++;
                    $this->printing->success(__('已拷贝：%{1}', [$relativePath]));
                } else {
                    $this->printing->error(__('拷贝失败：%{1}', [$relativePath]));
                }
            }
            
            $this->printing->printing("\n");
            $this->printing->success(__('成功拷贝 %{1} 个目录', [$copiedCount]));
        } else {
            $this->printing->note(__('已取消操作'));
        }
    }
    
    /**
     * 搜索模块
     */
    private function searchModules(string $search): array
    {
        $modules = [];
        $searchLower = strtolower(trim($search));
        
        if (empty($searchLower)) {
            return $modules;
        }
        
        // 获取所有激活的模块
        $activeModules = Env::getInstance()->getActiveModules();
        
        foreach ($activeModules as $module) {
            $moduleName = $module['name'] ?? '';
            $modulePath = $module['base_path'] ?? '';
            
            if (empty($moduleName) || empty($modulePath)) {
                continue;
            }
            
            $moduleNameLower = strtolower($moduleName);
            $modulePathLower = strtolower($modulePath);
            
            // 多种匹配方式：
            // 1. 完整匹配（忽略大小写）
            // 2. 包含匹配（模块名称中包含搜索词）
            // 3. 下划线分割匹配（如搜索 "Demo" 匹配 "Weline_Demo"）
            // 4. 路径匹配（路径中包含搜索词）
            
            $matched = false;
            
            // 完整匹配
            if ($moduleNameLower === $searchLower) {
                $matched = true;
            }
            // 包含匹配
            elseif (str_contains($moduleNameLower, $searchLower)) {
                $matched = true;
            }
            // 下划线分割匹配（如 "Demo" 匹配 "Weline_Demo"）
            elseif (strpos($moduleName, '_') !== false) {
                $parts = explode('_', $moduleName);
                foreach ($parts as $part) {
                    if (strtolower($part) === $searchLower || str_contains(strtolower($part), $searchLower)) {
                        $matched = true;
                        break;
                    }
                }
            }
            // 路径匹配
            elseif (str_contains($modulePathLower, $searchLower)) {
                $matched = true;
            }
            
            if ($matched) {
                $modules[] = [
                    'name' => $moduleName,
                    'path' => $modulePath
                ];
            }
        }
        
        // 按匹配度排序：完整匹配 > 开头匹配 > 包含匹配
        usort($modules, function($a, $b) use ($searchLower) {
            $aName = strtolower($a['name']);
            $bName = strtolower($b['name']);
            
            // 完整匹配优先
            if ($aName === $searchLower && $bName !== $searchLower) {
                return -1;
            }
            if ($bName === $searchLower && $aName !== $searchLower) {
                return 1;
            }
            
            // 开头匹配次之
            $aStarts = strpos($aName, $searchLower) === 0;
            $bStarts = strpos($bName, $searchLower) === 0;
            if ($aStarts && !$bStarts) {
                return -1;
            }
            if ($bStarts && !$aStarts) {
                return 1;
            }
            
            // 其他按字母顺序
            return strcmp($aName, $bName);
        });
        
        return $modules;
    }
    
    /**
     * 列出view目录下的所有子目录和文件
     */
    private function listViewItems(string $viewPath): array
    {
        $items = [];
        
        if (!is_dir($viewPath)) {
            return $items;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        $viewPathNormalized = rtrim(str_replace(['/', '\\'], DS, $viewPath), DS);
        
        foreach ($iterator as $file) {
            $itemPath = $file->getPathname();
            $itemPathNormalized = rtrim(str_replace(['/', '\\'], DS, $itemPath), DS);
            
            // 排除view目录本身，只包含其子目录和文件
            if ($itemPathNormalized !== $viewPathNormalized) {
                $items[] = [
                    'path' => $itemPath,
                    'type' => $file->isDir() ? 'dir' : 'file'
                ];
            }
        }
        
        // 排序：目录在前，文件在后，然后按路径排序
        usort($items, function($a, $b) {
            // 先按类型排序（目录在前）
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            // 同类型按路径排序
            return strcmp($a['path'], $b['path']);
        });
        
        return $items;
    }
    
    /**
     * 拷贝目录
     */
    private function copyDirectory(string $source, string $destination): bool
    {
        if (!is_dir($source)) {
            return false;
        }
        
        // 确保目标目录的父目录存在
        $parentDir = dirname($destination);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }
        
        // 如果目标目录已存在，先删除
        if (is_dir($destination)) {
            $this->removeDirectory($destination);
        }
        
        // 创建目标目录
        if (!mkdir($destination, 0755, true)) {
            return false;
        }
        
        // 递归拷贝
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $destination . DS . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
        
        return true;
    }
    
    /**
     * 删除目录（递归）
     */
    private function removeDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * 加粗文本（ANSI转义序列）
     */
    private function boldText(string $text): string
    {
        if (PHP_SAPI === 'cli') {
            return "\033[1m{$text}\033[0m";
        }
        return $text;
    }
    
    /**
     * 为文本添加颜色（ANSI转义序列）
     */
    private function colorizeText(string $text, string $color): string
    {
        if (PHP_SAPI !== 'cli') {
            return $text;
        }
        
        $colors = [
            'Blue' => "\033[34m",      // 蓝色
            'Purple' => "\033[35m",    // 紫色（洋红色）
            'Green' => "\033[32m",     // 绿色
            'Yellow' => "\033[33m",    // 黄色
            'Red' => "\033[31m",       // 红色
        ];
        
        $colorCode = $colors[$color] ?? '';
        $reset = "\033[0m";
        
        return $colorCode . $text . $reset;
    }
    
    /**
     * 显示主题选择菜单（当没有指定主题时）
     */
    private function showThemeSelectionMenu(): void
    {
        $this->printing->note(__('=== 主题管理 ==='));
        $this->printing->printing("\n");
        
        // 1. 获取数据库中的主题
        $dbThemes = $this->welineTheme->clearData()->select()->fetch()->getItems();
        
        // 2. 获取目录中的主题
        $dirThemes = $this->scanThemeDirectories();
        
        // 3. 合并并去重
        $allThemes = [];
        foreach ($dbThemes as $theme) {
            $themeName = $theme['name'] ?? '';
            if (!empty($themeName)) {
                $allThemes[$themeName] = [
                    'name' => $themeName,
                    'in_db' => true,
                    'in_dir' => isset($dirThemes[$themeName]),
                    'path' => $theme['path'] ?? ''
                ];
            }
        }
        foreach ($dirThemes as $themeName => $themePath) {
            if (!isset($allThemes[$themeName])) {
                $allThemes[$themeName] = [
                    'name' => $themeName,
                    'in_db' => false,
                    'in_dir' => true,
                    'path' => $themePath
                ];
            } else {
                $allThemes[$themeName]['in_dir'] = true;
                if (empty($allThemes[$themeName]['path'])) {
                    $allThemes[$themeName]['path'] = $themePath;
                }
            }
        }
        
        // 4. 显示菜单
        if (!empty($allThemes)) {
            $this->printing->note(__('已存在的主题：'));
            $themeList = array_values($allThemes);
            foreach ($themeList as $index => $theme) {
                $status = [];
                if ($theme['in_db']) {
                    $status[] = __('[已注册]');
                }
                if ($theme['in_dir']) {
                    $status[] = __('[有目录]');
                }
                $statusStr = !empty($status) ? ' ' . implode(' ', $status) : '';
                
                $themeNameBold = $this->boldText($theme['name']);
                $this->printing->printing(sprintf(
                    "  %d. %s%s\n",
                    $index + 1,
                    $themeNameBold,
                    $statusStr
                ));
            }
            
            $this->printing->printing("\n");
            $this->printing->note(__('请选择操作：'));
            $this->printing->note(__('  输入序号：选择已存在的主题进行操作'));
            $this->printing->note(__('  输入 new：创建新主题'));
            $this->printing->note(__('  输入 0 或直接回车：创建新主题'));
            
            $choice = trim($this->system->input());
            
            if (empty($choice) || $choice === '0' || strtolower($choice) === 'new') {
                // 创建新主题
                $this->interactiveCreate();
                return;
            }
            
            // 选择已存在的主题
            $choiceIndex = intval($choice) - 1;
            if (isset($themeList[$choiceIndex])) {
                $selectedTheme = $themeList[$choiceIndex];
                $this->printing->success(__('已选择主题：%{1}', [$selectedTheme['name']]));
                $this->printing->printing("\n");
                
                // 加载配置并进入二次操作模式
                $themeName = $selectedTheme['name'];
                $this->configFile = $this->getConfigFile($themeName);
                if (file_exists($this->configFile)) {
                    $this->themeConfig = json_decode(file_get_contents($this->configFile), true) ?? [];
                    $this->showThemeConfigSummary();
                } else {
                    // 创建默认配置
                    $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
                    if (is_dir($themePath)) {
                        $this->configFile = $themePath . DS . '.theme-config.json';
                    } else {
                        $this->configFile = BP . 'var' . DS . 'theme_config' . DS . $themeName . '.json';
                    }
                    
                    $this->themeConfig = [
                        'theme_name' => $themeName,
                        'version' => '1.0.0',
                        'description' => '',
                        'parent_theme' => '',
                        'create_template' => false,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'status' => 'in_progress'
                    ];
                    $this->saveConfig(true);
                }
                
                $this->handleSecondaryOperation($themeName);
            } else {
                $this->printing->warning(__('无效的选择，将创建新主题'));
                $this->interactiveCreate();
            }
        } else {
            // 没有已存在的主题，直接创建新主题
            $this->printing->note(__('未找到已存在的主题，将创建新主题'));
            $this->printing->printing("\n");
            $this->interactiveCreate();
        }
    }
    
    /**
     * 扫描主题目录，获取所有主题
     */
    private function scanThemeDirectories(): array
    {
        $themes = [];
        $themeBaseDir = Env::path_THEME_DESIGN_DIR . 'Weline';
        
        if (!is_dir($themeBaseDir)) {
            return $themes;
        }
        
        $dirs = scandir($themeBaseDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $themePath = $themeBaseDir . DS . $dir;
            if (is_dir($themePath)) {
                // 检查是否有 register.php 文件（确认是主题目录）
                $registerFile = $themePath . DS . 'register.php';
                if (file_exists($registerFile)) {
                    $themes[$dir] = $themePath;
                }
            }
        }
        
        return $themes;
    }
    
    /**
     * 检查主题完整性
     */
    private function checkThemeIntegrity(?string $themeName = null): void
    {
        $this->printing->note(__('=== 检查主题完整性 ==='));
        $this->printing->printing("\n");
        
        // 如果没有指定主题，显示主题选择菜单
        if (empty($themeName)) {
            $themeName = $this->selectThemeForCheck();
            if (empty($themeName)) {
                return;
            }
        }
        
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        
        $this->printing->note(__('正在检查主题：%{1}', [$themeName]));
        $this->printing->printing("\n");
        
        $issues = [];
        $warnings = [];
        $info = [];
        
        // 1. 检查主题目录是否存在
        if (!is_dir($themePath)) {
            $issues[] = __('主题目录不存在：%{1}', [$themePath]);
            $this->printing->error(__('❌ 主题目录不存在'));
            $this->printing->printing("\n");
            return;
        } else {
            $info[] = __('✓ 主题目录存在');
        }
        
        // 2. 检查 register.php 文件
        $registerFile = $themePath . DS . 'register.php';
        if (!file_exists($registerFile)) {
            $issues[] = __('缺少 register.php 文件');
            $this->printing->error(__('❌ 缺少 register.php 文件'));
        } else {
            $info[] = __('✓ register.php 文件存在');
            
            // 检查 register.php 内容
            $registerContent = file_get_contents($registerFile);
            if (empty($registerContent)) {
                $issues[] = __('register.php 文件为空');
                $this->printing->error(__('❌ register.php 文件为空'));
            } else {
                // 检查是否包含必要的注册代码
                if (!str_contains($registerContent, 'Register::register')) {
                    $issues[] = __('register.php 文件格式不正确，缺少 Register::register 调用');
                    $this->printing->error(__('❌ register.php 文件格式不正确'));
                } else {
                    $info[] = __('✓ register.php 文件格式正确');
                }
            }
        }
        
        // 3. 检查 README.md 文件（可选）
        $readmeFile = $themePath . DS . 'README.md';
        if (!file_exists($readmeFile)) {
            $warnings[] = __('缺少 README.md 文件（可选）');
            $this->printing->warning(__('⚠ 缺少 README.md 文件（可选）'));
        } else {
            $info[] = __('✓ README.md 文件存在');
        }
        
        // 4. 检查基础目录结构
        $requiredDirs = [
            'view' => __('view 目录（模板目录）'),
        ];
        
        $optionalDirs = [
            'view' . DS . 'templates' => __('view/templates 目录'),
            'view' . DS . 'statics' => __('view/statics 目录'),
            'view' . DS . 'statics' . DS . 'css' => __('view/statics/css 目录'),
            'view' . DS . 'statics' . DS . 'js' => __('view/statics/js 目录'),
            'view' . DS . 'statics' . DS . 'images' => __('view/statics/images 目录'),
        ];
        
        foreach ($requiredDirs as $dir => $desc) {
            $fullPath = $themePath . DS . $dir;
            if (!is_dir($fullPath)) {
                $warnings[] = __('缺少 %{1}', [$desc]);
                $this->printing->warning(__('⚠ 缺少 %{1}', [$desc]));
            } else {
                $info[] = __('✓ %{1} 存在', [$desc]);
            }
        }
        
        // 5. 检查数据库注册状态
        $themeModel = $this->welineTheme->clearData()->load('name', $themeName);
        if (!$themeModel->getId()) {
            $warnings[] = __('主题未在数据库中注册');
            $this->printing->warning(__('⚠ 主题未在数据库中注册'));
        } else {
            $info[] = __('✓ 主题已在数据库中注册');
            
            // 检查注册信息是否匹配
            $dbPath = $themeModel->getData('path');
            $expectedPath = 'Weline' . DS . $themeName;
            if ($dbPath !== $expectedPath) {
                $warnings[] = __('数据库中的路径与目录路径不匹配：%{1} vs %{2}', [$dbPath, $expectedPath]);
                $this->printing->warning(__('⚠ 数据库路径与目录路径不匹配'));
            }
        }
        
        // 6. 检查配置文件
        $configFile = $this->getConfigFile($themeName);
        if (!file_exists($configFile)) {
            $warnings[] = __('缺少主题配置文件：%{1}', [$configFile]);
            $this->printing->warning(__('⚠ 缺少主题配置文件'));
        } else {
            $info[] = __('✓ 主题配置文件存在');
        }
        
        // 7. 检查是否有模块目录（继承的模块）
        $moduleDirs = glob($themePath . DS . '*' . DS . '*', GLOB_ONLYDIR);
        if (!empty($moduleDirs)) {
            $info[] = __('✓ 发现 %{1} 个模块目录', [count($moduleDirs)]);
            foreach ($moduleDirs as $moduleDir) {
                $relativePath = str_replace($themePath . DS, '', $moduleDir);
                $viewPath = $moduleDir . DS . 'view';
                if (is_dir($viewPath)) {
                    $info[] = __('  - %{1} (有 view 目录)', [$relativePath]);
                } else {
                    $warnings[] = __('  - %{1} (缺少 view 目录)', [$relativePath]);
                }
            }
        }
        
        // 输出检查结果摘要
        $this->printing->printing("\n");
        $this->printing->note(__('=== 检查结果摘要 ==='));
        $this->printing->success(__('✓ 正常项：%{1}', [count($info)]));
        if (!empty($warnings)) {
            $this->printing->warning(__('⚠ 警告项：%{1}', [count($warnings)]));
        }
        if (!empty($issues)) {
            $this->printing->error(__('❌ 错误项：%{1}', [count($issues)]));
        }
        
        $this->printing->printing("\n");
        if (empty($issues) && empty($warnings)) {
            $this->printing->success(__('主题完整性检查通过！所有项目正常。'));
        } elseif (empty($issues)) {
            $this->printing->warning(__('主题基本完整，但有一些可选的警告项。'));
        } else {
            $this->printing->error(__('主题存在一些问题，请修复后再使用。'));
        }
    }
    
    /**
     * 选择要检查的主题
     */
    private function selectThemeForCheck(): ?string
    {
        // 获取数据库中的主题
        $dbThemes = $this->welineTheme->clearData()->select()->fetch()->getItems();
        
        // 获取目录中的主题
        $dirThemes = $this->scanThemeDirectories();
        
        // 合并并去重
        $allThemes = [];
        foreach ($dbThemes as $theme) {
            $themeName = $theme['name'] ?? '';
            if (!empty($themeName)) {
                $allThemes[$themeName] = [
                    'name' => $themeName,
                    'in_db' => true,
                    'in_dir' => isset($dirThemes[$themeName]),
                ];
            }
        }
        foreach ($dirThemes as $themeName => $themePath) {
            if (!isset($allThemes[$themeName])) {
                $allThemes[$themeName] = [
                    'name' => $themeName,
                    'in_db' => false,
                    'in_dir' => true,
                ];
            } else {
                $allThemes[$themeName]['in_dir'] = true;
            }
        }
        
        if (empty($allThemes)) {
            $this->printing->warning(__('未找到任何主题'));
            return null;
        }
        
        $this->printing->note(__('请选择要检查的主题：'));
        $themeList = array_values($allThemes);
        foreach ($themeList as $index => $theme) {
            $status = [];
            if ($theme['in_db']) {
                $status[] = __('[已注册]');
            }
            if ($theme['in_dir']) {
                $status[] = __('[有目录]');
            }
            $statusStr = !empty($status) ? ' ' . implode(' ', $status) : '';
            
            $themeNameBold = $this->boldText($theme['name']);
            $this->printing->printing(sprintf(
                "  %d. %s%s\n",
                $index + 1,
                $themeNameBold,
                $statusStr
            ));
        }
        
        $this->printing->printing("\n");
        $this->printing->note(__('请输入序号（输入 0 取消）'));
        $choice = trim($this->system->input());
        
        if ($choice === '0' || empty($choice)) {
            return null;
        }
        
        $choiceIndex = intval($choice) - 1;
        if (isset($themeList[$choiceIndex])) {
            return $themeList[$choiceIndex]['name'];
        }
        
        $this->printing->warning(__('无效的选择'));
        return null;
    }
    
    /**
     * 注册主题到数据库
     */
    private function registerTheme(string $themeName): void
    {
        $this->printing->note(__('=== 注册主题到数据库 ==='));
        $this->printing->printing("\n");
        
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $registerFile = $themePath . DS . 'register.php';
        
        // 检查主题目录是否存在
        if (!is_dir($themePath)) {
            $this->printing->error(__('主题目录不存在：%{1}', [$themePath]));
            return;
        }
        
        // 检查 register.php 文件是否存在
        if (!file_exists($registerFile)) {
            $this->printing->error(__('主题注册文件不存在：%{1}', [$registerFile]));
            $this->printing->note(__('请先创建 register.php 文件'));
            return;
        }
        
        // 检查主题是否已经注册
        $this->welineTheme->clearData();
        $this->welineTheme->load('name', $themeName);
        if ($this->welineTheme->getId()) {
            $status = $this->welineTheme->isActive() ? __('已激活') : __('未激活');
            $this->printing->warning(__('主题 %{1} 已经注册到数据库', [$themeName]));
            $this->printing->note(__('当前状态：%{1}', [$status]));
            $this->printing->note(__('是否重新注册（更新）？(y/n，默认：n)'));
            $confirm = trim(strtolower($this->system->input()));
            if ($confirm !== 'y' && $confirm !== 'yes') {
                $this->printing->note(__('已取消操作'));
                return;
            }
        }
        
        try {
            $this->printing->setup(__('正在注册主题：%{1}', [$themeName]));
            
            // 执行注册文件
            require_once $registerFile;
            
            // 验证注册结果
            $this->welineTheme->clearData();
            $this->welineTheme->load('name', $themeName);
            
            if ($this->welineTheme->getId()) {
                $status = $this->welineTheme->isActive() ? __('已激活') : __('未激活');
                $this->printing->success(__('主题 %{1} 注册成功！', [$themeName]));
                $this->printing->note(__('主题状态：%{1}', [$status]));
                $this->printing->note(__('主题路径：%{1}', [$this->welineTheme->getPath()]));
            } else {
                $this->printing->warning(__('主题注册可能未完成，请检查 register.php 文件'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('注册主题时出错：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 卸载主题（从数据库移除）
     */
    private function uninstallTheme(string $themeName): void
    {
        $this->printing->note(__('=== 卸载主题 ==='));
        $this->printing->printing("\n");
        
        // 检查主题是否已注册
        $this->welineTheme->clearData();
        $this->welineTheme->load('name', $themeName);
        
        if (!$this->welineTheme->getId()) {
            $this->printing->warning(__('主题 %{1} 未在数据库中注册', [$themeName]));
            return;
        }
        
        $isActive = $this->welineTheme->isActive();
        $status = $isActive ? __('已激活') : __('未激活');
        
        $this->printing->note(__('主题名称：%{1}', [$themeName]));
        $this->printing->note(__('主题状态：%{1}', [$status]));
        $this->printing->note(__('主题路径：%{1}', [$this->welineTheme->getPath()]));
        $this->printing->printing("\n");
        
        if ($isActive) {
            $this->printing->warning(__('警告：当前主题处于激活状态，卸载后系统将没有激活的主题！'));
        }
        
        $this->printing->note(__('确认要卸载主题 %{1} 吗？(y/n，默认：n)', [$themeName]));
        $this->printing->note(__('注意：此操作只会从数据库移除主题记录，不会删除主题文件'));
        $this->printing->note(__('卸载前将自动备份主题文件'));
        $confirm = trim(strtolower($this->system->input()));
        
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printing->note(__('已取消操作'));
            return;
        }
        
        try {
            // 第一步：通过事件通知卸载服务执行卸载（备份）
            $this->printing->setup(__('步骤 1/2：正在备份主题文件...'));
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = [
                'type' => UninstallService::TYPE_THEME,
                'name' => $themeName,
                'auto_backup' => true,
            ];
            $eventManager->dispatch('Weline_Framework_UninstallService::uninstall', $eventData);
            
            // 获取卸载结果
            $uninstallResult = $eventData['uninstall_result'] ?? null;
            $backupPath = '';
            if ($uninstallResult && isset($uninstallResult['success'])) {
                if ($uninstallResult['success']) {
                    $backupPath = $uninstallResult['backup_path'] ?? '';
                    $this->printing->success(__('主题备份成功'));
                    if ($backupPath) {
                        $this->printing->note(__('备份路径：%{1}', [$backupPath]));
                    }
                    // 显示卸载步骤
                    if (!empty($uninstallResult['steps'])) {
                        foreach ($uninstallResult['steps'] as $step) {
                            if (isset($step['message'])) {
                                $this->printing->note('  - ' . $step['message']);
                            }
                        }
                    }
                } else {
                    $this->printing->warning(__('主题备份失败：%{1}', [
                        $uninstallResult['message'] ?? __('未知错误')
                    ]));
                }
            }
            $this->printing->printing("\n");
            
            // 第二步：删除主题记录
            $this->printing->setup(__('步骤 2/2：正在卸载主题：%{1}', [$themeName]));
            
            // 删除主题记录
            $result = $this->welineTheme->delete();
            
            if ($result) {
                $this->printing->success(__('主题 %{1} 卸载成功！', [$themeName]));
                $this->printing->note(__('主题文件仍然保留在：%{1}', [Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName]));
                if ($backupPath) {
                    $this->printing->note(__('备份文件已保存到：%{1}', [$backupPath]));
                }
                $this->printing->note(__('如需重新注册，请使用选项 9：注册主题到数据库'));
            } else {
                $this->printing->error(__('主题卸载失败'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('卸载主题时出错：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 备份主题文件
     */
    private function backupTheme(string $themeName): ?string
    {
        try {
            $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
            
            // 检查主题目录是否存在
            if (!is_dir($themePath)) {
                $this->printing->warning(__('主题目录不存在，跳过备份：%{1}', [$themePath]));
                return null;
            }
            
            // 创建备份目录
            $backupDir = BP . 'var' . DS . 'backup' . DS . 'theme' . DS;
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            // 生成备份文件名（包含时间戳）
            $timestamp = date('Y-m-d_H-i-s');
            $backupFileName = $themeName . '_' . $timestamp . '.zip';
            $backupFilePath = $backupDir . $backupFileName;
            
            // 使用 Compress 类压缩主题目录
            /** @var Compress $compress */
            $compress = ObjectManager::getInstance(Compress::class);
            $zipPath = $compress->compression($themePath, $backupFilePath, dirname($themePath));
            
            if (file_exists($zipPath)) {
                // 获取文件大小
                $fileSize = filesize($zipPath);
                $fileSizeFormatted = $this->formatFileSize($fileSize);
                $this->printing->note(__('备份文件大小：%{1}', [$fileSizeFormatted]));
                return $zipPath;
            } else {
                $this->printing->warning(__('备份文件创建失败'));
                return null;
            }
        } catch (\Exception $e) {
            $this->printing->warning(__('备份主题时出错：%{1}', [$e->getMessage()]));
            return null;
        }
    }
    
    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * 重装主题（卸载后重新安装）
     */
    private function reinstallTheme(string $themeName): void
    {
        $this->printing->note(__('=== 重装主题 ==='));
        $this->printing->printing("\n");
        
        $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
        $registerFile = $themePath . DS . 'register.php';
        
        // 检查主题目录是否存在
        if (!is_dir($themePath)) {
            $this->printing->error(__('主题目录不存在：%{1}', [$themePath]));
            return;
        }
        
        // 检查 register.php 文件是否存在
        if (!file_exists($registerFile)) {
            $this->printing->error(__('主题注册文件不存在：%{1}', [$registerFile]));
            $this->printing->note(__('请先创建 register.php 文件'));
            return;
        }
        
        // 检查主题是否已注册
        $this->welineTheme->clearData();
        $this->welineTheme->load('name', $themeName);
        $wasInstalled = $this->welineTheme->getId();
        $wasActive = $wasInstalled && $this->welineTheme->isActive();
        
        if ($wasInstalled) {
            $status = $wasActive ? __('已激活') : __('未激活');
            $this->printing->note(__('主题当前状态：已注册，%{1}', [$status]));
        } else {
            $this->printing->note(__('主题当前状态：未注册'));
        }
        
        $this->printing->printing("\n");
        $this->printing->note(__('重装操作将：'));
        $this->printing->note(__('  1. 从数据库卸载主题（如果已注册）'));
        $this->printing->note(__('  2. 重新注册主题到数据库'));
        if ($wasActive) {
            $this->printing->warning(__('  注意：重装后主题将变为未激活状态，需要手动激活'));
        }
        $this->printing->printing("\n");
        $this->printing->note(__('确认要重装主题 %{1} 吗？(y/n，默认：n)', [$themeName]));
        $confirm = trim(strtolower($this->system->input()));
        
        if ($confirm !== 'y' && $confirm !== 'yes') {
            $this->printing->note(__('已取消操作'));
            return;
        }
        
        try {
            // 第一步：卸载（如果已注册）
            if ($wasInstalled) {
                $this->printing->setup(__('步骤 1/2：正在卸载主题...'));
                $this->welineTheme->clearData();
                $this->welineTheme->load('name', $themeName);
                $uninstallResult = $this->welineTheme->delete();
                
                if ($uninstallResult) {
                    $this->printing->success(__('主题卸载成功'));
                } else {
                    $this->printing->warning(__('主题卸载可能失败，但将继续尝试重新注册'));
                }
                $this->printing->printing("\n");
            }
            
            // 第二步：重新注册
            $this->printing->setup(__('步骤 %{1}/2：正在重新注册主题...', [$wasInstalled ? '2' : '1']));
            
            // 执行注册文件
            require_once $registerFile;
            
            // 验证注册结果
            $this->welineTheme->clearData();
            $this->welineTheme->load('name', $themeName);
            
            if ($this->welineTheme->getId()) {
                $status = $this->welineTheme->isActive() ? __('已激活') : __('未激活');
                $this->printing->success(__('主题 %{1} 重装成功！', [$themeName]));
                $this->printing->note(__('主题状态：%{1}', [$status]));
                $this->printing->note(__('主题路径：%{1}', [$this->welineTheme->getPath()]));
                
                if ($wasActive && !$this->welineTheme->isActive()) {
                    $this->printing->warning(__('提示：主题已重装，但当前为未激活状态'));
                    $this->printing->note(__('如需激活主题，请使用命令：php bin/w theme:activate %{1}', [$themeName]));
                }
            } else {
                $this->printing->error(__('主题重装失败，请检查 register.php 文件'));
            }
        } catch (\Exception $e) {
            $this->printing->error(__('重装主题时出错：%{1}', [$e->getMessage()]));
        }
    }
}

