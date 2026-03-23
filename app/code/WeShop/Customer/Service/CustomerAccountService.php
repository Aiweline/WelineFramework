<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Customer\Model\Customer as CustomerProfile;
use WeShop\Customer\Session\CustomerSession;
use Weline\Customer\Model\Customer as AuthCustomer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;

class CustomerAccountService
{
    public function __construct(
        private readonly AuthCustomer $authCustomer,
        private readonly CustomerProfileService $customerProfileService,
        private readonly CustomerSession $customerSession,
        private readonly Request $request,
        private readonly CustomerToken $customerToken
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

        $authUser = $this->authCustomer->reset()
            ->clearData()
            ->setUsername($email)
            ->setPassword($password)
            ->save();

        $profile = $this->customerProfileService->getOrCreateByAuthUser($authUser, array_merge($profileData, [
            'email' => $email,
            'status' => 'active',
        ]));

        return [
            'auth_user' => $authUser,
            'profile' => $profile,
        ];
    }

    public function authenticate(string $email, string $password): array
    {
        $email = $this->normalizeEmail($email);
        $authUser = $this->findAuthUserByEmail($email);

        if (!$authUser || !password_verify($password, (string) $authUser->getPassword())) {
            throw new \RuntimeException((string) __('Invalid email or password.'));
        }

        $profile = $this->customerProfileService->getOrCreateByAuthUser($authUser, ['email' => $email]);
        $status = (string) ($profile->getData(CustomerProfile::schema_fields_STATUS) ?? 'active');
        if (!in_array($status, ['active', 'enabled'], true)) {
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
        $authUser->setSessionId($this->customerSession->getId())
            ->setLoginIp($this->request->clientIP())
            ->resetAttemptTimes()
            ->save();

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

    public function findAuthUserByEmail(string $email): ?AuthCustomer
    {
        $authUser = $this->authCustomer->reset()
            ->where(AuthCustomer::schema_fields_username, $this->normalizeEmail($email))
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
}
