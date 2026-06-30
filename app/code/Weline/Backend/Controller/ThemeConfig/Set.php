<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\ThemeConfig;

use Weline\Admin\Controller\BaseController as AdminBaseController;
use Weline\Backend\Block\ThemeConfig;
use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Set extends BackendController
{
    private ThemeConfig $themeConfig;

    public function __construct(
        ThemeConfig $themeConfig,
    ) {
        $this->themeConfig = $themeConfig;
    }

    public function postIndex(): bool|string
    {
        // getBodyParams() 在 Content-Type 为 JSON 时已经自动解码为数组
        $data = $this->request->getBodyParams();
        
        // 如果返回的是字符串，尝试解码为数组
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $data = $decoded;
            } else {
                // 如果解码失败，尝试使用 getParams()
                $data = $this->request->getParams();
            }
        }
        
        // 确保 $data 是数组
        if (!is_array($data)) {
            $data = [];
        }
        
        try {
            $originThemeConfig = $this->themeConfig->getOriginThemeConfig();
            if (!\is_array($originThemeConfig)) {
                $originThemeConfig = [];
            }
            $old_layout = $originThemeConfig['layouts'] ?? [];
            if (isset($data['layouts']) && is_array($data['layouts'])) {
                // 合并旧配置
                if (is_array($old_layout)) {
                    $data['layouts'] = array_merge($old_layout, $data['layouts']);
                }
                // 移除空字符串值（表示清除该属性）
                foreach ($data['layouts'] as $key => $value) {
                    if ($value === '' || $value === null) {
                        unset($data['layouts'][$key]);
                    }
                }
            }
            $data = $this->normalizeThemePayload($data);
            $themeConfig = \array_merge($originThemeConfig, $data);
            if (isset($data['layouts']) && \is_array($data['layouts'])) {
                $themeConfig['layouts'] = $data['layouts'];
            }
            $this->themeConfig->setThemeConfig($themeConfig);
            $this->persistThemeConfigForCurrentUser($themeConfig);
            AdminBaseController::clearRuntimeFullPageCache();
            return $this->fetchJson($this->success());
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
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

    private function persistThemeConfigForCurrentUser(array $themeConfig): void
    {
        /** @var BackendUserConfig $userConfig */
        $userConfig = ObjectManager::getInstance(BackendUserConfig::class);
        $userId = $userConfig->getCurrentUserId();
        if ($userId <= 0) {
            return;
        }

        $userConfig->clear()
            ->setData(BackendUserConfig::schema_fields_key, ThemeConfig::theme_Session_Config, true)
            ->setData(
                BackendUserConfig::schema_fields_value,
                (string)\json_encode(
                    $themeConfig,
                    \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE
                )
            )
            ->setData(BackendUserConfig::schema_fields_user_id, $userId, true)
            ->setData(BackendUserConfig::schema_fields_module, 'Weline_Backend')
            ->setData(BackendUserConfig::schema_fields_name, '主题设置')
            ->save(true);
    }
}
