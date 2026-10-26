<?php

declare(strict_types=1);

namespace Weline\App\Api\Rest\V1;

use Weline\App\Model\AppBuild;
use Weline\App\Service\SiteProfile;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteDomainCatalogInterface;
use Weline\Frontend\Controller\FrontendRestController;

/**
 * 公开启动配置 API。缺 #[Acl] 即公开接口。
 */
class Config extends FrontendRestController
{
    public function getConfig(): string
    {
        try {
            $websiteId = $this->resolveWebsiteId();

            $domain = '';
            $enabled = false;
            try {
                /** @var AppBuild $build */
                $build = ObjectManager::getInstance(AppBuild::class);
                $row = $build->clear()
                    ->where(AppBuild::schema_fields_website_id, $websiteId)
                    ->find()
                    ->fetch();
                if ($row && $row->getData('id')) {
                    $domain = (string)$row->getData(AppBuild::schema_fields_domain);
                    $enabled = (bool)$row->getData(AppBuild::schema_fields_enabled);
                }
            } catch (\Throwable) {
                $domain = '';
                $enabled = false;
            }

            $profile = ObjectManager::getInstance(SiteProfile::class)->resolve($websiteId, true);

            $versions = [];
            $file = BP . 'app/code/Weline/App/etc/weline_app_versions.json';
            if (is_file($file)) {
                $decoded = json_decode((string)file_get_contents($file), true);
                if (is_array($decoded)) {
                    $versions = $decoded;
                }
            }

            return $this->success(__('获取配置成功'), [
                'website_id' => $websiteId,
                'domain' => $domain,
                'universal' => $domain === '',
                'enabled' => $enabled,
                'app_name' => $profile['name'],
                'locales' => $profile['locales'],
                'default_locale' => $profile['default_locale'],
                'currencies' => $profile['currencies'],
                'default_currency' => $profile['default_currency'],
                'theme' => $profile['theme'],
                'version' => (string)($versions['module'] ?? '1.0.0'),
            ]);
        } catch (\Throwable $e) {
            return $this->error(__('获取配置失败：%{1}', [$e->getMessage()]), '', 500);
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
