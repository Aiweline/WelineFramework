<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Service\DropshipChannelManager;
use Weline\Dropship\Service\DropshipFacade;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Dropship::commerce:dropship:listings', '货源商品', 'tag', '货源商品管理', 'Weline_Dropship::commerce:dropship:group')]
class Listing extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:listings_index', '查看货源商品', 'tag', '查看货源商品')]
    public function index(): string
    {
        $sourcesRaw = $this->request->getGet('sources', '');
        if (is_array($sourcesRaw)) {
            $sources = array_values(array_filter(array_map(static fn ($v) => trim((string)$v), $sourcesRaw)));
        } else {
            $sources = array_values(array_filter(array_map('trim', explode(',', (string)$sourcesRaw))));
        }
        /** @var DropshipListing $model */
        $model = ObjectManager::getInstance(DropshipListing::class);
        $q = $model->clear();
        if ($sources !== []) {
            $q->where(DropshipListing::schema_fields_PROVIDER_CODE, $sources, 'IN');
        }
        $rows = $q->order(DropshipListing::schema_fields_ID, 'DESC')->limit(100)->select()->fetchArray();
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $channels->registerAllProviders();
        $providerCodes = array_map(static fn ($p) => $p->getCode(), $channels->getProviders());

        $this->assign('page_title', __('货源商品'));
        $this->assign('listings', is_array($rows) ? $rows : []);
        $this->assign('provider_codes', $providerCodes);
        $this->assign('selected_sources', $sources);

        return $this->fetch();
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_search', '搜索远程货源', 'tag', '搜索远程货源商品')]
    public function search(): string
    {
        $providerCode = (string)$this->request->getGet('provider', 'cj');
        $keyword = (string)$this->request->getGet('q', '');
        /** @var DropshipChannelManager $channels */
        $channels = ObjectManager::getInstance(DropshipChannelManager::class);
        $provider = $channels->getProvider($providerCode);
        $items = [];
        if ($provider instanceof DropshipCatalogProviderInterface) {
            $items = array_map(static fn (DropshipCatalogSnapshot $s) => $s->toArray(), $provider->searchProducts([
                'keyword' => $keyword,
                'country_code' => (string)$this->request->getGet('country_code', ''),
            ]));
        }
        $this->assign('page_title', __('搜索货源'));
        $this->assign('provider_code', $providerCode);
        $this->assign('items', $items);
        $this->assign('keyword', $keyword);

        return $this->fetch('Backend/Listing/search');
    }

    #[Acl('Weline_Dropship::commerce:dropship:listings_publish', '刊登货源商品', 'tag', '刊登到本地')]
    public function postPublish(): string
    {
        $providerCode = (string)$this->request->getPost('provider_code', '');
        $payload = (string)$this->request->getPost('snapshot_json', '{}');
        $snapshot = DropshipCatalogSnapshot::fromArray(json_decode($payload, true) ?: []);
        /** @var DropshipFacade $facade */
        $facade = ObjectManager::getInstance(DropshipFacade::class);
        try {
            $result = $facade->publishSnapshot($providerCode, $snapshot, [
                'website_id' => (int)$this->request->getPost('website_id', 0),
                'store_id' => (int)$this->request->getPost('store_id', 0),
                'channel' => (string)$this->request->getPost('channel', 'default'),
            ]);
            $this->getMessage()->success(__('已刊登 listing #%1', [$result['listing_id'] ?? 0]));
        } catch (\Throwable $e) {
            $this->getMessage()->error($e->getMessage());
        }

        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl('dropship/backend/listing/index'));
    }
}
