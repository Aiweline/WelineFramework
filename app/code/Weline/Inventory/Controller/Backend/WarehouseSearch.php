<?php

declare(strict_types=1);

namespace Weline\Inventory\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Inventory\Service\WarehouseSelectSearchService;

/**
 * 本地仓搜索 JSON（theme:search-select / inventory:warehouse:select）。
 */
#[Acl('Weline_Inventory::commerce:inventory:warehouses', '仓库选择搜索', 'home', '搜索本地仓库')]
final class WarehouseSearch extends BackendController
{
    public function search(): string
    {
        $q = trim((string)$this->request->getGet('q', ''));
        $websiteRaw = $this->request->getGet('website_id', null);
        $websiteId = null;
        if ($websiteRaw !== null && trim((string)$websiteRaw) !== '' && ctype_digit((string)$websiteRaw)) {
            $websiteId = (int)$websiteRaw;
        }
        $limit = (int)$this->request->getGet('limit', 30);
        /** @var WarehouseSelectSearchService $svc */
        $svc = ObjectManager::getInstance(WarehouseSelectSearchService::class);
        $data = $svc->search($q, $websiteId, $limit);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return (string)json_encode(
            ['success' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
