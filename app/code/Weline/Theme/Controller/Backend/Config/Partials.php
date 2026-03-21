<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend\Config;

use Weline\Framework\App\Controller\BackendController;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemeResourceCatalog;

/**
 * 主题 Partials 配置控制器
 */
class Partials extends BackendController
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeResourceCatalog $themeResourceCatalog,
        private readonly ThemeContextService $themeContextService,
    ) {
    }

    /**
     * 获取主题的 partials 配置页面
     */
    public function getIndex()
    {
        $themeId = $this->request->getParam('theme_id');
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();
        $theme->load((int)$themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 扫描可用的 partials（canonical 资源链）
        $frontendPartials = $this->toLegacyPartialMap(
            $this->themeResourceCatalog->getPartials('frontend', $theme)
        );
        $backendPartials = $this->toLegacyPartialMap(
            $this->themeResourceCatalog->getPartials('backend', $theme)
        );
        
        // 获取当前配置
        $frontendConfig = $this->getPartialsConfig($theme, 'frontend');
        $backendConfig = $this->getPartialsConfig($theme, 'backend');

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

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();
        $theme->load((int)$themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 扫描可用的 partials（canonical 资源链）
        $frontendPartials = $this->toLegacyPartialMap(
            $this->themeResourceCatalog->getPartials('frontend', $theme)
        );
        $backendPartials = $this->toLegacyPartialMap(
            $this->themeResourceCatalog->getPartials('backend', $theme)
        );
        
        // 获取当前配置
        $frontendConfig = $this->getPartialsConfig($theme, 'frontend');
        $backendConfig = $this->getPartialsConfig($theme, 'backend');

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

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();
        $theme->load((int)$themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        $area = $this->themeContextService->normalizeArea((string)$area);
        // 验证 partials 选项是否存在（canonical 资源链）
        $availablePartials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials($area, $theme));
        foreach ($partials as $type => $option) {
            if (!isset($availablePartials[$type]) || !in_array($option, $availablePartials[$type])) {
                return $this->fetchJson($this->error(__('Partials 选项无效：%{type}/%{option}', ['type' => $type, 'option' => $option])));
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

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();
        $theme->load((int)$themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        $area = $this->themeContextService->normalizeArea((string)$area);
        // 扫描可用的 partials（canonical 资源链）
        $partials = $this->toLegacyPartialMap($this->themeResourceCatalog->getPartials($area, $theme));
        $options = $partials[$type] ?? [];

        return $this->fetchJson($this->success('', ['options' => $options]));
    }

    private function getPartialsConfig(WelineTheme $theme, string $area): array
    {
        $config = $theme->getConfig();
        $partials = (array)($config['partials'] ?? []);
        $area = $this->themeContextService->normalizeArea($area);
        return (array)($partials[$area] ?? []);
    }

    private function toLegacyPartialMap(array $partials): array
    {
        $result = [];
        foreach ($partials as $type => $options) {
            $legacyOptions = [];
            foreach ($options as $option) {
                $value = (string)($option['value'] ?? '');
                if ($value !== '') {
                    $legacyOptions[] = $value;
                }
            }
            $result[$type] = array_values(array_unique($legacyOptions));
        }

        return $result;
    }
}

