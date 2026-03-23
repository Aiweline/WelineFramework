<?php

declare(strict_types=1);

namespace WeShop\Compliance\Service;

use WeShop\Compliance\Model\CookieConsent;
use Weline\Framework\Manager\ObjectManager;

class ConsentService
{
    /**
     * @return array<int, string>
     */
    public function getSupportedConsentTypes(): array
    {
        return ['cookie', 'privacy', 'terms', 'marketing'];
    }

    /**
     * @param array<string, mixed> $consentData
     */
    public function saveConsent(array $consentData): CookieConsent
    {
        $customerId = max(0, (int) ($consentData['customer_id'] ?? 0));
        $consentType = $this->normalizeConsentType((string) ($consentData['consent_type'] ?? 'cookie'));
        $isAccepted = !empty($consentData['is_accepted']) ? 1 : 0;

        $consent = $this->findConsent($customerId, $consentType);
        $consent->setData(CookieConsent::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(CookieConsent::schema_fields_CONSENT_TYPE, $consentType)
            ->setData(CookieConsent::schema_fields_IS_ACCEPTED, $isAccepted)
            ->save();

        return $consent;
    }

    public function hasConsented(int $customerId, string $consentType): bool
    {
        $consent = $this->findConsent(max(0, $customerId), $this->normalizeConsentType($consentType));

        return (int) ($consent->getData(CookieConsent::schema_fields_IS_ACCEPTED) ?? 0) === 1;
    }

    /**
     * @return array<string, bool>
     */
    public function getConsentStatuses(int $customerId): array
    {
        $statuses = [];
        foreach ($this->getSupportedConsentTypes() as $consentType) {
            $statuses[$consentType] = $this->hasConsented($customerId, $consentType);
        }

        return $statuses;
    }

    private function normalizeConsentType(string $consentType): string
    {
        $consentType = strtolower(trim($consentType));
        if ($consentType === '' || !in_array($consentType, $this->getSupportedConsentTypes(), true)) {
            return 'cookie';
        }

        return $consentType;
    }

    private function findConsent(int $customerId, string $consentType): CookieConsent
    {
        /** @var CookieConsent $consent */
        $consent = ObjectManager::getInstance(CookieConsent::class);
        $consent->clear()
            ->where(CookieConsent::schema_fields_CUSTOMER_ID, $customerId)
            ->where(CookieConsent::schema_fields_CONSENT_TYPE, $consentType)
            ->find()
            ->fetch();

        return $consent;
    }
}

