<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Theme\Service\PreviewTokenService;

/**
 * 系统升级后推送预览绕过规则到 CDN
 * 
 * 在模块安装或升级后，推送 CDN 规则以绕过预览请求的缓存
 */
class SetupUpgradeAfter implements ObserverInterface
{
    private EventsManager $eventsManager;
    private Printing $printing;

    public function __construct(EventsManager $eventsManager, Printing $printing)
    {
        $this->eventsManager = $eventsManager;
        $this->printing = $printing;
    }

    public function execute(Event &$event): void
    {
        $this->printing->note(__('正在推送主题预览 CDN 绕过规则…'));
        $pushed = $this->pushPreviewBypassRules();
        if ($pushed) {
            $this->printing->success(__('主题预览 CDN 绕过规则推送完成'));
        }
    }

    /**
     * 推送预览绕过规则到 CDN
     *
     * 规则说明：
     * 1. 路径 /~preview/{token}/…（真实预览主通道）
     * 2. 兼容旧入口：?weline_preview_token=
     * 3. Header X-Weline-Preview-Token
     * Cookie 不再作为预览身份，不推 Cookie 旁路。
     */
    private function pushPreviewBypassRules(): bool
    {
        $rules = [
            // Path namespace /~preview/{token}/…（真实预览主通道）
            [
                'type' => 'bypass',
                'name' => 'Theme Live Preview Path Bypass',
                'expression' => 'http.request.uri.path contains "/~preview/"',
                'action' => 'bypass_cache',
            ],
            // URL 参数绕过规则（兼容旧入口）
            [
                'type' => 'bypass',
                'name' => 'Theme Preview URL Param Bypass',
                'expression' => 'http.request.uri.query contains "' . PreviewTokenService::TOKEN_KEY . '="',
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
            return true;
        } catch (\Throwable $e) {
            // 不影响系统升级流程；CDN 未安装或推送失败时仅提示
            $this->printing->note(__('主题预览 CDN 规则推送跳过：%{msg}', ['msg' => $e->getMessage()]));
            return false;
        }
    }
}
