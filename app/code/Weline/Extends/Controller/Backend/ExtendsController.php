<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Extends\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Extends\Model\ExtendsRule;
use Weline\Extends\Service\CircularDependencyDetector;
use Weline\Framework\Manager\MessageManager;

/**
 * 扩展管理控制器
 */
class ExtendsController extends BackendController
{
    /**
     * 扩展列表页面
     */
    public function index()
    {
        /** @var ExtendsRule $extendsRule */
        $extendsRule = ObjectManager::getInstance(ExtendsRule::class);
        $allExtends = $extendsRule->getAllExtends();

        $this->assign('extends', $allExtends);
        $this->assign('title', __('扩展管理'));
        return $this->fetch();
    }

    /**
     * 模块扩展详情
     */
    public function detail()
    {
        $moduleName = $this->request->getParam('module');
        if (empty($moduleName)) {
            MessageManager::warning(__('请指定模块名'));
            $this->redirect('*/index');
            return;
        }

        /** @var ExtendsRule $extendsRule */
        $extendsRule = ObjectManager::getInstance(ExtendsRule::class);
        $moduleExtends = $extendsRule->getModuleExtends($moduleName);
        $extendedBy = $extendsRule->getExtendedBy($moduleName);

        $this->assign('module', $moduleName);
        $this->assign('extends', $moduleExtends);
        $this->assign('extended_by', $extendedBy);
        $this->assign('title', __('模块扩展详情') . ': ' . $moduleName);
        return $this->fetch();
    }

    /**
     * 循环依赖检测
     */
    public function checkCircular()
    {
        try {
            /** @var CircularDependencyDetector $detector */
            $detector = ObjectManager::getInstance(CircularDependencyDetector::class);
            $cycles = $detector->detectAll();

            if (empty($cycles)) {
                MessageManager::success(__('未检测到循环依赖'));
            } else {
                MessageManager::error(__('检测到循环依赖，请查看详情'));
                $this->assign('cycles', $cycles);
            }
        } catch (\Exception $e) {
            MessageManager::error($e->getMessage());
        }

        $this->assign('title', __('循环依赖检测'));
        return $this->fetch();
    }

    /**
     * 刷新扩展注册表
     */
    public function refresh()
    {
        try {
            /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
            $result = $registry->refresh();

            if ($result) {
                MessageManager::success(__('扩展注册表刷新成功'));
            } else {
                MessageManager::error(__('扩展注册表刷新失败'));
            }
        } catch (\Exception $e) {
            MessageManager::error(__('刷新失败: %1', $e->getMessage()));
        }

        $this->redirect('*/index');
    }

    /**
     * Sticker 扩展统计页面
     */
    public function stickerStats()
    {
        try {
            /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
            
            // 获取所有 Sticker 扩展
            $allStickerExtensions = $registry->getAllStickerExtensions();
            
            // 获取扩展统计信息
            $stats = $registry->getExtensionStats();
            
            // 获取被 Sticker 扩展最多的模块
            $mostExtendedModules = [];
            foreach ($allStickerExtensions as $sourceModule => $extensions) {
                foreach ($extensions as $extension) {
                    $targetModule = $extension['target_module'] ?? 'unknown';
                    if (!isset($mostExtendedModules[$targetModule])) {
                        $mostExtendedModules[$targetModule] = 0;
                    }
                    $mostExtendedModules[$targetModule]++;
                }
            }
            
            // 按扩展数量排序
            arsort($mostExtendedModules);
            $mostExtendedModules = array_slice($mostExtendedModules, 0, 10, true);
            
            // 获取使用 Sticker 最多的模块
            $mostStickerModules = [];
            foreach ($allStickerExtensions as $sourceModule => $extensions) {
                $count = count($extensions);
                $mostStickerModules[$sourceModule] = $count;
            }
            arsort($mostStickerModules);
            $mostStickerModules = array_slice($mostStickerModules, 0, 10, true);
            
            $this->assign('all_sticker_extensions', $allStickerExtensions);
            $this->assign('stats', $stats);
            $this->assign('most_extended_modules', $mostExtendedModules);
            $this->assign('most_sticker_modules', $mostStickerModules);
            $this->assign('title', __('Sticker 扩展统计'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            MessageManager::error(__('加载 Sticker 统计失败: %1', $e->getMessage()));
            $this->redirect('*/index');
        }
    }

    /**
     * 模块扩展详情页面
     */
    public function moduleDetail()
    {
        try {
            $moduleName = $this->request->getParam('module');
            if (empty($moduleName)) {
                MessageManager::warning(__('请指定模块名'));
                if ($this->request->isAjax() || $this->request->getGet('isIframe')) {
                    $this->assign('error', __('请指定模块名'));
                    return $this->fetch('templates/Backend/Extends/error.phtml');
                }
                $this->redirect('*/index');
                return;
            }

            /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
            
            // 获取模块的扩展信息
            $moduleExtends = $registry->getModuleExtends($moduleName);
            
            // 获取模块被扩展的信息（按类型分组）
            $extendedBy = $registry->getModuleExtendedBy($moduleName);
            
            // 获取 Sticker 扩展信息
            $stickerExtensions = $registry->getModuleStickerExtensions($moduleName);
            
            // 检查模块是否有特定类型的扩展
            $hasStickerExtensions = $registry->isStickerExtended($moduleName);
            $hasModuleExtensions = !empty($extendedBy['module_extensions']);
            $hasThemeExtensions = !empty($extendedBy['theme_extensions']);
            
            $this->assign('module', $moduleName);
            $this->assign('module_extends', $moduleExtends);
            $this->assign('extended_by', $extendedBy);
            $this->assign('sticker_extensions', $stickerExtensions);
            $this->assign('has_sticker_extensions', $hasStickerExtensions);
            $this->assign('has_module_extensions', $hasModuleExtensions);
            $this->assign('has_theme_extensions', $hasThemeExtensions);
            $this->assign('title', __('模块扩展详情') . ': ' . $moduleName);
            
            return $this->fetch();
        } catch (\Exception $e) {
            MessageManager::error(__('加载模块详情失败: %1', $e->getMessage()));
            $this->redirect('*/index');
        }
    }

    /**
     * 扩展搜索页面
     */
    public function search()
    {
        try {
            $searchTerm = trim($this->request->getGet('q', ''));
            $searchType = $this->request->getGet('type', 'all'); // all, sticker, module, theme
            
            /** @var \Weline\Framework\Extends\ExtendsRegistry $registry */
            $registry = ObjectManager::getInstance(\Weline\Framework\Extends\ExtendsRegistry::class);
            
            $results = [];
            
            if (!empty($searchTerm)) {
                $allRegistry = $registry->getRegistry();
                
                foreach ($allRegistry as $moduleName => $data) {
                    $moduleResults = [];
                    
                    // 搜索模块名
                    if (stripos($moduleName, $searchTerm) !== false) {
                        $moduleResults[] = [
                            'type' => 'module_name',
                            'module' => $moduleName,
                            'data' => $data
                        ];
                    }
                    
                    // 搜索扩展定义
                    if (isset($data['extends']['extends'])) {
                        foreach ($data['extends']['extends'] as $extendName => $extendConfig) {
                            if (stripos($extendName, $searchTerm) !== false || 
                                stripos($extendConfig['description'] ?? '', $searchTerm) !== false) {
                                $moduleResults[] = [
                                    'type' => 'extends_definition',
                                    'module' => $moduleName,
                                    'extend_name' => $extendName,
                                    'data' => $extendConfig
                                ];
                            }
                        }
                    }
                    
                    // 搜索被扩展信息
                    if (isset($data['extended_by'])) {
                        foreach ($data['extended_by'] as $sourceModule => $extensions) {
                            if (stripos($sourceModule, $searchTerm) !== false) {
                                $moduleResults[] = [
                                    'type' => 'extended_by',
                                    'module' => $moduleName,
                                    'source_module' => $sourceModule,
                                    'data' => $extensions
                                ];
                            }
                            
                            // 搜索扩展信息
                            foreach ($extensions as $extension) {
                                $filePath = $extension['file_path'] ?? '';
                                if (stripos($filePath, $searchTerm) !== false) {
                                    $moduleResults[] = [
                                        'type' => 'extension_file',
                                        'module' => $moduleName,
                                        'source_module' => $sourceModule,
                                        'extension' => $extension
                                    ];
                                }
                            }
                        }
                    }
                    
                    if (!empty($moduleResults)) {
                        $results[$moduleName] = $moduleResults;
                    }
                }
                
                // 按类型过滤
                if ($searchType !== 'all') {
                    foreach ($results as $moduleName => $moduleResults) {
                        $filteredResults = [];
                        foreach ($moduleResults as $result) {
                            if ($searchType === 'sticker' && ($result['extension']['is_sticker_extension'] ?? false)) {
                                $filteredResults[] = $result;
                            } elseif ($searchType === 'module' && ($result['extension']['type'] ?? '') === 'module') {
                                $filteredResults[] = $result;
                            } elseif ($searchType === 'theme' && ($result['extension']['type'] ?? '') === 'theme') {
                                $filteredResults[] = $result;
                            } elseif (in_array($searchType, ['module_name', 'extends_definition', 'extended_by'])) {
                                $filteredResults[] = $result;
                            }
                        }
                        if (empty($filteredResults)) {
                            unset($results[$moduleName]);
                        } else {
                            $results[$moduleName] = $filteredResults;
                        }
                    }
                }
            }
            
            $this->assign('search_term', $searchTerm);
            $this->assign('search_type', $searchType);
            $this->assign('results', $results);
            $this->assign('title', __('扩展搜索'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            MessageManager::error(__('搜索失败: %1', $e->getMessage()));
            $this->redirect('*/index');
        }
    }
}

