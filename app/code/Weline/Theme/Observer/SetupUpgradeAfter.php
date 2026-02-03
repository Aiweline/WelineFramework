<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\PreviewTokenService;

/**
 * 系统升级后推送预览绕过规则到 CDN
 * 
 * 在模块安装或升级后，推送 CDN 规则以绕过预览请求的缓存
 */
class SetupUpgradeAfter implements ObserverInterface
{
    private EventsManager $eventsManager;

    public function __construct(EventsManager $eventsManager)
    {
        $this->eventsManager = $eventsManager;
    }

    public function execute(Event &$event): void
    {
        // 推送预览绕过规则
        $this->pushPreviewBypassRules();
    }

    /**
     * 推送预览绕过规则到 CDN
     * 
     * 规则说明：
     * 1. 包含 weline_preview_token URL 参数的请求绕过缓存
     * 2. 包含 weline_preview_token Cookie 的请求绕过缓存
     * 3. 包含 X-Weline-Preview-Token Header 的请求绕过缓存
     */
    private function pushPreviewBypassRules(): void
    {
        $rules = [
            // URL 参数绕过规则
            [
                'type' => 'bypass',
                'name' => 'Theme Preview URL Param Bypass',
                'expression' => 'http.request.uri.query contains "' . PreviewTokenService::TOKEN_KEY . '="',
                'action' => 'bypass_cache',
            ],
            // Cookie 绕过规则
            [
                'type' => 'bypass',
                'name' => 'Theme Preview Cookie Bypass',
                'expression' => 'http.cookie contains "' . PreviewTokenService::TOKEN_KEY . '="',
                'action' => 'bypass_cache',
            ],
            // Header 绕过规则
            [
                'type' => 'bypass',
                'name' => 'Theme Preview Header Bypass',
                'expression' => 'http.request.headers["' . strtolower(PreviewTokenService::TOKEN_HEADER) . '"][0] ne ""',
                'action' => 'bypass_cache',
            ],
        ];

        try {
            // 分发 CDN 请求事件推送规则
            $eventData = [
                'action' => 'push_rule',
                'data' => ['rules' => $rules],
            ];
            
            $this->eventsManager->dispatch('Weline_Cdn::request', $eventData);
        } catch (\Throwable $e) {
            // 静默失败，不影响系统升级流程
            // 如果 CDN 模块未安装，这里会失败
        }
    }
}
