<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Controller\ThemeConfig;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Frontend\Block\ThemeConfig;

class Set extends FrontendController
{
    private ThemeConfig $themeConfig;

    public function __construct(
        ThemeConfig $themeConfig,
    ) {
        $this->themeConfig = $themeConfig;
    }

    public function index(): bool|string
    {
        $data = $this->request->getBodyParams();
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            } else {
                $data = $this->request->getParams();
            }
        }
        if (!is_array($data)) {
            $data = [];
        }
        try {
            $old_layout = $this->themeConfig->getThemeConfig('layouts');
            if (isset($data['layouts']) && is_array($data['layouts']) && is_array($old_layout)) {
                $data['layouts'] = array_merge($old_layout, $data['layouts']);
            }
            if (isset($data['layouts']) && is_array($data['layouts'])) {
                foreach ($data['layouts'] as $key => $value) {
                    if ($value === '' || $value === null) {
                        unset($data['layouts'][$key]);
                    }
                }
            }
            $data = $this->normalizeThemePayload($data);
            $this->themeConfig->setThemeConfig($data);
            return json_encode($this->success());
        } catch (\Exception $exception) {
            return json_encode($this->exception($exception));
        }
    }

    private function normalizeThemePayload(array $data): array
    {
        $mode = null;
        if (isset($data['theme-mode-switch']) && is_string($data['theme-mode-switch']) && $data['theme-mode-switch'] !== '') {
            $mode = $data['theme-mode-switch'];
        } elseif (array_key_exists('dark-mode-switch', $data)) {
            $mode = (bool)$data['dark-mode-switch'] ? 'dark' : 'light';
        } elseif (array_key_exists('light-mode-switch', $data)) {
            $mode = (bool)$data['light-mode-switch'] ? 'light' : 'dark';
        } elseif (isset($data['layouts']) && is_array($data['layouts'])) {
            $layoutMode = $data['layouts']['data-theme-mode'] ?? $data['layouts']['data-layout-mode'] ?? null;
            if (is_string($layoutMode) && $layoutMode !== '') {
                $mode = $layoutMode;
            }
        }

        if ($mode === null) {
            return $data;
        }

        $data['theme-mode-switch'] = $mode;
        $data['dark-mode-switch'] = $mode === 'dark';
        $data['light-mode-switch'] = $mode === 'light';
        $layouts = isset($data['layouts']) && is_array($data['layouts']) ? $data['layouts'] : [];
        $layouts['data-theme-mode'] = $mode;
        $layouts['data-layout-mode'] = $mode;
        $data['layouts'] = $layouts;
        return $data;
    }
}
