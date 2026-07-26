<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\DeveloperAccessPolicy;

/**
 * 生成开发面板「访问事件」Tab 的全局引导 HTML。
 *
 * 与 PixelBootstrapHtmlService 解耦：面板 Tab 由框架 HTML 响应全局注入，
 * 不依赖主题 layout / AI 自建整页是否走 body-end hook。
 */
class VisitorPanelBootstrapHtmlService
{
    private const PANEL_SCRIPT_VERSION = '20260725-panel-auth-1';

    public function shouldInject(): bool
    {
        try {
            return (bool)ObjectManager::getInstance(DeveloperAccessPolicy::class)->shouldInjectBootstrap();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 返回可拼接到 </body> 前的脚本标签；策略拒绝时返回空串。
     */
    public function render(): string
    {
        if (!$this->shouldInject()) {
            return '';
        }

        $panelScriptUrl = $this->moduleStaticUrl('Weline/Visitor', 'js/weline-panel-visitor.js')
            . '?v=' . self::PANEL_SCRIPT_VERSION;

        return <<<HTML
<script src="{$panelScriptUrl}"
        data-no-extract="true"
        data-load-order="last"
        data-weline-panel-visitor-bootstrap="true"></script>
HTML;
    }

    private function moduleStaticUrl(string $modulePath, string $file): string
    {
        $url = '/' . \trim($modulePath, '/') . '/view/statics/' . \ltrim($file, '/');
        if (\defined('PROD') && PROD) {
            $themePath = Env::get('theme')['path'] ?? Env::default_theme_DATA['path'];
            $url = '/static/' . \str_replace('\\', '/', (string)$themePath) . $url;
        }

        return $url;
    }
}
