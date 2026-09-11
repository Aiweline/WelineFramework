<?php

declare(strict_types=1);

namespace Weline\B2B\Extends\Module\Weline_Faq\FaqPageProvider;

use Weline\B2B\Service\SellingModePolicy;
use Weline\Faq\Api\FaqPageProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;

/** Wholesale identity / VIP / credit / deposit help page for /faq/b2b-wholesale. */
final class B2BWholesaleFaqPageProvider implements FaqPageProviderInterface
{
    public function __construct(
        private readonly ?SellingModePolicy $sellingMode = null,
    ) {
    }

    public function pageCode(): string
    {
        return 'b2b-wholesale';
    }

    public function slug(): string
    {
        return 'b2b-wholesale';
    }

    public function title(): string
    {
        return (string)__('批发身份与信用说明');
    }

    public function summary(): string
    {
        return (string)__('VIP 等级、批发信用额度、定金与汇率换算说明');
    }

    public function sortOrder(): int
    {
        return 15;
    }

    public function isEnabled(): bool
    {
        try {
            $websiteId = max(0, (int)RequestContext::getWelineWebsiteId());
            $storeId = max(0, (int)RequestContext::getWelineStoreId());
            $policy = $this->sellingMode ?? ObjectManager::getInstance(SellingModePolicy::class);

            return $policy->isModeEnabled(SellingModePolicy::MODE_TOB, $websiteId, $storeId);
        } catch (\Throwable) {
            return true;
        }
    }

    public function template(): string
    {
        return 'Weline_B2B::templates/frontend/faq/b2b-wholesale.phtml';
    }

    public function group(): string
    {
        return (string)__('批发');
    }
}
