<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Output\Log;
use Weline\Framework\Event\EventsManager;

/**
 * 发布日志服务
 * 
 * 记录主题发布相关的日志和发送通知
 */
class PublishLogService
{
    private Log $log;
    private EventsManager $eventsManager;

    public function __construct(Log $log, EventsManager $eventsManager)
    {
        $this->log = $log;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 记录发布成功
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int|null $userId 用户ID
     * @param array $extra 额外信息
     */
    public function logPublishSuccess(int $themeId, string $pageType, ?int $userId = null, array $extra = []): void
    {
        $message = sprintf(
            '[Theme Publish Success] Theme: %d, Page: %s, User: %s, Time: %s',
            $themeId,
            $pageType,
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        if (!empty($extra)) {
            $message .= ', Extra: ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
        }
        
        $this->log->info($message);
        
        // 发送通知事件
        $this->sendNotification('publish_success', [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'user_id' => $userId,
            'message' => __('主题 %{1} 的 %{2} 页面已成功发布', [$themeId, $pageType]),
            'extra' => $extra,
        ]);
    }

    /**
     * 记录发布失败
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $error 错误信息
     * @param int|null $userId 用户ID
     * @param array $extra 额外信息
     */
    public function logPublishFailure(int $themeId, string $pageType, string $error, ?int $userId = null, array $extra = []): void
    {
        $message = sprintf(
            '[Theme Publish Failure] Theme: %d, Page: %s, Error: %s, User: %s, Time: %s',
            $themeId,
            $pageType,
            $error,
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        if (!empty($extra)) {
            $message .= ', Extra: ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
        }
        
        $this->log->debug($message);
        
        // 发送通知事件
        $this->sendNotification('publish_failure', [
            'theme_id' => $themeId,
            'page_type' => $pageType,
            'user_id' => $userId,
            'error' => $error,
            'message' => __('主题 %{1} 的 %{2} 页面发布失败：%{3}', [$themeId, $pageType, $error]),
            'extra' => $extra,
        ]);
    }

    /**
     * 记录 CDN 刷新成功
     * 
     * @param int $themeId 主题ID
     * @param string $domain 域名
     * @param int|null $userId 用户ID
     */
    public function logCdnPurgeSuccess(int $themeId, string $domain, ?int $userId = null): void
    {
        $message = sprintf(
            '[CDN Purge Success] Theme: %d, Domain: %s, User: %s, Time: %s',
            $themeId,
            $domain,
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        $this->log->info($message);
    }

    /**
     * 记录 CDN 刷新失败
     * 
     * @param int $themeId 主题ID
     * @param string $domain 域名
     * @param string $error 错误信息
     * @param int|null $userId 用户ID
     */
    public function logCdnPurgeFailure(int $themeId, string $domain, string $error, ?int $userId = null): void
    {
        $message = sprintf(
            '[CDN Purge Failure] Theme: %d, Domain: %s, Error: %s, User: %s, Time: %s',
            $themeId,
            $domain,
            $error,
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        $this->log->debug($message);
        
        // 发送通知事件
        $this->sendNotification('cdn_purge_failure', [
            'theme_id' => $themeId,
            'domain' => $domain,
            'user_id' => $userId,
            'error' => $error,
            'message' => __('CDN 缓存刷新失败（域名：%{1}）：%{2}', [$domain, $error]),
        ]);
    }

    /**
     * 记录预览开始
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param string $token Token
     * @param int|null $userId 用户ID
     */
    public function logPreviewStart(int $themeId, string $pageType, string $token, ?int $userId = null): void
    {
        $message = sprintf(
            '[Theme Preview Start] Theme: %d, Page: %s, Token: %s..., User: %s, Time: %s',
            $themeId,
            $pageType,
            substr($token, 0, 8),
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        $this->log->info($message);
    }

    /**
     * 记录预览结束
     * 
     * @param int $themeId 主题ID
     * @param string $pageType 页面类型
     * @param int|null $userId 用户ID
     */
    public function logPreviewEnd(int $themeId, string $pageType, ?int $userId = null): void
    {
        $message = sprintf(
            '[Theme Preview End] Theme: %d, Page: %s, User: %s, Time: %s',
            $themeId,
            $pageType,
            $userId ?: 'N/A',
            date('Y-m-d H:i:s')
        );
        
        $this->log->info($message);
    }

    /**
     * 发送通知事件
     * 
     * @param string $type 通知类型
     * @param array $data 通知数据
     */
    private function sendNotification(string $type, array $data): void
    {
        try {
            // 分发主题通知事件
            $eventData = [
                'type' => $type,
                'data' => $data,
                'timestamp' => time(),
            ];
            
            $this->eventsManager->dispatch('Weline_Theme::notification', $eventData);
            
            // 通知模块可选监听 Theme-owned 事件；Theme 不反向调用具体消息模块。
        } catch (\Throwable $e) {
            // 通知发送失败不影响主流程
        }
    }
}
