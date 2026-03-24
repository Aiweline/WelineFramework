<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Customer\Model\Customer as CustomerProfile;
use Weline\Customer\Model\Customer as AuthCustomer;

class CustomerProfileService
{
    public function __construct(
        private readonly CustomerProfile $customerProfile
    ) {
    }

    public function getByUserId(int $userId): ?CustomerProfile
    {
        $profile = $this->customerProfile->reset()
            ->where(CustomerProfile::schema_fields_USER_ID, $userId)
            ->find()
            ->fetch();

        return $profile->getId() ? $profile : null;
    }

    public function getByEmail(string $email): ?CustomerProfile
    {
        $profile = $this->customerProfile->reset()
            ->where(CustomerProfile::schema_fields_EMAIL, $email)
            ->find()
            ->fetch();

        return $profile->getId() ? $profile : null;
    }

    public function getOrCreateByAuthUser(AuthCustomer $authUser, array $profileData = []): CustomerProfile
    {
        $email = (string) ($profileData['email'] ?? $authUser->getEmail() ?: $authUser->getUsername());
        $profile = $this->getByUserId((int) $authUser->getId())
            ?? ($email !== '' ? $this->getByEmail($email) : null)
            ?? $this->customerProfile->reset()->clearData();
        $now = date('Y-m-d H:i:s');
        $enabled = in_array((string) ($profileData['status'] ?? 'active'), ['active', 'enabled', '1'], true) ? 1 : 0;

        if (!$profile->getId()) {
            $profile->setData(CustomerProfile::schema_fields_ID, (int) $authUser->getId());
        }

        $profile->setData(CustomerProfile::schema_fields_EMAIL, $email)
            ->setData(CustomerProfile::schema_fields_STATUS, $enabled)
            ->setData(CustomerProfile::schema_fields_FIRST_NAME, $profileData['first_name'] ?? $profile->getData(CustomerProfile::schema_fields_FIRST_NAME))
            ->setData(CustomerProfile::schema_fields_LAST_NAME, $profileData['last_name'] ?? $profile->getData(CustomerProfile::schema_fields_LAST_NAME))
            ->setData(CustomerProfile::schema_fields_PHONE, $profileData['phone'] ?? $profile->getData(CustomerProfile::schema_fields_PHONE))
            ->setData(CustomerProfile::schema_fields_AVATAR, $profileData['avatar'] ?? $profile->getData(CustomerProfile::schema_fields_AVATAR))
            ->setData(CustomerProfile::schema_fields_UPDATED_AT, $now);

        if (!$profile->getId()) {
            $profile->setData(CustomerProfile::schema_fields_CREATED_AT, $now);
        }

        $profile->save();

        return $profile;
    }

    public function updateProfile(AuthCustomer $authUser, array $profileData): CustomerProfile
    {
        return $this->getOrCreateByAuthUser($authUser, $profileData);
    }
}
