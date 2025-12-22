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
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * 布局 / 颜色等 Meta 参数配置控制器
 *
 * 专门用于齿轮按钮打开的参数配置弹窗：
 * - GET meta/config-data  返回某个 meta_identify 下的参数定义 + 当前值
 * - POST meta/save        保存某个 meta_identify 下的参数值
 *
 * 约定：
 * - 前端传入的 identify 为「文件的 meta_identify」，例如：layouts.default / layouts.account / colors.default
 * - ThemeData 会自动补全前缀为 theme.frontend.* 或 theme.backend.*，不需要前端关心 namespace
 */
class Meta extends BackendController
{
    /**
     * 获取指定 Meta 的参数配置数据
     *
     * 请求参数：
     * - theme_id  主题ID
     * - area      frontend/backend
     * - identify  文件 meta_identify（例如 layouts.default）
     * - scope     作用域（可选，默认 default）
     * - locale    语言代码（可选，默认当前语言）
     */
    public function getConfigData()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $identify = trim((string)$this->request->getParam('identify'));
        $scope = trim((string)$this->request->getParam('scope', 'default'));
        $locale = $this->request->getParam('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN');

        if (!$themeId || $identify === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 设置当前主题和区域，确保 ThemeData 正确解析 namespace/scope
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        try {
            // 获取完整的 Meta 记录，用于拿到参数定义（名称、描述等）
            $meta = ThemeData::getMeta($identify);
            if (!$meta) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：') . $identify));
            }

            $setting = $meta['setting'] ?? [];
            $paramDefs = $setting['param'] ?? [];

            // 使用 ThemeData::getFileParams 获取「当前语言」的值（会自动按 scope / 默认值处理）
            // 注意：getFileParams 内部会根据 paramDef 中的 translate 标记决定是否走多语言翻译
            $values = ThemeData::getFileParams($identify);

            $params = [];
            foreach ($paramDefs as $name => $def) {
                if (!is_array($def)) {
                    $def = ['default' => $def];
                }
                $isTranslatable = !empty($def['translate']) || !empty($def['translatable']);

                $params[] = [
                    'name' => $name,
                    'label' => (string)($def['name'] ?? $name),
                    'description' => (string)($def['description'] ?? ''),
                    'default' => $def['default'] ?? null,
                    'value' => $values[$name] ?? ($def['default'] ?? null),
                    'translate' => $isTranslatable,
                ];
            }

            // TODO：如果后续需要支持多语言选择，这里可以返回 available_locales 列表
            return $this->fetchJson($this->success('',
                [
                    'identify' => $identify,
                    'scope' => $scope,
                    'area' => $area,
                    'locale' => $locale,
                    'params' => $params,
                ]));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('加载 Meta 配置失败：') . $e->getMessage()));
        }
    }

    /**
     * 保存指定 Meta 的参数配置
     *
     * POST 参数：
     * - theme_id  主题ID
     * - area      frontend/backend
     * - identify  文件 meta_identify（例如 layouts.default）
     * - scope     作用域（可选，默认 default）
     * - params    键值对数组，形如 [name => value]
     */
    public function postSave()
    {
        $themeId = (int)$this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $identify = trim((string)$this->request->getPost('identify'));
        $scope = trim((string)$this->request->getPost('scope', 'default'));
        $params = (array)$this->request->getPost('params', []);

        if (!$themeId || $identify === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        try {
            foreach ($params as $name => $value) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                // 保存到 ThemeData：{identify}.param.{name}.value
                $configIdentify = "{$identify}.param.{$name}.value";
                ThemeData::set($configIdentify, (string)$value, $scope);
            }

            return $this->fetchJson($this->success(__('参数已保存')));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('保存失败：') . $e->getMessage()));
        }
    }

    /**
     * 获取单个参数在某个语言下的翻译值（用于「翻译」弹窗）
     *
     * 请求参数：
     * - theme_id  主题ID
     * - area      frontend/backend
     * - identify  文件 meta_identify（例如 layouts.default）
     * - param     参数名
     * - scope     作用域（可选，默认 default）
     * - locale    语言代码（可选，默认当前语言）
     */
    public function getParamTranslation()
    {
        $themeId = (int)$this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $identify = trim((string)$this->request->getParam('identify'));
        $paramName = trim((string)$this->request->getParam('param'));
        $scope = trim((string)$this->request->getParam('scope', 'default'));
        $locale = $this->request->getParam('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN');

        if (!$themeId || $identify === '' || $paramName === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        try {
            $meta = ThemeData::getMeta($identify);
            if (!$meta) {
                return $this->fetchJson($this->error(__('未找到对应的 Meta 配置：') . $identify));
            }

            $setting = $meta['setting'] ?? [];
            $paramDefs = $setting['param'] ?? [];
            $def = $paramDefs[$paramName] ?? null;
            if ($def === null) {
                return $this->fetchJson($this->error(__('参数不存在：') . $paramName));
            }

            if (!is_array($def)) {
                $def = ['default' => $def];
            }

            $defaultValue = $def['default'] ?? null;
            $label = (string)($def['name'] ?? $paramName);
            $description = (string)($def['description'] ?? '');

            $value = ThemeData::getParamTranslation($identify, $paramName, $scope, $locale, is_scalar($defaultValue) ? (string)$defaultValue : null);

            return $this->fetchJson($this->success('', [
                'identify' => $identify,
                'param' => $paramName,
                'scope' => $scope,
                'locale' => $locale,
                'label' => $label,
                'description' => $description,
                'default' => $defaultValue,
                'value' => $value,
            ]));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('加载翻译失败：') . $e->getMessage()));
        }
    }

    /**
     * 保存单个参数在某个语言下的翻译值
     *
     * POST 参数：
     * - theme_id  主题ID
     * - area      frontend/backend
     * - identify  文件 meta_identify（例如 layouts.default）
     * - param     参数名
     * - scope     作用域（可选，默认 default）
     * - locale    语言代码（可选，默认当前语言）
     * - value     翻译值
     */
    public function postParamTranslationSave()
    {
        $themeId = (int)$this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $identify = trim((string)$this->request->getPost('identify'));
        $paramName = trim((string)$this->request->getPost('param'));
        $scope = trim((string)$this->request->getPost('scope', 'default'));
        $locale = $this->request->getPost('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN');
        $value = (string)$this->request->getPost('value', '');

        if (!$themeId || $identify === '' || $paramName === '') {
            return $this->fetchJson($this->error(__('参数不完整')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        try {
            ThemeData::setParamTranslation($identify, $paramName, $value, $scope, $locale);

            return $this->fetchJson($this->success(__('翻译已保存')));
        } catch (\Throwable $e) {
            return $this->fetchJson($this->error(__('保存翻译失败：') . $e->getMessage()));
        }
    }
}


