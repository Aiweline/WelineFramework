<?php

declare(strict_types=1);

/*
 * 组件API控制器 - 负责组件相关的API请求
 * 遵循单一职责原则(SRP) - 只负责组件API
 */

namespace GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\ComponentService;

class Component extends BackendController
{
    private ComponentService $componentService;
    
    public function __construct()
    {
        $this->componentService = ObjectManager::getInstance(ComponentService::class);
    }
    
    /**
     * API: 获取组件列表
     * GET /backend/visual/api/component/list
     * 
     * 返回结构：
     * - recommended: 推荐组件（当前模板专属）
     * - shared: 共享组件（跨模板通用）
     * - other_templates: 其他模板的兼容组件
     * - by_category: 按分类分组
     * - by_region: 按区域分组（如果指定了布局）
     */
    public function list()
    {
        try {
            $styleCode = $this->request->getParam('style_code', '');
            $layoutCode = $this->request->getParam('layout_code', '');
            $includeCompatible = $this->request->getParam('include_compatible', '1') === '1';
            
            // 先扫描共享组件
            $this->componentService->scanAndRegister('_shared');
            
            // 扫描当前模板组件
            if ($styleCode) {
                $this->componentService->scanAndRegister($styleCode);
            }
            
            // 获取为构建器格式化的组件数据
            $data = $this->componentService->getComponentsForBuilder($styleCode, $layoutCode ?: null);
            
            return $this->fetchJson([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取组件列表（旧版兼容）
     * GET /backend/visual/api/component/listLegacy
     */
    public function listLegacy()
    {
        try {
            $styleCode = $this->request->getParam('style_code', '');
            $includeCompatible = $this->request->getParam('include_compatible', '1') === '1';
            
            // 扫描组件
            $this->componentService->scanAndRegister($styleCode);
            
            // 获取组件
            $components = $this->componentService->getComponentsByStyle($styleCode, $includeCompatible);
            
            $result = [
                'success' => true,
                'own' => $this->componentService->toArrayBatch($components['own']),
                'shared' => $this->componentService->toArrayBatch($components['shared'] ?? []),
                'compatible' => [],
            ];
            
            // 转换兼容组件
            foreach ($components['compatible'] as $templateCode => $templateComponents) {
                $result['compatible'][$templateCode] = $this->componentService->toArrayBatch($templateComponents);
            }
            
            return $this->fetchJson($result);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 预览组件
     * POST /backend/visual/api/component/preview
     */
    public function preview()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            $config = $this->request->getParam('config', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            $configArray = json_decode($config, true) ?: [];
            $html = $this->componentService->renderPreview($componentCode, $configArray);
            
            $component = $this->componentService->getByCode($componentCode);
            
            return $this->fetchJson([
                'success' => true,
                'html' => $html,
                'component' => $component ? $this->componentService->toArray($component) : null,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 获取单个组件信息
     * GET /backend/visual/api/component/info
     */
    public function info()
    {
        try {
            $componentCode = $this->request->getParam('component_code', '');
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            $component = $this->componentService->getByCode($componentCode);
            
            if (!$component) {
                throw new \Exception('组件不存在');
            }
            
            return $this->fetchJson([
                'success' => true,
                'component' => $this->componentService->toArray($component),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 添加组件到布局
     * POST /backend/visual/api/component/add
     */
    public function add()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $replace = $body['replace'] ?? false;
            $templateCode = $body['template_code'] ?? '';
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 获取页面模型
            $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
            $page = clone $pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 获取当前布局配置
            $layoutConfigJson = $page->getData('layout_config') ?: '{}';
            $layoutConfig = json_decode($layoutConfigJson, true) ?: [];
            
            // 初始化区域数组
            if (!isset($layoutConfig[$region])) {
                $layoutConfig[$region] = [];
            }
            
            // 检查是否需要替换
            if ($replace && !in_array($region, ['content'])) {
                // header 和 footer 只能有一个组件，替换
                $layoutConfig[$region] = [];
            }
            
            // 添加新组件
            $newComponent = [
                'code' => $componentCode,
                'enabled' => true,
                'config' => [],
                'template_code' => $templateCode,
            ];
            
            // content 区域可以多个，添加到末尾
            // header/footer 区域只能一个
            if ($region === 'content') {
                $layoutConfig[$region][] = $newComponent;
            } else {
                $layoutConfig[$region] = [$newComponent];
            }
            
            // 保存布局配置
            $page->setData('layout_config', json_encode($layoutConfig, JSON_UNESCAPED_UNICODE));
            $page->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('组件已添加'),
                'layout_config' => $layoutConfig,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 从布局中移除组件
     * POST /backend/visual/api/component/remove
     */
    public function remove()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $index = $body['index'] ?? null;
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode && $index === null) {
                throw new \Exception('缺少组件代码或索引');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 获取页面模型
            $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
            $page = clone $pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 获取当前布局配置
            $layoutConfigJson = $page->getData('layout_config') ?: '{}';
            $layoutConfig = json_decode($layoutConfigJson, true) ?: [];
            
            // 检查区域是否存在
            if (!isset($layoutConfig[$region])) {
                throw new \Exception('区域不存在');
            }
            
            // 移除组件
            if ($index !== null) {
                // 按索引移除
                if (isset($layoutConfig[$region][$index])) {
                    array_splice($layoutConfig[$region], $index, 1);
                }
            } else {
                // 按代码移除
                $layoutConfig[$region] = array_values(array_filter(
                    $layoutConfig[$region],
                    fn($comp) => ($comp['code'] ?? '') !== $componentCode
                ));
            }
            
            // 保存布局配置
            $page->setData('layout_config', json_encode($layoutConfig, JSON_UNESCAPED_UNICODE));
            $page->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('组件已移除'),
                'layout_config' => $layoutConfig,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 更新组件配置
     * POST /backend/visual/api/component/updateConfig
     */
    public function updateConfig()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $componentCode = $body['component_code'] ?? '';
            $region = $body['region'] ?? '';
            $index = $body['index'] ?? 0;
            $config = $body['config'] ?? [];
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$componentCode) {
                throw new \Exception('缺少组件代码');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 获取页面模型
            $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
            $page = clone $pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 获取当前布局配置
            $layoutConfigJson = $page->getData('layout_config') ?: '{}';
            $layoutConfig = json_decode($layoutConfigJson, true) ?: [];
            
            // 检查区域和组件是否存在
            if (!isset($layoutConfig[$region][$index])) {
                throw new \Exception('组件不存在');
            }
            
            if ($layoutConfig[$region][$index]['code'] !== $componentCode) {
                throw new \Exception('组件代码不匹配');
            }
            
            // 更新组件配置
            $layoutConfig[$region][$index]['config'] = $config;
            
            // 保存布局配置
            $page->setData('layout_config', json_encode($layoutConfig, JSON_UNESCAPED_UNICODE));
            $page->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('配置已更新'),
                'layout_config' => $layoutConfig,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: 调整组件顺序
     * POST /backend/visual/api/component/reorder
     */
    public function reorder()
    {
        try {
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            
            $pageId = (int)($body['page_id'] ?? 0);
            $region = $body['region'] ?? '';
            $newOrder = $body['order'] ?? []; // 新的组件代码顺序数组
            
            if (!$pageId) {
                throw new \Exception('缺少页面ID');
            }
            
            if (!$region) {
                throw new \Exception('缺少区域');
            }
            
            // 只有 content 区域支持重排
            if ($region !== 'content') {
                throw new \Exception('只有内容区域支持重新排序');
            }
            
            // 获取页面模型
            $pageModel = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Model\Page::class);
            $page = clone $pageModel;
            $page->load($pageId);
            
            if (!$page->getId()) {
                throw new \Exception('页面不存在');
            }
            
            // 获取当前布局配置
            $layoutConfigJson = $page->getData('layout_config') ?: '{}';
            $layoutConfig = json_decode($layoutConfigJson, true) ?: [];
            
            // 检查区域是否存在
            if (!isset($layoutConfig[$region])) {
                throw new \Exception('区域不存在');
            }
            
            // 构建代码到组件的映射
            $componentMap = [];
            foreach ($layoutConfig[$region] as $comp) {
                $code = $comp['code'] ?? '';
                if ($code) {
                    $componentMap[$code] = $comp;
                }
            }
            
            // 按新顺序重排
            $newComponents = [];
            foreach ($newOrder as $code) {
                if (isset($componentMap[$code])) {
                    $newComponents[] = $componentMap[$code];
                    unset($componentMap[$code]);
                }
            }
            
            // 将未在新顺序中的组件添加到末尾
            foreach ($componentMap as $comp) {
                $newComponents[] = $comp;
            }
            
            $layoutConfig[$region] = $newComponents;
            
            // 保存布局配置
            $page->setData('layout_config', json_encode($layoutConfig, JSON_UNESCAPED_UNICODE));
            $page->save();
            
            return $this->fetchJson([
                'success' => true,
                'message' => __('顺序已更新'),
                'layout_config' => $layoutConfig,
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
