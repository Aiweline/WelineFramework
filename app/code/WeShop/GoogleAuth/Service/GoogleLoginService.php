<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerProfileService;
use Weline\Backend\Model\BackendUser;
use Weline\Customer\Model\Customer as AuthCustomer;

class GoogleLoginService
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuthService,
        private readonly GoogleBindingService $googleBindingService,
        private readonly CustomerAccountService $customerAccountService,
        private readonly CustomerProfileService $customerProfileService,
        private readonly AuthCustomer $authCustomer,
        private readonly BackendUser $backendUser
    ) {
    }

    public function authenticateByCode(string $area, string $code): array
    {
        $googleUser = $this->googleOAuthService->fetchGoogleUser($code);

        return match ($this->normalizeArea($area)) {
            'frontend' => $this->authenticateFrontend($googleUser),
            'backend' => $this->authenticateBackend($googleUser),
        };
    }

    public function bindByCode(string $area, int $localUserId, string $code): array
    {
        $area = $this->normalizeArea($area);
        if ($localUserId <= 0) {
            throw new \InvalidArgumentException((string) __('A local user is required for Google binding.'));
        }

        $googleUser = $this->googleOAuthService->fetchGoogleUser($code);

        match ($area) {
            'frontend' => $this->assertFrontendUserExists($localUserId),
            'backend' => $this->assertBackendUserCanBind($localUserId),
        };

        $binding = $this->googleBindingService->bind(
            $area,
            $localUserId,
            (string) $googleUser['sub'],
            (string) $googleUser['email']
        );
        $this->googleBindingService->touchLastLogin($binding);

        return [
            'binding' => $binding,
            'google_user' => $googleUser,
        ];
    }

    public function unbind(string $area, int $localUserId): bool
    {
        return $this->googleBindingService->unbind($this->normalizeArea($area), $localUserId);
    }

    public function getBinding(string $area, int $localUserId): ?\WeShop\GoogleAuth\Model\GoogleBinding
    {
        return $this->googleBindingService->getBinding($this->normalizeArea($area), $localUserId);
    }

    private function authenticateFrontend(array $googleUser): array
    {
        $binding = $this->googleBindingService->getByGoogleSubject('frontend', (string) $googleUser['sub']);
        $authUser = null;

        if ($binding) {
            $authUser = $this->customerAccountService->getAuthUserById(
                (int) $binding->getData(\WeShop\GoogleAuth\Model\GoogleBinding::schema_fields_LOCAL_USER_ID)
            );
        }

        if (!$authUser) {
            $authUser = $this->customerAccountService->findAuthUserByEmail((string) $googleUser['email']);
        }

        if (!$authUser) {
            $register = $this->customerAccountService->register(
                (string) $googleUser['email'],
                $this->generateRandomPassword(),
                $this->buildCustomerProfileData($googleUser)
            );
            $authUser = $register['auth_user'];
        }

        $profile = $this->customerProfileService->getOrCreateByAuthUser($authUser, $this->buildCustomerProfileData($googleUser));
        $status = (string) ($profile->getData(CustomerProfile::schema_fields_STATUS) ?? 'active');
        if (!in_array($status, ['active', 'enabled'], true)) {
            throw new \RuntimeException((string) __('This account has been disabled.'));
        }

        $binding = $this->googleBindingService->bind(
            'frontend',
            (int) $authUser->getId(),
            (string) $googleUser['sub'],
            (string) $googleUser['email']
        );
        $this->googleBindingService->touchLastLogin($binding);

        return [
            'actor_type' => ActorContext::ACTOR_CUSTOMER,
            'actor_id' => (int) $authUser->getId(),
            'scopes' => ['customer'],
            'email' => (string) $googleUser['email'],
        ];
    }

    private function authenticateBackend(array $googleUser): array
    {
        $binding = $this->googleBindingService->getByGoogleSubject('backend', (string) $googleUser['sub']);
        if (!$binding) {
            throw new \RuntimeException((string) __('This Google account is not bound to any backend user.'));
        }

        $backendUser = $this->assertBackendUserCanBind(
            (int) $binding->getData(\WeShop\GoogleAuth\Model\GoogleBinding::schema_fields_LOCAL_USER_ID)
        );
        $this->googleBindingService->touchLastLogin($binding);

        return [
            'actor_type' => ActorContext::ACTOR_BACKEND,
            'actor_id' => (int) $backendUser->getId(),
            'scopes' => ['backend'],
            'email' => (string) $googleUser['email'],
        ];
    }

    private function assertFrontendUserExists(int $localUserId): AuthCustomer
    {
        $authUser = $this->customerAccountService->getAuthUserById($localUserId);
        if (!$authUser) {
            throw new \RuntimeException((string) __('The customer account no longer exists.'));
        }

        return $authUser;
    }

    private function assertBackendUserCanBind(int $localUserId): BackendUser
    {
        $backendUser = clone $this->backendUser;
        $backendUser->load($localUserId);

        if (!$backendUser->getId() || !$backendUser->getIsEnabled()) {
            throw new \RuntimeException((string) __('The backend account is unavailable.'));
        }

        $userRole = $backendUser->getRole();
        $hasRole = (bool) ($userRole && $userRole->getRoleId());
        $isSuperAdminById = (int) $backendUser->getId() === 1;
        if (!$hasRole && !$isSuperAdminById) {
            throw new \RuntimeException((string) __('The backend account has no role and cannot sign in.'));
        }

        return $backendUser;
    }

    private function buildCustomerProfileData(array $googleUser): array
    {
        $firstName = trim((string) ($googleUser['given_name'] ?? ''));
        $lastName = trim((string) ($googleUser['family_name'] ?? ''));
        $name = trim((string) ($googleUser['name'] ?? ''));

        if (($firstName === '' || $lastName === '') && $name !== '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $firstName = $firstName !== '' ? $firstName : (string) ($parts[0] ?? '');
            if ($lastName === '' && count($parts) > 1) {
                $lastName = implode(' ', array_slice($parts, 1));
            }
        }

        return [
            'email' => (string) $googleUser['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'avatar' => (string) ($googleUser['picture'] ?? ''),
            'status' => 'active',
        ];
    }

    private function generateRandomPassword(): string
    {
        return 'Gs' . bin2hex(random_bytes(8)) . '9a';
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException((string) __('Unsupported Google auth area: %{1}', [$area]));
        }

        return $area;
    }
}
