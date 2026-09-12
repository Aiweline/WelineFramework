<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\OfferSelectSearchService;

/**
 * Offer 搜索 JSON（theme:search-select / product:offer:select）。
 */
#[Acl('Weline_Product::commerce:catalog:offers', 'Offer 选择搜索', 'tag', '搜索销售规格 Offer')]
final class OfferSearch extends BackendController
{
    public function search(): string
    {
        $q = trim((string)$this->request->getGet('q', ''));
        $websiteId = (int)$this->request->getGet('website_id', 0);
        $limit = (int)$this->request->getGet('limit', 30);
        /** @var OfferSelectSearchService $svc */
        $svc = ObjectManager::getInstance(OfferSelectSearchService::class);
        $data = $svc->search($q, $websiteId, $limit);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string)json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
