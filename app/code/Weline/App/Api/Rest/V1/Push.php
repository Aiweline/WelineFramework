<?php

declare(strict_types=1);

namespace Weline\App\Api\Rest\V1;

use Weline\App\Model\AppDeviceToken;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteDomainCatalogInterface;
use Weline\Frontend\Controller\FrontendRestController;

/**
 * 公开推送令牌 API。缺 #[Acl] 即公开接口（guest 白名单由框架 PublicApiAuthRouteMatcher 管理）。
 */
class Push extends FrontendRestController
{
    public function postRegister(): string
    {
        try {
            $platform = strtolower(trim((string)$this->request->getParam('platform', '')));
            if (!in_array($platform, ['android', 'ios'], true)) {
                return $this->error(__('非法平台'), '', 400);
            }
            $token = trim((string)$this->request->getParam('token', ''));
            if ($token === '' || mb_strlen($token) > 512) {
                return $this->error(__('缺少令牌'), '', 400);
            }

            $websiteId = $this->resolveWebsiteId();
            $now = date('Y-m-d H:i:s');
            $tokenHash = hash('sha256', $token);

            /** @var AppDeviceToken $device */
            $device = ObjectManager::getInstance(AppDeviceToken::class);
            $row = $device->clear()
                ->where(AppDeviceToken::schema_fields_website_id, $websiteId)
                ->where(AppDeviceToken::schema_fields_platform, $platform)
                ->where(AppDeviceToken::schema_fields_token_hash, $tokenHash)
                ->find()
                ->fetch();

            $data = [
                AppDeviceToken::schema_fields_website_id => $websiteId,
                AppDeviceToken::schema_fields_platform => $platform,
                AppDeviceToken::schema_fields_token => $token,
                AppDeviceToken::schema_fields_token_hash => $tokenHash,
                AppDeviceToken::schema_fields_locale => mb_substr((string)$this->request->getParam('locale', ''), 0, 16),
                AppDeviceToken::schema_fields_currency => mb_substr((string)$this->request->getParam('currency', ''), 0, 8),
                AppDeviceToken::schema_fields_customer_id => (int)$this->request->getParam('customer_id', 0),
                AppDeviceToken::schema_fields_app_version => mb_substr((string)$this->request->getParam('app_version', ''), 0, 32),
                AppDeviceToken::schema_fields_os_version => mb_substr((string)$this->request->getParam('os_version', ''), 0, 32),
                AppDeviceToken::schema_fields_enabled => 1,
                AppDeviceToken::schema_fields_last_seen_at => $now,
                AppDeviceToken::schema_fields_updated_at => $now,
            ];
            if (!$row || !(int)$row->getData(AppDeviceToken::schema_fields_ID)) {
                $data[AppDeviceToken::schema_fields_created_at] = $now;
            }
            $row->setData($data);
            $row->save();

            return $this->success(__('注册成功'), [
                'website_id' => $websiteId,
                'platform' => $platform,
                'enabled' => 1,
            ]);
        } catch (\Throwable $e) {
            return $this->error(__('注册失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    public function postUnregister(): string
    {
        try {
            $token = trim((string)$this->request->getParam('token', ''));
            if ($token === '') {
                return $this->error(__('缺少令牌'), '', 400);
            }
            $tokenHash = hash('sha256', $token);

            /** @var AppDeviceToken $device */
            $device = ObjectManager::getInstance(AppDeviceToken::class);
            $rows = $device->clear()
                ->where(AppDeviceToken::schema_fields_token_hash, $tokenHash)
                ->fetch()
                ->getItems();
            foreach ($rows as $row) {
                $row->setData([AppDeviceToken::schema_fields_enabled => 0]);
                $row->save();
            }

            return $this->success(__('已注销'));
        } catch (\Throwable $e) {
            return $this->error(__('注销失败：%{1}', [$e->getMessage()]), '', 500);
        }
    }

    private function resolveWebsiteId(): int
    {
        $host = strtolower(trim((string)($this->request->getServer('HTTP_HOST') ?: '')));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host !== '') {
            try {
                /** @var WebsiteCatalogInterface $catalog */
                $catalog = ObjectManager::getInstance(WebsiteCatalogInterface::class);
                $domainCatalog = ObjectManager::getInstance(WebsiteDomainCatalogInterface::class);
                foreach ($catalog->all() as $website) {
                    $websiteId = (int)($website->id ?? $website->website_id ?? 0);
                    if (!$websiteId) {
                        continue;
                    }
                    foreach ($domainCatalog->publicBaseUrls($websiteId) as $url) {
                        $urlHost = strtolower(trim((string)parse_url($url, PHP_URL_HOST)));
                        if ($urlHost !== '' && $urlHost === $host) {
                            return $websiteId;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }
        try {
            return ObjectManager::getInstance(WebsiteCatalogInterface::class)->defaultWebsiteId();
        } catch (\Throwable) {
            return 0;
        }
    }
}
