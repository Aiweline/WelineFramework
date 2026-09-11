<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Model\DropshipListing;
use Weline\Dropship\Model\DropshipPushOutbox;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Dropship::commerce:dropship:dashboard', '数据面板', 'chart', '货源数据面板', 'Weline_Dropship::commerce:dropship:group')]
class Dashboard extends BackendController
{
    #[Acl('Weline_Dropship::commerce:dropship:dashboard_index', '查看数据面板', 'chart', '查看货源数据面板')]
    public function index(): string
    {
        $providerFilter = trim((string)$this->request->getGet('provider', ''));
        /** @var DropshipListing $listing */
        $listing = ObjectManager::getInstance(DropshipListing::class);
        /** @var DropshipPushOutbox $outbox */
        $outbox = ObjectManager::getInstance(DropshipPushOutbox::class);
        /** @var DropshipFulfillment $ful */
        $ful = ObjectManager::getInstance(DropshipFulfillment::class);

        $listingQ = $listing->clear();
        $outboxQ = $outbox->clear();
        $fulQ = $ful->clear();
        if ($providerFilter !== '') {
            $listingQ->where(DropshipListing::schema_fields_PROVIDER_CODE, $providerFilter);
            $outboxQ->where(DropshipPushOutbox::schema_fields_PROVIDER_CODE, $providerFilter);
            $fulQ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, $providerFilter);
        }
        $listings = $listingQ->select()->fetchArray() ?: [];
        $pendingTips = 0;
        foreach ($listings as $row) {
            if (!empty($row['price_drop_tip'])) {
                $pendingTips++;
            }
        }
        $outboxRows = $outboxQ->select()->fetchArray() ?: [];
        $errorOutbox = 0;
        foreach ($outboxRows as $row) {
            if (($row['status'] ?? '') === 'error') {
                $errorOutbox++;
            }
        }

        $this->assign('page_title', __('货源数据面板'));
        $this->assign('provider_filter', $providerFilter);
        $this->assign('stats', [
            'listings' => count($listings),
            'price_drop_tips' => $pendingTips,
            'fulfillments' => count($fulQ->select()->fetchArray() ?: []),
            'outbox_errors' => $errorOutbox,
        ]);

        return $this->fetch();
    }
}
