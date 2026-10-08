<?php

declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\SuperAdminObjectScopeGrantService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 网站保存后为超管补齐该站 Website 级对象写授权。
 *
 * 避免新建站仅有 All Sites 只读、主题编辑器/配置嵌入无法 UPDATE。
 */
final class WebsiteSaveAfterGrantSuperAdminObjectScope implements ObserverInterface
{
    public function __construct(
        private readonly SuperAdminObjectScopeGrantService $grantService,
    ) {
    }

    public function execute(Event &$event): void
    {
        $websiteId = (int)($event->getData('website_id') ?? 0);
        if ($websiteId < 0) {
            return;
        }

        $website = $event->getData('website');
        $code = '';
        if (\is_array($website)) {
            $code = \trim((string)($website['code'] ?? $website['website_code'] ?? ''));
            if ($websiteId === 0 && isset($website['website_id'])) {
                $websiteId = (int)$website['website_id'];
            }
        }
        if ($code === '' && \is_object($website) && \method_exists($website, 'getData')) {
            $code = \trim((string)$website->getData('code'));
            if ($websiteId === 0 && \method_exists($website, 'getId')) {
                $websiteId = (int)$website->getId();
            }
        }
        if ($websiteId < 0 || $code === '') {
            return;
        }

        try {
            $this->grantService->ensureWebsiteWriteGrant($websiteId, $code);
        } catch (\Throwable) {
            // 授权补齐失败不得阻断网站保存主链。
        }
    }
}
