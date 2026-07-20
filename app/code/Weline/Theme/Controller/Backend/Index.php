<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ConfigLoader;
use Weline\Theme\Helper\MetaTranslation;
use Weline\Theme\Helper\PreviewManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePreviewEntryApplication;
use Weline\Theme\Service\ThemePreviewGenerator;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;
use Weline\Framework\Http\Sse\SseWriter;

/**
 * 主题管理控制器
 */
class Index extends BackendController
{
    /**
     * 主题列表页面
     */
    public function getIndex()
    {
        /** @var WelineTheme $themeModel */
        $themeModel = ObjectManager::getInstance(WelineTheme::class);
        
        // 获取所有主题（包含 preview_image 字段）
        $themes = $themeModel->select()->fetch()->getItems();
        
        // 为每个主题获取父主题信息
        foreach ($themes as &$theme) {
            // 确保 $theme 是数组格式
            if (is_object($theme)) {
                $themeData = $theme->getData();
            } else {
                $themeData = $theme;
            }
            
            // 获取父主题信息
            if (!empty($themeData['parent_id'])) {
                /** @var WelineTheme $parentTheme */
                $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                $parentTheme->load($themeData['parent_id']);
                
                if ($parentTheme->getId()) {
                    $theme['parent_id'] = $parentTheme->getId();
                    $theme['parent_theme_name'] = $parentTheme->getName();
                } else {
                    $theme['parent_id'] = null;
                    $theme['parent_theme_name'] = null;
                }
            } else {
                $theme['parent_id'] = null;
                $theme['parent_theme_name'] = null;
            }

            $themePath = '';
            if (is_array($themeData)) {
                $themePath = (string)($themeData['path'] ?? '');
            } elseif (is_object($themeData) && method_exists($themeData, 'getData')) {
                $themePath = (string)$themeData->getData('path');
            }

            $theme['has_frontend'] = $this->themeHasArea($themePath, 'frontend');
            $theme['has_backend'] = $this->themeHasArea($themePath, 'backend');
        }
        unset($theme); // 解除引用

        /** @var WelineTheme $activeQuery */
        $activeQuery = ObjectManager::getInstance(WelineTheme::class);
        $activeFrontend = $this->safeLoadActiveThemeId($activeQuery, WelineTheme::schema_fields_IS_ACTIVE_FRONTEND);
        $activeBackend = $this->safeLoadActiveThemeId($activeQuery, WelineTheme::schema_fields_IS_ACTIVE_BACKEND);
        if (!$activeFrontend && !$activeBackend) {
            $fallbackActive = $this->safeLoadActiveThemeId($activeQuery, WelineTheme::schema_fields_IS_ACTIVE);
            if ($fallbackActive) {
                $activeFrontend = $fallbackActive;
                $activeBackend = $fallbackActive;
            }
        }
        
        $this->assign('themes', $themes);
        $this->assign('active_frontend_id', $activeFrontend);
        $this->assign('active_backend_id', $activeBackend);
        $this->assign('page_title', __('主题管理'));
        
        return $this->fetch('Weline_Theme::templates/backend/index.phtml');
    }

    /**
     * 获取主题详情（用于modal显示）
     */
    public function getThemeInfo()
    {
        $themeId = $this->request->getParam('theme_id');
        
        if (!$themeId) {
            return $this->fetchJson(['status' => 'error', 'message' => __('请选择主题'), 'data' => []]);
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson(['status' => 'error', 'message' => __('主题不存在'), 'data' => []]);
        }

        // 获取父主题信息
        $parentTheme = null;
        if ($theme->getParentId()) {
            $parentTheme = ObjectManager::getInstance(WelineTheme::class);
            $parentTheme->load($theme->getParentId());
            if ($parentTheme->getId()) {
                $parentTheme = [
                    'id' => $parentTheme->getId(),
                    'name' => $parentTheme->getName(),
                ];
            } else {
                $parentTheme = null;
            }
        }

        // 生成预览URL（使用Url类自动处理货币和语言前缀）
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        
        $previewUrlFrontend = $url->getBackendUrl('theme/backend/index/preview', [
            'theme_id' => $theme->getId(),
            'area' => 'frontend',
            'auto_login' => '1' // 默认开启自动登录
        ]);
        
        $previewUrlBackend = $url->getBackendUrl('theme/backend/index/preview', [
            'theme_id' => $theme->getId(),
            'area' => 'backend'
        ]);

        return $this->fetchJson([
            'status' => 'success',
            'message' => '',
            'data' => [
                'id' => $theme->getId(),
                'name' => $theme->getName(),
                'module_name' => $theme->getModuleName(),
                'path' => $theme->getPath(),
                'is_active' => $theme->getIsActive(),
                'parent_id' => $theme->getParentId(),
                'parent_theme' => $parentTheme,
                'config' => $theme->getConfig(),
                'preview_image' => $theme->getPreviewImage(),
                'frontend_preview_image' => $theme->getFrontendPreviewImage(),
                'backend_preview_image' => $theme->getBackendPreviewImage(),
                'preview_url_frontend' => $previewUrlFrontend,
                'preview_url_backend' => $previewUrlBackend,
            ]
        ]);
    }

    /**
     * 主题预览（临时激活主题并渲染真实页面）
     */
    public function getPreview()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $area = $this->request->getParam('area', 'frontend'); // frontend、backend 或 mobile
        $autoLogin = $this->request->getParam('auto_login', '1'); // 是否自动登录，默认开启（1=开启，0=关闭）
        $pageType = (string)$this->request->getParam('page_type', 'homepage');
        $versionId = (int)$this->request->getParam('version_id', 0);
        $status = (string)$this->request->getParam('status', 'draft');
        $previewMode = (string)$this->request->getParam('preview_mode', 'default');

        /** @var ThemePreviewEntryApplication $previewEntry */
        $previewEntry = ObjectManager::getInstance(ThemePreviewEntryApplication::class);
        $result = $previewEntry->preparePreviewRedirect(
            $themeId,
            (string)$area,
            $autoLogin,
            $this->session,
            true,
            null,
            $pageType,
            $versionId > 0 ? $versionId : null,
            $status,
            (string)$area,
            $previewMode,
        );

        if (!$result['ok']) {
            return $this->error($result['message']);
        }

        $this->request->getResponse()->redirect($result['redirect']);

        return '';
    }


    /**
     * 激活主题（异步），按区域：frontend 前台 / backend 后台
     */
    public function postActivate()
    {
        $themeId = (int)$this->request->getPost('theme_id');
        $area = $this->request->getPost('area');
        if (!in_array($area, ['frontend', 'backend'], true)) {
            $area = null;
        }

        $result = ObjectManager::getInstance(ThemeContextService::class)
            ->activateThemeForArea($themeId, $area);

        if (!empty($result['success'])) {
            return $this->fetchJson($this->success((string)($result['message'] ?? __('主题激活成功'))));
        }

        return $this->fetchJson($this->error((string)($result['message'] ?? __('激活失败'))));
    }

    /**
     * 根据布局文件的 @preview.login 标记判断是否需要自动登录
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域（frontend/backend）
     * @return bool 是否需要自动登录
     */
    private function shouldAutoLoginByLayout(WelineTheme $theme, string $area): bool
    {
        try {
            // 获取布局配置
            $layoutConfig = ConfigLoader::getLayoutConfig($theme, $area);
            
            // 获取默认布局类型（通常是 'default'）
            $layoutType = 'default';
            $layoutOption = $layoutConfig[$layoutType] ?? 'default';
            
            // 构建布局文件路径
            $themePath = $theme->getPath();
            if (empty($themePath)) {
                return false; // 默认不登录
            }
            
            $layoutPath = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $layoutOption . '.phtml';
            $layoutPath = str_replace('\\', DS, $layoutPath);
            
            // 如果当前主题不存在，尝试父主题
            if (!is_file($layoutPath)) {
                $parentId = $theme->getParentId();
                if ($parentId) {
                    /** @var WelineTheme $parentTheme */
                    $parentTheme = ObjectManager::getInstance(WelineTheme::class);
                    $parentTheme->load($parentId);
                    if ($parentTheme->getId()) {
                        return $this->shouldAutoLoginByLayout($parentTheme, $area);
                    }
                }
                
                // 如果都找不到，使用默认值
                return false;
            }
            
            // 解析布局文件的 Meta 信息
            $meta = ComponentMetaParser::parse($layoutPath);
            
            // 返回 preview_login 标记的值（默认 0，即不登录）
            return isset($meta['preview_login']) && $meta['preview_login'] == 1;
        } catch (\Exception $e) {
            // 解析失败，默认不登录
            return false;
        }
    }

    /**
     * 生成主题预览图片（AJAX调用）
     */
    public function postGeneratePreviewImage()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $area = $area === 'backend' ? 'backend' : 'frontend';
        $force = (bool)$this->request->getPost('force', false);

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);

        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        if (!$this->themeSupportsArea($theme, $area)) {
            return $this->fetchJson($this->error(__('主题不支持 %{1} 区域', [$area])));
        }

        try {
            $imagePath = ThemePreviewGenerator::generatePreviewImage($theme, $area, $force);

            if ($imagePath) {
                // 更新数据库中的 preview_image 字段
                $relativePath = ThemePreviewGenerator::normalizePreviewRelativePath($imagePath);
                $this->persistThemePreviewImage($theme, $area, $relativePath);

                return $this->fetchJson($this->success(__('预览图生成成功'), [
                    'image_url' => '/' . $relativePath,
                    'image_path' => $imagePath,
                ]));
            } else {
                return $this->fetchJson($this->error(__('预览图生成失败')));
            }
        } catch (\Exception $e) {
            Env::log_error('theme_preview', __('生成预览图失败：%{1}', [$e->getMessage()]));
            return $this->fetchJson($this->error(__('生成失败：%{1}', [$e->getMessage()])));
        }
    }

    /**
     * 并发批量生成所有主题的预览图片（SSE：实时输出进度）
     *
     * POST /theme/backend/index/postGenerateAllPreviewsConcurrent
     */
    public function postGenerateAllPreviewsConcurrent(): void
    {
        $sse = new SseWriter();
        $concurrency = (int)$this->request->getPost('concurrency', $this->request->getGet('concurrency', ThemePreviewGenerator::DEFAULT_CONCURRENCY));
        $concurrency = max(1, min($concurrency, 8));

        try {
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->select()->fetch()->getItems();

            // 构建任务列表
            $tasks = [];
            foreach ($themes as $theme) {
                $themeId = is_object($theme) ? $theme->getId() : ($theme['id'] ?? 0);
                if (!$themeId) {
                    continue;
                }
                $tasks[] = [
                    'theme' => $theme,
                    'area' => 'frontend',
                    'force' => true,
                ];
                $tasks[] = [
                    'theme' => $theme,
                    'area' => 'backend',
                    'force' => true,
                ];
            }

            $total = count($tasks);
            $sse->start();
            $sse->sendEvent('start', [
                'message' => __('开始并发批量生成预览图（并发数：%{1}）...', [$concurrency]),
                'total' => $total,
                'concurrency' => $concurrency,
            ]);

            $result = ThemePreviewGenerator::generatePreviewImagesBatch(
                $tasks,
                $concurrency,
                function(int $completed, int $total, string $message) use ($sse) {
                    if ($sse->isAlive()) {
                        $sse->sendEvent('progress', [
                            'progress' => $total > 0 ? (($completed / $total) * 100) : 0,
                            'current' => $completed,
                            'total' => $total,
                            'message' => $message,
                        ]);
                    }
                }
            );

            // 保存成功的预览图路径到数据库
            $themeModelFresh = ObjectManager::getInstance(WelineTheme::class);
            foreach ($result['results'] as $item) {
                if ($item['success'] && !empty($item['path'])) {
                    try {
                        $themeObj = clone $themeModelFresh;
                        $themeObj->load($item['theme_id']);
                        if ($item['area'] === 'backend') {
                            $themeObj->setBackendPreviewImage($item['path']);
                        } else {
                            $themeObj->setFrontendPreviewImage($item['path'])
                                ->setPreviewImage($item['path']);
                        }
                        $themeObj->save();
                    } catch (\Throwable $e) {
                        // 保存失败不影响整体结果
                    }
                }
            }

            if ($result['failed'] > 0) {
                $sse->complete([
                    'message' => __('部分预览图生成失败'),
                    'data' => [
                        'success' => $result['success'],
                        'failed' => $result['failed'],
                        'total' => $total,
                    ],
                ]);
                return;
            }

            $sse->complete([
                'message' => __('所有预览图生成成功'),
                'data' => [
                    'success' => $result['success'],
                    'failed' => $result['failed'],
                    'total' => $total,
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                if (!$sse->isStarted()) {
                    $sse->start();
                }
                $sse->sendError($e->getMessage(), 500);
                $sse->close();
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * 批量生成所有主题的预览图片（AJAX调用）
     */
    public function postGenerateAllPreviews()
    {
        try {
            /** @var WelineTheme $themeModel */
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->select()->fetch()->getItems();

            $total = count($themes);
            $success = 0;
            $failed = 0;
            $messages = [];

            foreach ($themes as $theme) {
                $themeId = is_object($theme) ? $theme->getId() : ($theme['id'] ?? 0);
                $themeName = is_object($theme) ? $theme->getName() : ($theme['name'] ?? 'Unknown');

                if (!$themeId) {
                    continue;
                }

                $themeOk = false;
                $themeErrors = [];

                // 生成 frontend 预览图
                try {
                    $result1 = ThemePreviewGenerator::generatePreviewImage($theme, 'frontend', true);
                    $relativePath1 = ThemePreviewGenerator::normalizePreviewRelativePath($result1);
                    $themeObj1 = clone $themeModel;
                    $themeObj1->load($themeId);
                    $this->persistThemePreviewImage($themeObj1, 'frontend', $relativePath1);

                    $themeOk = true;
                } catch (\Exception $e) {
                    $themeErrors[] = __('frontend 生成失败：%{1}', [$e->getMessage()]);
                }

                // 生成 backend 预览图
                try {
                    $result2 = ThemePreviewGenerator::generatePreviewImage($theme, 'backend', true);
                    $relativePath2 = ThemePreviewGenerator::normalizePreviewRelativePath($result2);
                    $themeObj2 = clone $themeModel;
                    $themeObj2->load($themeId);
                    $this->persistThemePreviewImage($themeObj2, 'backend', $relativePath2);

                    $themeOk = true;
                } catch (\Exception $e) {
                    $themeErrors[] = __('backend 生成失败：%{1}', [$e->getMessage()]);
                }

                if ($themeOk) {
                    $success++;
                } else {
                    $failed++;
                    $detail = !empty($themeErrors) ? implode('；', $themeErrors) : __('原因未知');
                    $messages[] = __('%{1}: 生成失败：%{2}', [$themeName, $detail]);
                }
            }

            $result = [
                'total' => $total,
                'success' => $success,
                'failed' => $failed,
            ];

            if ($failed > 0) {
                $result['messages'] = $messages;
                return $this->fetchJson($this->warning(__('部分预览图生成失败'), $result));
            }

            return $this->fetchJson($this->success(__('所有预览图生成成功'), $result));
        } catch (\Exception $e) {
            Env::log_error('theme_preview', __('批量生成预览图失败：%{1}', [$e->getMessage()]));
            return $this->fetchJson($this->error(__('生成失败：%{1}', [$e->getMessage()])));
        }
    }

    /**
     * 批量生成所有主题预览图（SSE：实时输出进度，支持并发）
     *
     * POST /theme/backend/index/postGenerateAllPreviewsSse
     */
    public function postGenerateAllPreviewsSse(): void
    {
        $sse = new SseWriter();
        $concurrency = (int)$this->request->getPost('concurrency', $this->request->getGet('concurrency', ThemePreviewGenerator::DEFAULT_CONCURRENCY));
        $concurrency = max(1, min($concurrency, 8));

        try {
            $themeModel = ObjectManager::getInstance(WelineTheme::class);
            $themes = $themeModel->select()->fetch()->getItems();

            // 构建任务列表
            $tasks = [];
            foreach ($themes as $theme) {
                $themeId = is_object($theme) ? $theme->getId() : ($theme['id'] ?? 0);
                if (!$themeId) {
                    continue;
                }
                $tasks[] = [
                    'theme' => $theme,
                    'area' => 'frontend',
                    'force' => true,
                ];
                $tasks[] = [
                    'theme' => $theme,
                    'area' => 'backend',
                    'force' => true,
                ];
            }

            $total = count($tasks);
            $sse->start();
            $sse->sendEvent('start', [
                'message' => __('开始并发批量生成预览图（并发数：%{1}）...', [$concurrency]),
                'total' => $total,
                'concurrency' => $concurrency,
            ]);

            $result = ThemePreviewGenerator::generatePreviewImagesBatch(
                $tasks,
                $concurrency,
                function(int $completed, int $total, string $message) use ($sse) {
                    if ($sse->isAlive()) {
                        $sse->sendEvent('progress', [
                            'progress' => $total > 0 ? (($completed / $total) * 100) : 0,
                            'current' => $completed,
                            'total' => $total,
                            'message' => $message,
                        ]);
                    }
                }
            );

            // 保存成功的预览图路径到数据库
            $themeModelFresh = ObjectManager::getInstance(WelineTheme::class);
            foreach ($result['results'] as $item) {
                if ($item['success'] && !empty($item['path'])) {
                    try {
                        $themeObj = clone $themeModelFresh;
                        $themeObj->load($item['theme_id']);
                        if ($item['area'] === 'backend') {
                            $themeObj->setBackendPreviewImage($item['path']);
                        } else {
                            $themeObj->setFrontendPreviewImage($item['path'])
                                ->setPreviewImage($item['path']);
                        }
                        $themeObj->save();
                    } catch (\Throwable) {
                        // 保存失败不影响整体结果
                    }
                }
            }

            if ($result['failed'] > 0) {
                $sse->complete([
                    'message' => __('部分预览图生成失败'),
                    'data' => [
                        'success' => $result['success'],
                        'failed' => $result['failed'],
                        'total' => $total,
                    ],
                ]);
                return;
            }

            $sse->complete([
                'message' => __('所有预览图生成成功'),
                'data' => [
                    'success' => $result['success'],
                    'failed' => $result['failed'],
                    'total' => $total,
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                if (!$sse->isStarted()) {
                    $sse->start();
                }
                $sse->sendError($e->getMessage(), 500);
                $sse->close();
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * 按区域保存主题预览图路径
     */
    private function persistThemePreviewImage(WelineTheme $theme, string $area, string $relativePath): void
    {
        $relativePath = ThemePreviewGenerator::normalizePreviewRelativePath($relativePath);

        if ($area === 'backend') {
            $theme->setBackendPreviewImage($relativePath);
        } else {
            $theme->setFrontendPreviewImage($relativePath)
                ->setPreviewImage($relativePath);
        }
        try {
            $theme->save();
        } catch (\Throwable $throwable) {
            if (!$this->isMissingThemePreviewFieldError($throwable, WelineTheme::schema_fields_PREVIEW_IMAGE)) {
                throw $throwable;
            }

            // 兼容旧库：若缺少 preview_image 列，移除该字段后重试保存。
            $theme->unsetData(WelineTheme::schema_fields_PREVIEW_IMAGE)
                ->unsetModelData(WelineTheme::schema_fields_PREVIEW_IMAGE);
            $theme->save();
        }
    }

    private function isMissingThemePreviewFieldError(\Throwable $throwable, string $field): bool
    {
        $message = strtolower($throwable->getMessage());
        if (!str_contains($message, strtolower($field))) {
            return false;
        }

        return str_contains($message, 'undefined column')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'column');
    }

    /**
     * 获取主题的meta信息和配置
     */
    public function getMetaConfig()
    {
        $themeId = $this->request->getParam('theme_id');
        $area = $this->request->getParam('area', 'frontend');
        $type = $this->request->getParam('type', 'layouts'); // layouts, components, partials
        $identify = $this->request->getParam('identify', ''); // 如 default, account 等
        $locale = $this->request->getParam('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN');
        
        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);

        // 构建meta_identify（格式：theme.frontend.layouts.default）
        // 规则统一：{type}.{identify}，不再额外追加 .default 段
        $metaIdentify = "{$type}";
        if ($identify) {
            $metaIdentify .= ".{$identify}";
        }

        // 使用 ThemeData 加载元数据信息（从缓存读取，不触发数据库查询）
        $metaDataArr = ThemeData::getMeta($metaIdentify);
        $metaData = [];
        $params = [];
        
        if ($metaDataArr) {
            $metaData = $metaDataArr['meta_data'] ?? [];
            $setting = $metaDataArr['setting'] ?? [];
            $params = $setting['param'] ?? [];
        }

        // 如果 Meta 模块中没有找到定义，尝试从模板文件解析
        if (empty($metaData) || empty($params)) {
            $filePath = $this->resolveThemeFilePath($theme, $area, $type, $identify);
            if ($filePath) {
                $parsed = ComponentMetaParser::parse($filePath);
                if (empty($metaData) && !empty($parsed['meta'])) {
                    $metaData = $parsed['meta'];
                }
                if ((empty($params) || !is_array($params)) && !empty($parsed['params'])) {
                    $params = $this->formatParsedParams($parsed['params']);
                }
            }
        }

        // 读取参数配置值（使用 ThemeData::get 从缓存读取）
        $themeConfig = [];
        $baseIdentify = "{$type}";
        if ($identify) {
            $baseIdentify .= ".{$identify}";
        }
        
        if ($params) {
            foreach ($params as $paramKey => $paramMeta) {
                $paramIdentify = "{$baseIdentify}.param.{$paramKey}.value";
                $defaultValue = $paramMeta['default'] ?? null;
                $paramValue = ThemeData::get($paramIdentify, $defaultValue);
                $themeConfig[$paramKey] = $paramValue;
            }
        }

        /** @var LocaleCatalogInterface $localeCatalog */
        $localeCatalog = ObjectManager::getInstance(LocaleCatalogInterface::class);
        $localeList = $localeCatalog->all((string)$locale);

        return $this->fetchJson($this->success('', [
            'meta' => $metaData,
            'params' => $params,
            'theme_config' => $themeConfig,
            'locale' => $locale,
            'locales' => $localeList,
        ]));
    }

    /**
     * 保存主题的meta配置
     */
    public function postSaveMetaConfig()
    {
        $themeId = $this->request->getPost('theme_id');
        $area = $this->request->getPost('area', 'frontend');
        $type = $this->request->getPost('type', 'layouts');
        $identify = $this->request->getPost('identify', '');
        $locale = $this->request->getPost('locale') ?: (\Weline\Framework\Http\Cookie::getLangLocal() ?? null);
        $configJson = $this->request->getPost('config', '{}'); // JSON格式的配置数据

        if (!$themeId) {
            return $this->fetchJson($this->error(__('请选择主题')));
        }

        /** @var WelineTheme $theme */
        $theme = ObjectManager::getInstance(WelineTheme::class);
        $theme->load($themeId);
        
        if (!$theme->getId()) {
            return $this->fetchJson($this->error(__('主题不存在')));
        }

        // 解析配置JSON
        $config = json_decode($configJson, true);
        if (!is_array($config)) {
            $config = [];
        }

        // 设置当前主题和区域
        ThemeData::setCurrentTheme($theme);
        ThemeData::setCurrentArea($area);
        
        // 构建配置键（布局参数配置）
        // 格式：{type}.{identify}.{key}.value
        $baseConfigKey = "{$type}";
        if ($identify) {
            $baseConfigKey .= ".{$identify}";
        }
        
        // 保存每个参数配置（使用 ThemeData::set()）
        foreach ($config as $key => $value) {
            $normalizedKey = ltrim($key, '.');
            $fullIdentify = "{$baseConfigKey}.param.{$normalizedKey}.value";
            ThemeData::set($fullIdentify, (string)$value, 'default', $locale);
        }

        return $this->fetchJson($this->success(__('配置保存成功')));
    }

    /**
     * 根据类型和标识解析模板文件路径
     */
    private function resolveThemeFilePath(WelineTheme $theme, string $area, string $type, string $identify): ?string
    {
        $relativePath = $this->buildRelativePath($area, $type, $identify);
        if (!$relativePath) {
            return null;
        }
        return $this->findFileInThemeHierarchy($theme, $relativePath);
    }

    private function buildRelativePath(string $area, string $type, string $identify): ?string
    {
        $area = strtolower($area);
        $identify = trim($identify);

        switch ($type) {
            case 'layouts':
                [$layoutType, $option] = array_pad(explode('.', $identify, 2), 2, 'default');
                if (!$layoutType) {
                    $layoutType = 'default';
                }
                if (!$option) {
                    $option = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'layouts' . DS . $layoutType . DS . $option . '.phtml';
            case 'partials':
                [$partialType, $option] = array_pad(explode('.', $identify, 2), 2, 'default');
                if (!$partialType) {
                    $partialType = 'default';
                }
                if (!$option) {
                    $option = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'partials' . DS . $partialType . DS . $option . '.phtml';
            case 'components':
                $component = $identify ?: 'default';
                return 'view' . DS . 'theme' . DS . $area . DS . 'components' . DS . $component . '.phtml';
            case 'colors':
                $color = ltrim($identify, '_');
                if ($color === '') {
                    $color = 'default';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'colors' . DS . '_' . $color . '.css';
            case 'variables':
                $variable = ltrim($identify, '_');
                if ($variable === '') {
                    $variable = 'colors';
                }
                return 'view' . DS . 'theme' . DS . $area . DS . 'variables' . DS . '_' . $variable . '.css';
            default:
                return null;
        }
    }

    private function findFileInThemeHierarchy(WelineTheme $theme, string $relativePath): ?string
    {
        $currentTheme = $theme;
        while ($currentTheme) {
            $themePath = $currentTheme->getPath();
            if ($themePath) {
                $fullPath = rtrim($themePath, DS) . DS . $relativePath;
                if (is_file($fullPath)) {
                    return $fullPath;
                }
            }
            $currentTheme = $currentTheme->getParentTheme();
        }

        $modules = Env::getInstance()->getModuleList();
        if (isset($modules['Weline_Theme'])) {
            $basePath = rtrim($modules['Weline_Theme']['base_path'], DS);
            $fullPath = $basePath . DS . $relativePath;
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }

    private function formatParsedParams(array $parsedParams): array
    {
        $result = [];
        foreach ($parsedParams as $param) {
            $key = $param['name'] ?? '';
            if ($key === '') {
                continue;
            }
            $result[$key] = [
                'name' => $param['name_label'] ?? $key,
                'description' => $param['description'] ?? '',
                'default' => $param['default'] ?? '',
                'type' => $param['type'] ?? 'text',
                'required' => (bool)($param['required'] ?? false),
            ];
        }
        return $result;
    }

    private function safeLoadActiveThemeId(WelineTheme $theme, string $field): ?int
    {
        $theme->clearData()->clearQuery();
        try {
            $theme->load($field, 1);
            return $theme->getId() ? (int)$theme->getId() : null;
        } catch (\Throwable $throwable) {
            if ($this->isMissingThemeActivationFieldError($throwable, $field)) {
                return null;
            }
            throw $throwable;
        }
    }

    private function isMissingThemeActivationFieldError(\Throwable $throwable, string $field): bool
    {
        $message = strtolower($throwable->getMessage());
        if (!str_contains($message, strtolower($field))) {
            return false;
        }

        return str_contains($message, 'undefined column')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'column');
    }

    private function safeToggleAreaThemeActivation(WelineTheme $theme, int $themeId, string $field): bool
    {
        try {
            $theme->clearQuery();
            $theme->where($field, 1)
                ->update([$field => 0])->fetch();
            $theme->clearQuery();
            $theme->where(WelineTheme::schema_fields_ID, $themeId)
                ->update([$field => 1])->fetch();
            return true;
        } catch (\Throwable $throwable) {
            if ($this->isMissingThemeActivationFieldError($throwable, $field)) {
                return false;
            }
            throw $throwable;
        }
    }

    private function activateThemeFallback(WelineTheme $theme, int $themeId): void
    {
        $theme->clearQuery();
        $theme->where(WelineTheme::schema_fields_IS_ACTIVE, 1)
            ->update([WelineTheme::schema_fields_IS_ACTIVE => 0])->fetch();
        $theme->clearQuery();
        $theme->where(WelineTheme::schema_fields_ID, $themeId)
            ->update([WelineTheme::schema_fields_IS_ACTIVE => 1])->fetch();
    }

    private function clearActivationRuntimeCaches(int $themeId, ?string $area): void
    {
        try {
            ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class)->clearNonGlobalCaches(
                $themeId,
                'theme_backend_activate_' . ($area ?: 'global')
            );
        } catch (\Throwable) {
        }
    }

    private function themeSupportsArea(WelineTheme $theme, string $area): bool
    {
        return $this->themeHasArea($theme->getPath(), $area);
    }

    private function themeHasArea(string $themePath, string $area): bool
    {
        $themePath = trim($themePath);
        if ($themePath === '') {
            return false;
        }

        $normalizedPath = str_replace(['/', '\\'], DS, $themePath);
        $basePath = preg_match('/^[A-Za-z]:[\\\\\/]/', $normalizedPath) === 1 || str_starts_with($normalizedPath, DS)
            ? rtrim($normalizedPath, DS)
            : rtrim(Env::path_THEME_DESIGN_DIR, '/\\') . DS . trim($normalizedPath, DS);

        return is_dir($basePath . DS . $area)
            || is_dir($basePath . DS . 'view' . DS . 'theme' . DS . $area)
            || is_dir($basePath . DS . 'theme' . DS . $area);
    }
}
