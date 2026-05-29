<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class CustomerAccountService
{
    public function __construct(
        private readonly AuthCustomer $authCustomer,
        private readonly CustomerProfileService $customerProfileService,
        private readonly CustomerSession $customerSession,
        private readonly Request $request,
        private readonly CustomerToken $customerToken,
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function validatePasswordStrength(string $password): void
    {
        $lengthOkay = strlen($password) >= 8;
        $hasLetter = preg_match('/[A-Za-z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;

        if (!$lengthOkay || !$hasLetter || !$hasDigit) {
            throw new \InvalidArgumentException((string) __('Password must be at least 8 characters and contain letters and numbers.'));
        }
    }

    public function register(string $email, string $password, array $profileData = []): array
    {
        $email = $this->normalizeEmail($email);
        $this->validatePasswordStrength($password);

        if ($this->findAuthUserByEmail($email)) {
            throw new \RuntimeException((string) __('An account with this email already exists.'));
        }

        $beforePayload = [
            'email' => $email,
            'profile_data' => $profileData,
            'referral_code' => (string) ($profileData['referral_code'] ?? ''),
        ];
        $this->eventsManager()->dispatch('WeShop_Customer::register_before', $beforePayload);

        $authUser = $this->authCustomer->reset()
            ->clearData();
        $authUser->setEmail($email)
            ->setUsername($email)
            ->setPassword($password);
        $authUser->save();

        $profile = $this->customerProfileService->getOrCreateByAuthUser($authUser, array_merge($profileData, [
            'email' => $email,
            'status' => 'active',
        ]));

        $afterPayload = [
            'auth_user' => $authUser,
            'profile' => $profile,
            'customer_id' => (int) ($profile->getId() ?? $authUser->getId() ?? 0),
            'email' => $email,
            'profile_data' => $profileData,
            'referral_code' => (string) ($profileData['referral_code'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $this->eventsManager()->dispatch('WeShop_Customer::register_after', $afterPayload);

        return [
            'auth_user' => $authUser,
            'profile' => $profile,
        ];
    }

    public function authenticate(string $login, string $password): array
    {
        $login = trim($login);
        $authUser = $this->findAuthUserByLogin($login);

        if (!$authUser || !password_verify($password, (string) $authUser->getPassword())) {
            throw new \RuntimeException((string) __('Invalid username/email or password.'));
        }

        $profileEmail = trim((string) $authUser->getEmail());
        if ($profileEmail === '' && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $profileEmail = $this->normalizeEmail($login);
        }

        $profile = $this->customerProfileService->getOrCreateByAuthUser($authUser, ['email' => $profileEmail]);
        $status = $profile->getData(CustomerProfile::schema_fields_STATUS);
        $enabled = $status === true
            || $status === 1
            || $status === '1'
            || $status === 'active'
            || $status === 'enabled';
        if (!$enabled) {
            throw new \RuntimeException((string) __('This account has been disabled.'));
        }

        return [
            'auth_user' => $authUser,
            'profile' => $profile,
        ];
    }

    public function login(AuthCustomer $authUser, bool $rememberMe = false, int $rememberDuration = 604800): void
    {
        $this->customerSession->login($authUser);

        if ($rememberMe) {
            $token = CustomerToken::generateToken();
            $expiresAt = time() + max(3600, $rememberDuration);

            $this->customerToken->reset()
                ->where(CustomerToken::schema_fields_user_id, (int) $authUser->getId())
                ->where(CustomerToken::schema_fields_type, 'remember_me')
                ->delete()
                ->fetch();

            $this->customerToken->reset()
                ->clearData()
                ->setUserId((int) $authUser->getId())
                ->setToken($token)
                ->setType('remember_me')
                ->setTokenExpireTime($expiresAt)
                ->save();

            Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
        }
    }

    public function findAuthUserByLogin(string $login): ?AuthCustomer
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $authUser = $this->findAuthUserByEmail($login);
            if ($authUser) {
                return $authUser;
            }
        }

        $authUser = $this->authCustomer->reset()
            ->where(AuthCustomer::schema_fields_username, $login)
            ->find()
            ->fetch();

        return $authUser->getId() ? $authUser : null;
    }

    public function findAuthUserByEmail(string $email): ?AuthCustomer
    {
        $authUser = $this->authCustomer->reset()
            ->where(AuthCustomer::schema_fields_email, $this->normalizeEmail($email))
            ->find()
            ->fetch();

        return $authUser->getId() ? $authUser : null;
    }

    public function getAuthUserById(int $userId): ?AuthCustomer
    {
        if ($userId <= 0) {
            return null;
        }

        $authUser = $this->authCustomer->reset();
        $authUser->load($userId);

        return $authUser->getId() ? $authUser : null;
    }

    private function eventsManager(): EventsManager
    {
        return $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
    }
}
