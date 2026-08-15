<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\ThemeConfig;

use Weline\Backend\Block\ThemeConfig;
use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Set extends BackendController
{
    private ThemeConfig $themeConfig;

    public function __construct(ThemeConfig $themeConfig)
    {
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
            // Validate explicit three-state fields before legacy blank-clearing
            // or old-layout merging can hide a malformed request value.
            $data = $this->normalizeThemePayload($data);
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
                if (($data['theme-mode-switch'] ?? null) === 'system') {
                    unset(
                        $data['layouts']['data-theme-mode'],
                        $data['layouts']['data-layout-mode'],
                        $data['layouts']['data-topbar'],
                        $data['layouts']['data-sidebar']
                    );
                }
                // 移除空字符串值（表示清除该属性）
                foreach ($data['layouts'] as $key => $value) {
                    if ($value === '' || $value === null) {
                        unset($data['layouts'][$key]);
                    }
                }
            }
            $themeConfig = \array_merge($originThemeConfig, $data);
            if (isset($data['layouts']) && \is_array($data['layouts'])) {
                $themeConfig['layouts'] = $data['layouts'];
            }
            $this->themeConfig->setThemeConfig($themeConfig);
            $this->persistThemeConfigForCurrentUser($themeConfig);
            // This endpoint persists a per-user preference.  Invalidating the
            // backend runtime cache here also clears the active backend
            // session in WLS, logging the user out during a theme switch.
            return $this->fetchJson($this->success());
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    private function normalizeThemePayload(array $data): array
    {
        if (array_key_exists('layouts', $data) && !is_array($data['layouts'])) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
        $layouts = $data['layouts'] ?? [];
        $layouts = is_array($layouts) ? $layouts : [];

        // Validate every explicit new-contract value before selecting precedence.
        // This prevents malformed request data from being hidden by legacy flags.
        $switchMode = $this->readThemeModeValue($data, 'theme-mode-switch', ['system', 'light', 'dark']);
        $preferenceMode = $this->readThemeModeValue($layouts, 'data-theme-preference', ['system', 'light', 'dark']);
        $resolvedThemeMode = $this->readThemeModeValue($layouts, 'data-theme-mode', ['light', 'dark']);
        $resolvedLayoutMode = $this->readThemeModeValue($layouts, 'data-layout-mode', ['light', 'dark']);

        $mode = null;
        if ($switchMode !== null) {
            $mode = $switchMode;
        } elseif ($preferenceMode !== null) {
            $mode = $preferenceMode;
        } elseif (array_key_exists('dark-mode-switch', $data)) {
            $mode = $this->normalizeLegacyBool($data['dark-mode-switch']) ? 'dark' : 'light';
        } elseif (array_key_exists('light-mode-switch', $data)) {
            $mode = $this->normalizeLegacyBool($data['light-mode-switch']) ? 'light' : 'dark';
        } elseif ($resolvedThemeMode !== null || $resolvedLayoutMode !== null) {
            $mode = $resolvedThemeMode ?? $resolvedLayoutMode;
        }

        if ($mode === null) {
            return $data;
        }

        $data['theme-mode-switch'] = $mode;
        $data['dark-mode-switch'] = $mode === 'dark';
        $data['light-mode-switch'] = $mode === 'light';
        $layouts['data-theme-preference'] = $mode;
        if ($mode === 'light' || $mode === 'dark') {
            $layouts['data-theme-mode'] = $mode;
            $layouts['data-layout-mode'] = $mode;
        } else {
            // `system` persists only a preference.  Resolved presentation
            // attributes must not survive an Admin fallback request, otherwise
            // an old fixed topbar/sidebar is restored on the next render.
            unset(
                $layouts['data-theme-mode'],
                $layouts['data-layout-mode'],
                $layouts['data-topbar'],
                $layouts['data-sidebar']
            );
        }
        $data['layouts'] = $layouts;
        return $data;
    }

    /** @param array<string, mixed> $source @param list<string> $allowed */
    private function readThemeModeValue(array $source, string $key, array $allowed): ?string
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }
        if (!is_string($source[$key])) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }

        $mode = strtolower(trim($source[$key]));
        if ($mode === '') {
            throw new \InvalidArgumentException((string)__('后端主题模式无效。'));
        }
        if (!in_array($mode, $allowed, true)) {
            throw new \InvalidArgumentException((string)__('后端主题模式无效：%{1}', $mode));
        }

        return $mode;
    }

    /**
     * Historical configuration stored booleans as JSON values and, in older
     * installations, as form strings.  PHP considers every non-empty string
     * truthy, including "false", so normalise before mapping the legacy flags.
     */
    private function normalizeLegacyBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return false;
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
