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
            $themeConfig = $this->normalizeThemePayload($data);
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
        foreach (array_keys($data) as $key) {
            if (!in_array($key, ['theme-mode-switch', 'rtl-mode-switch'], true)) {
                throw new \InvalidArgumentException((string)__('后端主题配置字段无效：%{1}', [$key]));
            }
        }
        $mode = $this->readThemeModeValue($data, 'theme-mode-switch', ['system', 'light', 'dark']);
        if ($mode === null) {
            throw new \InvalidArgumentException((string)__('后端主题模式不能为空。'));
        }
        $normalized = ['theme-mode-switch' => $mode];
        if (array_key_exists('rtl-mode-switch', $data)) {
            if (!is_bool($data['rtl-mode-switch'])) {
                throw new \InvalidArgumentException((string)__('后端文字方向配置无效。'));
            }
            $normalized['rtl-mode-switch'] = $data['rtl-mode-switch'];
        }
        return $normalized;
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
