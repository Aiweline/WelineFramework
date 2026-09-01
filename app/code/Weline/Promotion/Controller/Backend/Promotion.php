<?php

declare(strict_types=1);

namespace Weline\Promotion\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Promotion\Service\PromotionDeskService;
use Weline\Promotion\Service\PromotionScopeResolver;

#[Acl(
    'Weline_Promotion::commerce:promotion:desk',
    '万能促销运营',
    'mdi mdi-sale-outline',
    'Weline 促销运营台',
    'Weline_Backend::marketing_group',
)]
class Promotion extends BackendController
{
    public function __construct(
        private readonly PromotionDeskService $deskService,
        private readonly PromotionScopeResolver $scopeResolver,
    ) {
    }

    #[Acl(
        'Weline_Promotion::commerce:promotion:desk_index',
        '查看促销运营台',
        'mdi mdi-sale-outline',
        '查看 Weline 促销运营台',
    )]
    public function index(): string
    {
        $scope = $this->scopeResolver->resolve();
        $websiteId = (int)$this->request->getGet('website_id', $scope['website_id'] ?? 0);
        $storeCode = trim((string)$this->request->getGet('store_code', $scope['store_code'] ?? ''));
        $channelCode = trim((string)$this->request->getGet('channel_code', $scope['channel_code'] ?? ''));

        $view = $this->deskService->buildDeskView($websiteId, $storeCode, $channelCode);
        foreach ($view as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch('Weline_Promotion::templates/backend/promotion/index.phtml');
    }
}
