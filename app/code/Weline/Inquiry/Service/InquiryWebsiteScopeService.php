<?php

declare(strict_types=1);

namespace Weline\Inquiry\Service;

use Weline\Backend\Model\BackendUser;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 询盘站点归属解析：页面上下文 → 请求 website_id → Host 绑定域名 → ScopeIdentity。
 * 查询 API 请求常常没有 WebsiteData 状态，Host 域名是最终可靠兜底。
 */
final class InquiryWebsiteScopeService
{
    public function currentWebsiteId(): int
    {
        try {
            if (class_exists(WebsiteData::class)) {
                $websiteId = WebsiteData::getWebsiteId();
                if ($websiteId !== null) {
                    return max(0, (int)$websiteId);
                }
            }
        } catch (\Throwable $e) {
            w_log_warning('Inquiry website data resolve failed: ' . $e->getMessage(), [], 'inquiry');
        }

        try {
            $requestWebsiteId = RequestContext::getWelineWebsiteId();
            if ($requestWebsiteId > 0) {
                return $requestWebsiteId;
            }
        } catch (\Throwable $e) {
            w_log_warning('Inquiry request website resolve failed: ' . $e->getMessage(), [], 'inquiry');
        }

        $hostWebsiteId = $this->websiteIdForHost($this->currentHost());
        if ($hostWebsiteId > 0) {
            return $hostWebsiteId;
        }

        try {
            $identity = RequestContext::scopeIdentity();

            return max(0, (int)($identity?->websiteId ?? 0));
        } catch (\Throwable $e) {
            w_log_warning('Inquiry scope identity resolve failed: ' . $e->getMessage(), [], 'inquiry');

            return 0;
        }
    }

    public function websiteIdForBackendUser(int $backendUserId): int
    {
        if ($backendUserId > 0) {
            try {
                $user = ObjectManager::getInstance(BackendUser::class);
                $user->reset()->clearData()->load($backendUserId);
                if ($user->getId()) {
                    $assignedWebsiteId = $user->getWebsiteId();
                    if ($assignedWebsiteId > 0) {
                        return $assignedWebsiteId;
                    }
                }
            } catch (\Throwable $e) {
                w_log_warning('Inquiry backend user website resolve failed: ' . $e->getMessage(), [], 'inquiry');
            }
        }

        return $this->currentWebsiteId();
    }

    public function websiteIdForHost(string $host): int
    {
        $host = strtolower(trim($host));
        $host = (string)preg_replace('/:\d+$/', '', $host);
        $host = rtrim($host, '.');
        if ($host === '') {
            return 0;
        }

        $candidates = [$host];
        if (str_starts_with($host, 'www.')) {
            $candidates[] = substr($host, 4);
        } else {
            $candidates[] = 'www.' . $host;
        }

        foreach ($candidates as $candidate) {
            try {
                $domain = ObjectManager::getInstance(WebsiteDomain::class);
                $domain->reset()->clearData()
                    ->where(WebsiteDomain::schema_fields_DOMAIN, $candidate)
                    ->where(WebsiteDomain::schema_fields_STATUS, WebsiteDomain::STATUS_ACTIVE)
                    ->order(WebsiteDomain::schema_fields_SUB_PATH, 'ASC')
                    ->find()
                    ->fetch();
                if ($domain->getId()) {
                    return max(0, (int)$domain->getData(WebsiteDomain::schema_fields_WEBSITE_ID));
                }
            } catch (\Throwable $e) {
                w_log_warning('Inquiry host website resolve failed: ' . $e->getMessage(), [], 'inquiry');
            }
        }

        return 0;
    }

    private function currentHost(): string
    {
        return (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    }
}
