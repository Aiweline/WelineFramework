<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Catalog;

/**
 * 站点绑定域名只读契约（v1）。跨模块只允许依赖本接口，不得直接引用 WebsiteDomain Model。
 */
interface WebsiteDomainCatalogInterface
{
    /**
     * 站点全部启用域名的公网入口：`scheme://domain[/sub_path]`，scheme 取自 https_enabled，主域名在前。
     *
     * @return list<string>
     */
    public function publicBaseUrls(int $websiteId): array;
}
