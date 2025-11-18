<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Uninstall;

use Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Io\File;

/**
 * 统一卸载服务
 * 
 * 提供模块、主题、翻译包的统一卸载、备份、回滚功能
 * 支持通过事件通知机制进行卸载操作
 */
class UninstallService
{
    // 卸载类型常量
    public const TYPE_MODULE = 'module';
    public const TYPE_THEME = 'theme';
    public const TYPE_I18N = 'i18n';

    /**
     * @var Printing
     */
    private Printing $printer;

    /**
     * @var string 备份根目录
     */
    private string $backupBaseDir;

    /**
     * @var string 当前运行用户
     */
    private string $currentUser;

    /**
     * @var string 配置的运行用户
     */
    private string $configUser;

    public function __construct()
    {
        $this->printer = ObjectManager::getInstance(Printing::class);
        $this->backupBaseDir = Env::getUninstallBackupDir();
        $this->currentUser = Env::user();
        $this->configUser = Env::get('user', '');
    }

    /**
     * 检查目录权限
     * 
     * @param string $dir 目录路径
     * @return array ['success' => bool, 'message' => string]
     */
    public function checkDirectoryPermission(string $dir): array
    {
        // 检查目录是否存在
        if (!is_dir($dir)) {
            // 尝试创建目录
            if (!mkdir($dir, 0755, true)) {
                return [
                    'success' => false,
                    'message' => __('无法创建备份目录：%{1}。请检查父目录权限。', [$dir])
                ];
            }
        }

        // 检查目录是否可写
        if (!is_writable($dir)) {
            return [
                'success' => false,
                'message' => __('备份目录不可写：%{1}。请检查目录权限。', [$dir])
            ];
        }

        // 检查用户权限（如果配置了用户）
        if (!empty($this->configUser) && $this->currentUser !== $this->configUser) {
            return [
                'success' => false,
                'message' => __('权限检查失败：当前运行用户 %{1} 与配置的运行用户 %{2} 不匹配。请在 env.php 中配置正确的 user 键，或确保外部目录对配置的用户有权限。', [
                    $this->currentUser,
                    $this->configUser
                ])
            ];
        }

        return [
            'success' => true,
            'message' => __('权限检查通过')
        ];
    }

    /**
     * 执行卸载操作（统一入口）
     * 
     * @param string $type 卸载类型（module/theme/i18n）
     * @param string $name 名称（模块名/主题名/语言代码）
     * @param bool $autoBackup 是否自动备份
     * @return array 统一的卸载返回数据
     */
    public function uninstall(string $type, string $name, bool $autoBackup = true): array
    {
        $result = [
            'success' => false,
            'type' => $type,
            'name' => $name,
            'backup_path' => '',
            'message' => '',
            'steps' => [],
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => '',
        ];

        try {
            // 添加步骤：开始卸载
            $result['steps'][] = [
                'step' => 'start',
                'message' => __('开始卸载 %{1}：%{2}', [$type, $name]),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            // 根据类型执行不同的卸载方法
            switch ($type) {
                case self::TYPE_MODULE:
                    $uninstallResult = $this->uninstallModule($name, $autoBackup);
                    break;
                case self::TYPE_THEME:
                    $uninstallResult = $this->uninstallTheme($name, $autoBackup);
                    break;
                case self::TYPE_I18N:
                    $uninstallResult = $this->uninstallI18n($name, $autoBackup);
                    break;
                default:
                    throw new Exception(__('不支持的卸载类型：%{1}', [$type]));
            }

            // 合并结果
            $result['success'] = $uninstallResult['success'];
            $result['backup_path'] = $uninstallResult['backup_path'] ?? '';
            $result['message'] = $uninstallResult['message'] ?? '';
            $result['steps'] = array_merge($result['steps'], $uninstallResult['steps'] ?? []);

            // 添加步骤：完成卸载
            $result['steps'][] = [
                'step' => 'complete',
                'message' => $result['success'] ? __('卸载完成') : __('卸载失败'),
                'time' => date('Y-m-d H:i:s'),
                'success' => $result['success'],
            ];

        } catch (Exception $e) {
            $result['success'] = false;
            $result['message'] = __('卸载异常：%{1}', [$e->getMessage()]);
            $result['steps'][] = [
                'step' => 'error',
                'message' => $e->getMessage(),
                'time' => date('Y-m-d H:i:s'),
                'success' => false,
            ];
        }

        $result['end_time'] = date('Y-m-d H:i:s');
        return $result;
    }

    /**
     * 备份模块
     * 
     * @param string $moduleName 模块名
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string]
     */
    public function backupModule(string $moduleName): array
    {
        try {
            // 获取模块信息
            $moduleInfo = Env::getInstance()->getModuleInfo($moduleName);
            if (!$moduleInfo || !isset($moduleInfo['base_path'])) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => __('模块 %{1} 不存在', [$moduleName])
                ];
            }

            $modulePath = $moduleInfo['base_path'];
            $backupDir = $this->backupBaseDir . self::TYPE_MODULE . DS . $moduleName . DS . date('Y-m-d_H-i-s');

            // 检查权限
            $permissionCheck = $this->checkDirectoryPermission($this->backupBaseDir);
            if (!$permissionCheck['success']) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => $permissionCheck['message']
                ];
            }

            // 创建备份目录
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            // 复制模块文件
            $this->copyDirectory($modulePath, $backupDir);

            // 保存备份信息
            $backupInfo = [
                'type' => self::TYPE_MODULE,
                'name' => $moduleName,
                'backup_time' => date('Y-m-d H:i:s'),
                'backup_path' => $backupDir,
                'module_path' => $modulePath,
                'module_info' => $moduleInfo,
            ];
            file_put_contents($backupDir . DS . 'backup_info.json', json_encode($backupInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return [
                'success' => true,
                'backup_path' => $backupDir,
                'message' => __('模块备份成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('备份失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 备份主题
     * 
     * @param string $themeName 主题名
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string]
     */
    public function backupTheme(string $themeName): array
    {
        try {
            $themePath = Env::path_THEME_DESIGN_DIR . 'Weline' . DS . $themeName;
            
            if (!is_dir($themePath)) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => __('主题目录不存在：%{1}', [$themePath])
                ];
            }

            $backupDir = $this->backupBaseDir . self::TYPE_THEME . DS . $themeName . DS . date('Y-m-d_H-i-s');

            // 检查权限
            $permissionCheck = $this->checkDirectoryPermission($this->backupBaseDir);
            if (!$permissionCheck['success']) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => $permissionCheck['message']
                ];
            }

            // 创建备份目录
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            // 复制主题文件
            $this->copyDirectory($themePath, $backupDir);

            // 保存备份信息
            $backupInfo = [
                'type' => self::TYPE_THEME,
                'name' => $themeName,
                'backup_time' => date('Y-m-d H:i:s'),
                'backup_path' => $backupDir,
                'theme_path' => $themePath,
            ];
            file_put_contents($backupDir . DS . 'backup_info.json', json_encode($backupInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return [
                'success' => true,
                'backup_path' => $backupDir,
                'message' => __('主题备份成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('备份失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 备份翻译包
     * 
     * @param string $localeCode 语言代码
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string]
     */
    public function backupI18n(string $localeCode): array
    {
        try {
            $i18nPath = Env::path_LANGUAGE_PACK . 'Weline' . DS . $localeCode;
            
            if (!is_dir($i18nPath)) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => __('翻译包目录不存在：%{1}', [$i18nPath])
                ];
            }

            $backupDir = $this->backupBaseDir . self::TYPE_I18N . DS . $localeCode . DS . date('Y-m-d_H-i-s');

            // 检查权限
            $permissionCheck = $this->checkDirectoryPermission($this->backupBaseDir);
            if (!$permissionCheck['success']) {
                return [
                    'success' => false,
                    'backup_path' => '',
                    'message' => $permissionCheck['message']
                ];
            }

            // 创建备份目录
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            // 复制翻译包文件
            $this->copyDirectory($i18nPath, $backupDir);

            // 保存备份信息
            $backupInfo = [
                'type' => self::TYPE_I18N,
                'name' => $localeCode,
                'backup_time' => date('Y-m-d H:i:s'),
                'backup_path' => $backupDir,
                'i18n_path' => $i18nPath,
            ];
            file_put_contents($backupDir . DS . 'backup_info.json', json_encode($backupInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return [
                'success' => true,
                'backup_path' => $backupDir,
                'message' => __('翻译包备份成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('备份失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 卸载模块（带备份）
     * 
     * @param string $moduleName 模块名
     * @param bool $autoBackup 是否自动备份
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string, 'steps' => array]
     */
    public function uninstallModule(string $moduleName, bool $autoBackup = true): array
    {
        $steps = [];
        try {
            $backupPath = '';
            
            // 自动备份
            if ($autoBackup) {
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('开始备份模块'),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
                
                $backupResult = $this->backupModule($moduleName);
                if (!$backupResult['success']) {
                    $steps[] = [
                        'step' => 'backup',
                        'message' => __('备份失败：%{1}', [$backupResult['message']]),
                        'time' => date('Y-m-d H:i:s'),
                        'success' => false,
                    ];
                    return [
                        'success' => false,
                        'backup_path' => '',
                        'message' => __('备份失败，取消卸载：%{1}', [$backupResult['message']]),
                        'steps' => $steps,
                    ];
                }
                $backupPath = $backupResult['backup_path'];
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('模块备份成功：%{1}', [$backupPath]),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
            }

            // 触发卸载前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_uninstall_module', [
                'module_name' => $moduleName,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'before_uninstall',
                'message' => __('触发卸载前事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            // 执行卸载逻辑（这里只处理备份，实际卸载由调用方处理）
            // 因为卸载涉及数据库操作等，应该由具体的模块管理器处理

            // 触发卸载后事件
            $eventManager->dispatch('Framework_UninstallService::after_uninstall_module', [
                'module_name' => $moduleName,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'after_uninstall',
                'message' => __('触发卸载后事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'message' => __('模块卸载完成（已备份）'),
                'steps' => $steps,
            ];
        } catch (Exception $e) {
            $steps[] = [
                'step' => 'error',
                'message' => __('卸载异常：%{1}', [$e->getMessage()]),
                'time' => date('Y-m-d H:i:s'),
                'success' => false,
            ];
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('卸载失败：%{1}', [$e->getMessage()]),
                'steps' => $steps,
            ];
        }
    }

    /**
     * 卸载主题（带备份）
     * 
     * @param string $themeName 主题名
     * @param bool $autoBackup 是否自动备份
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string, 'steps' => array]
     */
    public function uninstallTheme(string $themeName, bool $autoBackup = true): array
    {
        $steps = [];
        try {
            $backupPath = '';
            
            // 自动备份
            if ($autoBackup) {
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('开始备份主题'),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
                
                $backupResult = $this->backupTheme($themeName);
                if (!$backupResult['success']) {
                    $steps[] = [
                        'step' => 'backup',
                        'message' => __('备份失败：%{1}', [$backupResult['message']]),
                        'time' => date('Y-m-d H:i:s'),
                        'success' => false,
                    ];
                    return [
                        'success' => false,
                        'backup_path' => '',
                        'message' => __('备份失败，取消卸载：%{1}', [$backupResult['message']]),
                        'steps' => $steps,
                    ];
                }
                $backupPath = $backupResult['backup_path'];
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('主题备份成功：%{1}', [$backupPath]),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
            }

            // 触发卸载前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_uninstall_theme', [
                'theme_name' => $themeName,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'before_uninstall',
                'message' => __('触发卸载前事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            // 触发卸载后事件
            $eventManager->dispatch('Framework_UninstallService::after_uninstall_theme', [
                'theme_name' => $themeName,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'after_uninstall',
                'message' => __('触发卸载后事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'message' => __('主题卸载完成（已备份）'),
                'steps' => $steps,
            ];
        } catch (Exception $e) {
            $steps[] = [
                'step' => 'error',
                'message' => __('卸载异常：%{1}', [$e->getMessage()]),
                'time' => date('Y-m-d H:i:s'),
                'success' => false,
            ];
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('卸载失败：%{1}', [$e->getMessage()]),
                'steps' => $steps,
            ];
        }
    }

    /**
     * 卸载翻译包（带备份）
     * 
     * @param string $localeCode 语言代码
     * @param bool $autoBackup 是否自动备份
     * @return array ['success' => bool, 'backup_path' => string, 'message' => string, 'steps' => array]
     */
    public function uninstallI18n(string $localeCode, bool $autoBackup = true): array
    {
        $steps = [];
        try {
            $backupPath = '';
            
            // 自动备份
            if ($autoBackup) {
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('开始备份翻译包'),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
                
                $backupResult = $this->backupI18n($localeCode);
                if (!$backupResult['success']) {
                    $steps[] = [
                        'step' => 'backup',
                        'message' => __('备份失败：%{1}', [$backupResult['message']]),
                        'time' => date('Y-m-d H:i:s'),
                        'success' => false,
                    ];
                    return [
                        'success' => false,
                        'backup_path' => '',
                        'message' => __('备份失败，取消卸载：%{1}', [$backupResult['message']]),
                        'steps' => $steps,
                    ];
                }
                $backupPath = $backupResult['backup_path'];
                $steps[] = [
                    'step' => 'backup',
                    'message' => __('翻译包备份成功：%{1}', [$backupPath]),
                    'time' => date('Y-m-d H:i:s'),
                    'success' => true,
                ];
            }

            // 触发卸载前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_uninstall_i18n', [
                'locale_code' => $localeCode,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'before_uninstall',
                'message' => __('触发卸载前事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            // 触发卸载后事件
            $eventManager->dispatch('Framework_UninstallService::after_uninstall_i18n', [
                'locale_code' => $localeCode,
                'backup_path' => $backupPath,
            ]);

            $steps[] = [
                'step' => 'after_uninstall',
                'message' => __('触发卸载后事件'),
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
            ];

            return [
                'success' => true,
                'backup_path' => $backupPath,
                'message' => __('翻译包卸载完成（已备份）'),
                'steps' => $steps,
            ];
        } catch (Exception $e) {
            $steps[] = [
                'step' => 'error',
                'message' => __('卸载异常：%{1}', [$e->getMessage()]),
                'time' => date('Y-m-d H:i:s'),
                'success' => false,
            ];
            return [
                'success' => false,
                'backup_path' => '',
                'message' => __('卸载失败：%{1}', [$e->getMessage()]),
                'steps' => $steps,
            ];
        }
    }

    /**
     * 回滚模块
     * 
     * @param string $moduleName 模块名
     * @param string|null $backupPath 备份路径，如果为 null 则使用最新的备份
     * @return array ['success' => bool, 'message' => string]
     */
    public function rollbackModule(string $moduleName, ?string $backupPath = null): array
    {
        try {
            // 如果没有指定备份路径，查找最新的备份
            if ($backupPath === null) {
                $backupDir = $this->backupBaseDir . self::TYPE_MODULE . DS . $moduleName;
                if (!is_dir($backupDir)) {
                    return [
                        'success' => false,
                        'message' => __('未找到模块 %{1} 的备份', [$moduleName])
                    ];
                }

                // 查找所有备份目录
                $backups = glob($backupDir . DS . '*', GLOB_ONLYDIR);
                if (empty($backups)) {
                    return [
                        'success' => false,
                        'message' => __('未找到模块 %{1} 的备份', [$moduleName])
                    ];
                }

                // 使用最新的备份
                rsort($backups);
                $backupPath = $backups[0];
            }

            // 读取备份信息
            $backupInfoFile = $backupPath . DS . 'backup_info.json';
            if (!is_file($backupInfoFile)) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件不存在')
                ];
            }

            $backupInfo = json_decode(file_get_contents($backupInfoFile), true);
            if (!$backupInfo || !isset($backupInfo['module_path'])) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件格式错误')
                ];
            }

            $targetPath = $backupInfo['module_path'];

            // 触发回滚前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_rollback_module', [
                'module_name' => $moduleName,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            // 恢复文件
            $this->copyDirectory($backupPath, $targetPath, ['backup_info.json']);

            // 触发回滚后事件
            $eventManager->dispatch('Framework_UninstallService::after_rollback_module', [
                'module_name' => $moduleName,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            return [
                'success' => true,
                'message' => __('模块回滚成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('回滚失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 回滚主题
     * 
     * @param string $themeName 主题名
     * @param string|null $backupPath 备份路径，如果为 null 则使用最新的备份
     * @return array ['success' => bool, 'message' => string]
     */
    public function rollbackTheme(string $themeName, ?string $backupPath = null): array
    {
        try {
            // 如果没有指定备份路径，查找最新的备份
            if ($backupPath === null) {
                $backupDir = $this->backupBaseDir . self::TYPE_THEME . DS . $themeName;
                if (!is_dir($backupDir)) {
                    return [
                        'success' => false,
                        'message' => __('未找到主题 %{1} 的备份', [$themeName])
                    ];
                }

                $backups = glob($backupDir . DS . '*', GLOB_ONLYDIR);
                if (empty($backups)) {
                    return [
                        'success' => false,
                        'message' => __('未找到主题 %{1} 的备份', [$themeName])
                    ];
                }

                rsort($backups);
                $backupPath = $backups[0];
            }

            $backupInfoFile = $backupPath . DS . 'backup_info.json';
            if (!is_file($backupInfoFile)) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件不存在')
                ];
            }

            $backupInfo = json_decode(file_get_contents($backupInfoFile), true);
            if (!$backupInfo || !isset($backupInfo['theme_path'])) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件格式错误')
                ];
            }

            $targetPath = $backupInfo['theme_path'];

            // 触发回滚前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_rollback_theme', [
                'theme_name' => $themeName,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            // 恢复文件
            $this->copyDirectory($backupPath, $targetPath, ['backup_info.json']);

            // 触发回滚后事件
            $eventManager->dispatch('Framework_UninstallService::after_rollback_theme', [
                'theme_name' => $themeName,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            return [
                'success' => true,
                'message' => __('主题回滚成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('回滚失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 回滚翻译包
     * 
     * @param string $localeCode 语言代码
     * @param string|null $backupPath 备份路径，如果为 null 则使用最新的备份
     * @return array ['success' => bool, 'message' => string]
     */
    public function rollbackI18n(string $localeCode, ?string $backupPath = null): array
    {
        try {
            // 如果没有指定备份路径，查找最新的备份
            if ($backupPath === null) {
                $backupDir = $this->backupBaseDir . self::TYPE_I18N . DS . $localeCode;
                if (!is_dir($backupDir)) {
                    return [
                        'success' => false,
                        'message' => __('未找到翻译包 %{1} 的备份', [$localeCode])
                    ];
                }

                $backups = glob($backupDir . DS . '*', GLOB_ONLYDIR);
                if (empty($backups)) {
                    return [
                        'success' => false,
                        'message' => __('未找到翻译包 %{1} 的备份', [$localeCode])
                    ];
                }

                rsort($backups);
                $backupPath = $backups[0];
            }

            $backupInfoFile = $backupPath . DS . 'backup_info.json';
            if (!is_file($backupInfoFile)) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件不存在')
                ];
            }

            $backupInfo = json_decode(file_get_contents($backupInfoFile), true);
            if (!$backupInfo || !isset($backupInfo['i18n_path'])) {
                return [
                    'success' => false,
                    'message' => __('备份信息文件格式错误')
                ];
            }

            $targetPath = $backupInfo['i18n_path'];

            // 触发回滚前事件
            $eventManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
            $eventManager->dispatch('Framework_UninstallService::before_rollback_i18n', [
                'locale_code' => $localeCode,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            // 恢复文件
            $this->copyDirectory($backupPath, $targetPath, ['backup_info.json']);

            // 触发回滚后事件
            $eventManager->dispatch('Framework_UninstallService::after_rollback_i18n', [
                'locale_code' => $localeCode,
                'backup_path' => $backupPath,
                'target_path' => $targetPath,
            ]);

            return [
                'success' => true,
                'message' => __('翻译包回滚成功')
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => __('回滚失败：%{1}', [$e->getMessage()])
            ];
        }
    }

    /**
     * 复制目录
     * 
     * @param string $source 源目录
     * @param string $destination 目标目录
     * @param array $exclude 排除的文件/目录列表
     * @return void
     */
    private function copyDirectory(string $source, string $destination, array $exclude = []): void
    {
        if (!is_dir($source)) {
            return;
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = str_replace($source . DS, '', $item->getPathname());
            
            // 检查是否在排除列表中
            $shouldExclude = false;
            foreach ($exclude as $excludeItem) {
                if (strpos($relativePath, $excludeItem) === 0) {
                    $shouldExclude = true;
                    break;
                }
            }
            if ($shouldExclude) {
                continue;
            }

            $destPath = $destination . DS . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                copy($item->getPathname(), $destPath);
            }
        }
    }

    /**
     * 获取备份列表
     * 
     * @param string $type 类型（module/theme/i18n）
     * @param string $name 名称
     * @return array
     */
    public function getBackupList(string $type, string $name): array
    {
        $backupDir = $this->backupBaseDir . $type . DS . $name;
        
        if (!is_dir($backupDir)) {
            return [];
        }

        $backups = glob($backupDir . DS . '*', GLOB_ONLYDIR);
        $result = [];

        foreach ($backups as $backup) {
            $backupInfoFile = $backup . DS . 'backup_info.json';
            if (is_file($backupInfoFile)) {
                $info = json_decode(file_get_contents($backupInfoFile), true);
                if ($info) {
                    $result[] = [
                        'path' => $backup,
                        'time' => $info['backup_time'] ?? '',
                        'info' => $info,
                    ];
                }
            }
        }

        // 按时间倒序排序
        usort($result, function ($a, $b) {
            return strcmp($b['time'], $a['time']);
        });

        return $result;
    }
}

