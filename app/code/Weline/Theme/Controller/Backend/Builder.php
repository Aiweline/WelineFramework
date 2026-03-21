<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\ThemeBuilderSchemaService;
use Weline\Theme\Service\ThemeLayoutService;

class Builder extends BackendController
{
    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeBuilderSchemaService $builderSchemaService,
        private readonly ThemeLayoutService $themeLayoutService,
        private readonly SlotRendererService $slotRendererService,
    ) {
    }

    public function getSchema()
    {
        try {
            $themeId = (int)$this->request->getParam('theme_id', 0);
            $area = (string)$this->request->getParam('area', 'frontend');
            $pageType = $this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);
            if ($themeId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => __('缺少主题ID')]);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $this->builderSchemaService->getSchema($themeId, $area, (string)$pageType)->toArray(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function postSaveLayout()
    {
        $data = $this->getPayload();
        $themeId = (int)($data['theme_id'] ?? 0);
        $pageType = (string)($data['page_type'] ?? ThemeLayout::PAGE_TYPE_DEFAULT);
        $status = (string)($data['status'] ?? ThemeLayout::STATUS_DRAFT);
        $publish = (bool)($data['publish'] ?? false);
        $layoutData = $this->normalizeLayoutPayload($data['layout_data'] ?? $data['placements'] ?? $data['layout'] ?? []);

        if ($themeId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('缺少主题ID')]);
        }

        try {
            $this->themeLayoutService->saveLayout($themeId, $pageType, $layoutData, $status);
            if ($publish) {
                $this->themeLayoutService->publishLayout($themeId, $pageType);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => $publish ? __('布局已保存并发布') : __('布局已保存'),
                'data' => [
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                    'status' => $publish ? ThemeLayout::STATUS_PUBLISHED : $status,
                    'layout' => $this->themeLayoutService->getFullDraftLayout($themeId, $pageType),
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    public function getPreview()
    {
        $themeId = (int)$this->request->getParam('theme_id', 0);
        $area = $this->normalizeArea((string)$this->request->getParam('area', 'frontend'));
        $pageType = (string)$this->request->getParam('page_type', ThemeLayout::PAGE_TYPE_DEFAULT);
        $layoutType = (string)$this->request->getParam('layout_type', $pageType);
        $layoutOption = trim((string)$this->request->getParam('layout_option', ''));
        $status = (string)$this->request->getParam('status', ThemeLayout::STATUS_DRAFT);

        if ($themeId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('缺少主题ID')]);
        }

        try {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery()->load($themeId);
            if (!$theme->getId()) {
                return $this->fetchJson(['success' => false, 'message' => __('主题不存在')]);
            }

            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            if ($layoutOption === '') {
                $layoutOption = $this->resolveLayoutOption($area, $layoutType);
            }

            $session = ObjectManager::getInstance(\Weline\Framework\Session\Session::class);
            $session->setData('preview_theme_id', $themeId);
            $session->setData('preview_theme_area', $area);
            $this->request->setData('skip_view_file_cache', true);

            try {
                w_cache('view')->clear();
            } catch (\Throwable) {
            }
            $this->slotRendererService->clearCache();
            $this->builderSchemaService->clearCache();
            ThemeData::clearCache();
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);

            $this->assign('theme_id', $themeId);
            $this->assign('page_type', $pageType);
            $this->assign('layout_type', $layoutType);
            $this->assign('editor_mode', true);
            $this->assign('preview_mode', $status !== ThemeLayout::STATUS_PUBLISHED);
            $templatePath = "Weline_Theme::theme/{$area}/layouts/{$layoutType}/{$layoutOption}.phtml";
            $html = $this->fetchTagHtml($templatePath);

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'html' => $html,
                    'slots' => $this->slotRendererService->extractSlots($html),
                    'layout' => [
                        'type' => $layoutType,
                        'option' => $layoutOption,
                        'page_type' => $pageType,
                        'status' => $status,
                        'area' => $area,
                    ],
                ],
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function normalizeLayoutPayload(mixed $layoutData): array
    {
        if (!is_array($layoutData)) {
            return [];
        }

        $normalized = [];
        foreach ($layoutData as $area => $widgets) {
            if (is_array($widgets) && array_key_exists('widgets', $widgets) && is_array($widgets['widgets'])) {
                $widgets = $widgets['widgets'];
            }
            if (!is_array($widgets)) {
                continue;
            }

            $normalized[(string)$area] = [];
            foreach ($widgets as $index => $widget) {
                if (!is_array($widget)) {
                    continue;
                }

                $normalized[(string)$area][] = [
                    'widget_module' => (string)($widget['widget_module'] ?? $widget['module'] ?? ''),
                    'widget_type' => (string)($widget['widget_type'] ?? $widget['type'] ?? ''),
                    'widget_code' => (string)($widget['widget_code'] ?? $widget['code'] ?? ''),
                    'slot_id' => isset($widget['slot_id']) ? (string)$widget['slot_id'] : null,
                    'config' => is_array($widget['config'] ?? null) ? $widget['config'] : [],
                    'is_active' => !array_key_exists('is_active', $widget) || (bool)$widget['is_active'],
                    'sort_order' => (int)($widget['sort_order'] ?? $index),
                ];
            }
        }

        return $normalized;
    }

    private function resolveLayoutOption(string $area, string $layoutType): string
    {
        $layouts = ThemeData::getLayoutsConfig($area);
        $value = $layouts[$layoutType] ?? $layouts[$layoutType . '.value'] ?? 'default';
        return is_string($value) && $value !== '' ? $value : 'default';
    }

    private function normalizeArea(string $area): string
    {
        return strtolower($area) === 'backend' ? 'backend' : 'frontend';
    }

    private function getPayload(): array
    {
        $bodyParams = $this->request->getBodyParams();
        if (is_string($bodyParams)) {
            $decoded = json_decode($bodyParams, true);
            return is_array($decoded) ? $decoded : $this->request->getParams();
        }
        if (is_array($bodyParams) && !empty($bodyParams)) {
            return $bodyParams;
        }

        return $this->request->getParams();
    }
}
