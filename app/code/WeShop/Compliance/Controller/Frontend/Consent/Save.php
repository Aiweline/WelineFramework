<?php

declare(strict_types=1);

namespace WeShop\Compliance\Controller\Frontend\Consent;

use WeShop\Compliance\Service\ConsentService;
use WeShop\Customer\Api\CustomerContextInterface;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Save extends FrontendController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly ConsentService $consentService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        $consentType = $this->readConsentType();
        if ($consentType === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Consent type is required.'),
            ]);
        }

        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0 && $consentType !== 'cookie') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Please log in to update this consent.'),
                'data' => [
                    'redirect_url' => $this->url->getUrl('customer/account/login'),
                ],
            ]);
        }

        $isAccepted = $this->readIsAccepted();
        $this->consentService->saveConsent([
            'customer_id' => max(0, $customerId),
            'consent_type' => $consentType,
            'is_accepted' => $isAccepted ? 1 : 0,
        ]);

        return $this->fetchJson([
            'success' => true,
            'message' => __('Consent preference updated.'),
            'data' => [
                'consent_type' => $consentType,
                'is_accepted' => $isAccepted,
                'statuses' => $customerId > 0
                    ? $this->consentService->getConsentStatuses($customerId)
                    : ['cookie' => $consentType === 'cookie' ? $isAccepted : false],
            ],
        ]);
    }

    public function post(): string
    {
        return $this->index();
    }

    private function readConsentType(): string
    {
        $consentType = (string) (
            $this->request->body('consent_type')
            ?? $this->request->getPost('consent_type')
            ?? $this->request->getParam('consent_type')
            ?? ''
        );

        return strtolower(trim($consentType));
    }

    private function readIsAccepted(): bool
    {
        $value = $this->request->body('is_accepted')
            ?? $this->request->getPost('is_accepted')
            ?? $this->request->getParam('is_accepted')
            ?? 0;

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

