<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Frontend\Affiliate;

use Weline\Affiliate\Service\AffiliateService;
use Weline\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;

class Redirect extends FrontendController
{
    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly CustomerSession $customerSession
    ) {
    }

    public function index(): string
    {
        $shareCode = trim((string) ($this->request->getParam('code') ?? ''));
        if ($shareCode === '') {
            $this->getMessageManager()->addError(\__('分享链接无效或已过期。'));
            $this->redirect('product/list');
            return '';
        }

        $targetUrl = 'product/list';
        try {
            $result = $this->affiliateService->recordShareClick($shareCode, $this->getCustomerId());
            $targetUrl = (string) ($result['target_url'] ?? $targetUrl);
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        $this->redirect($targetUrl !== '' ? $targetUrl : 'product/list');
        return '';
    }

    private function getCustomerId(): int
    {
        try {
            return (int) ($this->customerSession->getUserId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
