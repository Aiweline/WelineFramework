<?php

declare(strict_types=1);

namespace WeShop\Compliance\Service;

use Weline\Framework\Http\Url;

class CompliancePageDataService
{
    public function __construct(
        private readonly ConsentService $consentService,
        private readonly Url $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConsentPage(int $customerId): array
    {
        $statuses = $customerId > 0 ? $this->consentService->getConsentStatuses($customerId) : $this->guestStatuses();
        $items = [];
        foreach ($this->consentDefinitions() as $consentType => $definition) {
            $items[] = [
                'code' => $consentType,
                'title' => (string) $definition['title'],
                'description' => (string) $definition['description'],
                'required' => (bool) $definition['required'],
                'accepted' => (bool) ($statuses[$consentType] ?? false),
            ];
        }

        $acceptedCount = count(array_filter($items, static fn(array $item): bool => (bool) ($item['accepted'] ?? false)));

        return [
            'can_manage' => $customerId > 0,
            'consent_items' => $items,
            'consent_count' => count($items),
            'consent_accepted_count' => $acceptedCount,
            'save_url' => $this->url->getUrl('compliance/consent/save'),
            'privacy_url' => $this->url->getUrl('compliance/privacy'),
            'consent_url' => $this->url->getUrl('compliance/consent'),
            'compliance_url' => $this->url->getUrl('compliance'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPrivacyPage(): array
    {
        return [
            'sections' => [
                [
                    'title' => (string) __('Data Collection'),
                    'body' => (string) __('We collect account, order, and support interaction data to deliver and improve storefront services.'),
                ],
                [
                    'title' => (string) __('Data Usage'),
                    'body' => (string) __('Collected data is used for checkout processing, fraud checks, logistics coordination, and customer support.'),
                ],
                [
                    'title' => (string) __('Retention and Deletion'),
                    'body' => (string) __('Data retention follows legal and tax obligations, and deletion requests are handled through compliance workflows.'),
                ],
                [
                    'title' => (string) __('Consent Controls'),
                    'body' => (string) __('You can review and update optional consent settings in your compliance center at any time.'),
                ],
            ],
            'consent_url' => $this->url->getUrl('compliance/consent'),
            'compliance_url' => $this->url->getUrl('compliance'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function consentDefinitions(): array
    {
        return [
            'cookie' => [
                'title' => __('Cookie Preferences'),
                'description' => __('Allow analytics and preference cookies to improve storefront experience.'),
                'required' => false,
            ],
            'privacy' => [
                'title' => __('Privacy Policy Acknowledgement'),
                'description' => __('Confirm you have reviewed the current privacy policy for account and order data handling.'),
                'required' => true,
            ],
            'terms' => [
                'title' => __('Terms of Service Acknowledgement'),
                'description' => __('Confirm acceptance of transaction and platform terms for ongoing account usage.'),
                'required' => true,
            ],
            'marketing' => [
                'title' => __('Marketing Communications'),
                'description' => __('Receive campaign updates, promotion alerts, and personalized recommendations.'),
                'required' => false,
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function guestStatuses(): array
    {
        return [
            'cookie' => false,
            'privacy' => false,
            'terms' => false,
            'marketing' => false,
        ];
    }
}

