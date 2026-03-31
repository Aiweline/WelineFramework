<?php

declare(strict_types=1);

namespace WeShop\Membership\Controller\Backend\Membership;

use WeShop\Membership\Service\MembershipService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    private const MEMBERSHIP_INDEX_ROUTE = '*/backend/membership';

    public function __construct(
        private readonly MembershipService $membershipService
    ) {
    }

    public function post(): string
    {
        $defaultBackUrl = (string) $this->_url->getBackendUrl(self::MEMBERSHIP_INDEX_ROUTE);
        $backUrl = self::sanitizeBackUrl(
            (string) $this->request->getParam('back_url', $defaultBackUrl),
            $defaultBackUrl
        );

        try {
            $membership = $this->membershipService->saveMembership([
                'membership_id' => $this->request->getParam('membership_id', 0),
                'customer_id' => $this->request->getParam('customer_id', 0),
                'level' => $this->request->getParam('level', 'bronze'),
                'points' => $this->request->getParam('points', 0),
            ]);

            $this->getMessageManager()->addSuccess(__('Membership saved.'));
            $this->redirect($this->_url->getBackendUrl(self::MEMBERSHIP_INDEX_ROUTE, ['id' => $membership->getId()]));
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

    private static function sanitizeBackUrl(string $backUrl, string $defaultBackUrl): string
    {
        if ($backUrl === '') {
            return $defaultBackUrl;
        }

        $parts = parse_url($backUrl);
        if ($parts === false) {
            return $defaultBackUrl;
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            return $defaultBackUrl;
        }

        return str_starts_with($backUrl, '/') ? $backUrl : $defaultBackUrl;
    }
}
