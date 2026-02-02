<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeConfigManager;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题组件配置控制器
 */
class Component extends BackendController
{
    /**
     * 保存组件参数
     */
    public function postSaveParams()
    {
        $themeId = $this->request->getPost('theme_id');
        $component = $this->request->getPost('component');
        $area = $this->request->getPost('area', 'frontend');
        $scope = $this->request->getPost('scope', 'default');
        $params = $this->request->getPost('params', []);
        
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }
        
        if (!$component) {
            return $this->fetchJson($this->error(__('请选择组件')));
        }
        
        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }
        
        try {
            // 获取当前配置
            $config = $theme->getConfig();
            
            // 初始化组件配置结构
            if (!isset($config['components'])) {
                $config['components'] = [];
            }
            if (!isset($config['components'][$area])) {
                $config['components'][$area] = [];
            }
            if (!isset($config['components'][$area][$component])) {
                $config['components'][$area][$component] = [];
            }
            if (!isset($config['components'][$area][$component][$scope])) {
                $config['components'][$area][$component][$scope] = [];
            }
            
            // 保存参数值
            foreach ($params as $paramName => $paramValue) {
                // 处理不同类型的值
                if (is_string($paramValue) && (strpos($paramValue, '[') === 0 || strpos($paramValue, '{') === 0)) {
                    // 尝试解析 JSON
                    $decoded = json_decode($paramValue, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $paramValue = $decoded;
                    }
                } elseif ($paramValue === '1' || $paramValue === '0') {
                    // 布尔值
                    $paramValue = (bool)$paramValue;
                } elseif (is_numeric($paramValue)) {
                    // 数字
                    if (strpos($paramValue, '.') !== false) {
                        $paramValue = (float)$paramValue;
                    } else {
                        $paramValue = (int)$paramValue;
                    }
                }
                
                $config['components'][$area][$component][$scope][$paramName] = $paramValue;
            }
            
            // 保存配置
            $theme->setConfig($config);
            $result = $theme->save();
            
            if ($result) {
                // 清除缓存
                $theme->_cache->delete('theme');
                $theme->_cache->delete('theme_parent_' . $theme->getId());
                $theme->_cache->delete('theme_config_' . $theme->getId());
                
                return $this->fetchJson($this->success(__('参数保存成功')));
            } else {
                return $this->fetchJson($this->error(__('参数保存失败')));
            }
        } catch (\Exception $e) {
            return $this->fetchJson($this->error(__('保存失败：%{error}', ['error' => $e->getMessage()])));
        }
    }
}

