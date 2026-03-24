<?php

declare(strict_types=1);

namespace WeShop\Membership\Controller\Backend\Membership;

use WeShop\Membership\Service\MembershipService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly MembershipService $membershipService
    ) {
    }

    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->getBackendUrl('*/backend/membership'));

        try {
            $membership = $this->membershipService->saveMembership([
                'membership_id' => $this->request->getParam('membership_id', 0),
                'customer_id' => $this->request->getParam('customer_id', 0),
                'level' => $this->request->getParam('level', 'bronze'),
                'points' => $this->request->getParam('points', 0),
            ]);

            $this->getMessageManager()->addSuccess(__('Membership saved.'));
            $this->redirect($this->getBackendUrl('*/backend/membership', ['id' => $membership->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Membership save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
