<?php

declare(strict_types=1);

namespace Weline\Websites\Integration\Backend;

use Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\WebsiteAclGrantService;
use Weline\Websites\Service\WebsiteEntryUrlService;

final class CurrentWebsiteStorefrontUrlProvider implements CurrentWebsiteStorefrontUrlProviderInterface
{
    public function __construct(
        private readonly WebsiteAclGrantService $grantService,
        private readonly WebsiteEntryUrlService $entryUrlService,
    ) {
    }

    public function resolve(Request $request): string
    {
        $websiteId = $this->grantService->currentWebsiteId();
        /** @var Website $website */
        $website = ObjectManager::getInstance(Website::class, [], false)->load($websiteId);
        $row = $website->getData();
        if (!\is_array($row)) {
            $row = [];
        }
        $row[Website::schema_fields_ID] = $websiteId;

        return $this->entryUrlService->resolveStorefrontForBackendChrome($request, $row);
    }
}
