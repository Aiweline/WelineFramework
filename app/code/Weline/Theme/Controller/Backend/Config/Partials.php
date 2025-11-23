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
use Weline\Theme\Helper\PartialsScanner;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题 Partials 配置控制器
 */
class Partials extends BackendController
{
    /**
     * 获取主题的 partials 配置页面
     */
    public function getIndex()
    {
        $themeId = $this->request->getParam('theme_id');
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 扫描可用的 partials
        $frontendPartials = PartialsScanner::scanPartials($theme, 'frontend');
        $backendPartials = PartialsScanner::scanPartials($theme, 'backend');
        
        // 获取当前配置
        $frontendConfig = PartialsScanner::getPartialsConfig($theme, 'frontend');
        $backendConfig = PartialsScanner::getPartialsConfig($theme, 'backend');

        $this->assign('theme', $theme);
        $this->assign('frontendPartials', $frontendPartials);
        $this->assign('backendPartials', $backendPartials);
        $this->assign('frontendConfig', $frontendConfig);
        $this->assign('backendConfig', $backendConfig);

        return $this->fetch('Weline_Theme::templates/backend/config/partials.phtml');
    }

    /**
     * 获取部件配置数据（用于modal异步加载）
     */
    public function getConfigData()
    {
        $themeId = $this->request->getParam('theme_id');
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 扫描可用的 partials
        $frontendPartials = PartialsScanner::scanPartials($theme, 'frontend');
        $backendPartials = PartialsScanner::scanPartials($theme, 'backend');
        
        // 获取当前配置
        $frontendConfig = PartialsScanner::getPartialsConfig($theme, 'frontend');
        $backendConfig = PartialsScanner::getPartialsConfig($theme, 'backend');

        return $this->fetchJson($this->success('', [
            'theme' => [
                'id' => $theme->getId(),
                'name' => $theme->getName(),
            ],
            'frontendPartials' => $frontendPartials,
            'backendPartials' => $backendPartials,
            'frontendConfig' => $frontendConfig,
            'backendConfig' => $backendConfig,
        ]));
    }

    /**
     * 保存 partials 配置
     */
    public function postSave()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend'); // frontend 或 backend
        $partials = $this->request->getPost('partials', []); // ['header' => 'default', 'footer' => 'minimal', ...]

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 验证 partials 选项是否存在
        $availablePartials = PartialsScanner::scanPartials($theme, $area);
        foreach ($partials as $type => $option) {
            if (!isset($availablePartials[$type]) || !in_array($option, $availablePartials[$type])) {
                return $this->fetchJson($this->error(__('Partials 选项无效：%1/%2', $type, $option)));
            }
        }

        // 获取现有配置
        $config = $theme->getConfig();
        if (!isset($config['partials'])) {
            $config['partials'] = [];
        }
        
        // 更新指定区域的配置
        $config['partials'][$area] = $partials;
        
        // 保存配置
        $theme->setConfig($config);
        $theme->save();
        
        // 清除主题缓存
        $theme->_cache->delete('theme');
        $theme->_cache->delete('theme_parent_' . $theme->getId());

        return $this->fetchJson($this->success(__('配置保存成功')));
    }

    /**
     * 获取可用的 partials 选项（AJAX）
     */
    public function getOptions()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $type = $this->request->getParam('type'); // header, footer, sidebar 等

        if (!$themeId || !$type) {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 扫描可用的 partials
        $partials = PartialsScanner::scanPartials($theme, $area);
        $options = $partials[$type] ?? [];

        return $this->fetchJson($this->success('', ['options' => $options]));
    }
}

