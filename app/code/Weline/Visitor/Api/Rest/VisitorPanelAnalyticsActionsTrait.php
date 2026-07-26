<?php

declare(strict_types=1);

namespace Weline\Visitor\Api\Rest;

use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\PixelHotBufferService;
use Weline\Visitor\Service\PixelMarkerAuditService;
use Weline\Visitor\Service\VisitorTrackingConfig;

/**
 * 开发面板「访问事件」专用动作（热缓冲 / 巡检 / 通道 / 字典）。
 *
 * 面板前端默认走 /analytics/* canonical（见 weline-panel-visitor.js）。
 * /panel/* 为兼容入口；硬闸仍为 PanelProtectedTrait::guardVisitorPanelApi。
 */
trait VisitorPanelAnalyticsActionsTrait
{
    public function getBufferStats(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            /** @var PixelHotBufferService $buffer */
            $buffer = ObjectManager::getInstance(PixelHotBufferService::class);

            return $this->success(__('获取热缓冲状态成功'), $buffer->stats());
        } catch (\Throwable $e) {
            return $this->error(__('获取热缓冲状态失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function postBufferFlush(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            $force = (bool)($this->request->getBodyParams()['force']
                ?? $this->request->getParam('force')
                ?? false);
            $limit = (int)($this->request->getBodyParams()['limit']
                ?? $this->request->getParam('limit')
                ?? 0);
            /** @var PixelHotBufferService $buffer */
            $buffer = ObjectManager::getInstance(PixelHotBufferService::class);

            return $this->success(__('热缓冲刷写完成'), $buffer->flushDue($force, $limit));
        } catch (\Throwable $e) {
            return $this->error(__('热缓冲刷写失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function postAuditMarkers(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            $body = $this->request->getBodyParams();
            if (!\is_array($body)) {
                $body = [];
            }
            $websiteId = (int)($body['websiteId']
                ?? $body['website_id']
                ?? $this->request->getParam('websiteId')
                ?? $this->request->getGet('websiteId')
                ?? 0);
            $force = (bool)($body['force'] ?? $this->request->getParam('force') ?? true);
            /** @var PixelMarkerAuditService $audit */
            $audit = ObjectManager::getInstance(PixelMarkerAuditService::class);

            return $this->success(__('像素标记巡检完成'), $audit->audit($websiteId, $force));
        } catch (\Throwable $e) {
            return $this->error(__('像素标记巡检失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function getAuditReport(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            $websiteId = (int)($this->request->getParam('websiteId')
                ?? $this->request->getGet('websiteId')
                ?? 0);
            /** @var PixelMarkerAuditService $audit */
            $audit = ObjectManager::getInstance(PixelMarkerAuditService::class);
            $report = $audit->getLatestReport($websiteId);

            return $this->success(__('获取巡检报告成功'), $report ?? []);
        } catch (\Throwable $e) {
            return $this->error(__('获取巡检报告失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function getChannelStatus(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            $websiteId = (int)($this->request->getParam('websiteId')
                ?? $this->request->getGet('websiteId')
                ?? 0);
            /** @var VisitorTrackingConfig $trackingConfig */
            $trackingConfig = ObjectManager::getInstance(VisitorTrackingConfig::class);
            $config = $trackingConfig->getRuntimeConfig();
            /** @var PixelMarkerAuditService $audit */
            $audit = ObjectManager::getInstance(PixelMarkerAuditService::class);
            $report = $audit->getLatestReport($websiteId);

            return $this->success(__('获取通道状态成功'), [
                'website_id' => $websiteId,
                'dict_version' => (string)($config['dictVersion'] ?? ''),
                'gtm' => $config['gtm'] ?? [],
                'ga4' => $config['ga4'] ?? [],
                'forwarding' => [
                    'gtm' => !empty($config['forwarders']['gtm']['enabled']),
                    'ga4' => !empty($config['forwarders']['ga4']['enabled']),
                    'exclude_local' => !empty($config['trafficRules']['excludeLocalForwarding']),
                    'consent' => !empty($config['consent']['enabled']),
                ],
                'last_audit' => $report ? [
                    'generated_at' => $report['generated_at'] ?? null,
                    'expired_at' => $report['expired_at'] ?? null,
                    'stale' => !empty($report['stale']),
                    'summary' => $report['summary'] ?? null,
                ] : null,
            ]);
        } catch (\Throwable $e) {
            return $this->error(__('获取通道状态失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function getEventDictionary(): string
    {
        if ($forbidden = $this->guardVisitorPanelApi()) {
            return $forbidden;
        }

        try {
            /** @var EventDictionaryService $dictionary */
            $dictionary = ObjectManager::getInstance(EventDictionaryService::class);

            return $this->success(__('获取事件字典成功'), $dictionary->listForPanel());
        } catch (\Throwable $e) {
            return $this->error(__('获取事件字典失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }
}
