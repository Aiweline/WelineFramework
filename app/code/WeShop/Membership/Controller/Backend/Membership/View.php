<?php

declare(strict_types=1);

namespace WeShop\Membership\Controller\Backend\Membership;

use WeShop\Membership\Service\MembershipAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly MembershipAdminPageDataService $membershipAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $membershipId = max(0, (int) $this->request->getParam('id', 0));
        $membership = null;
        if ($membershipId > 0) {
            $membership = $this->membershipAdminPageDataService->getMembershipRecord($membershipId);
        }

        if ($membershipId > 0 && !$membership) {
            $this->getMessageManager()->addError(__('Membership not found.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/membership'));
            return '';
        }

        $membershipIndexUrl = $this->_url->getBackendUrl('*/backend/membership');
        $membershipSaveUrl = $this->_url->getBackendUrl('*/backend/membership/save');

        $this->assign([
            'title' => (string) __('Membership Detail'),
            'membership' => $membership ?: [
                'membership_id' => 0,
                'customer_id' => 0,
                'level' => 'bronze',
                'level_label' => (string) __('Bronze'),
                'points' => 0,
                'created_at' => '',
                'updated_at' => '',
            ],
            'membershipIndexUrl' => $membershipIndexUrl,
            'membershipSaveUrl' => $membershipSaveUrl,
            'levelOptions' => $this->membershipAdminPageDataService->getLevelOptions(),
            'levelBenefits' => $this->membershipAdminPageDataService->getLevelBenefits(),
        ]);

        return (string) $this->fetchBase('WeShop_Membership::backend/templates/membership/view.phtml');
    }

    public function getMembershipRecord(int $membershipId): ?array
    {
        return $this->membershipAdminPageDataService->getMembershipRecord($membershipId);
    }

    public function getLevelOptions(): array
    {
        return $this->membershipAdminPageDataService->getLevelOptions();
    }

    public function getLevelBenefits(): array
    {
        return $this->membershipAdminPageDataService->getLevelBenefits();
    }
}
