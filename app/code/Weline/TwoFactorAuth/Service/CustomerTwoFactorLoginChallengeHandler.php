<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Service;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\SessionFactory;

class CustomerTwoFactorLoginChallengeHandler implements CustomerLoginChallengeHandlerInterface
{
    private const SESSION_KEY = 'weline_customer_2fa_login_challenges';
    private const EXPIRES_IN = 300;

    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
        private readonly CustomerAccountService $customerAccountService,
        private readonly SessionFactory $sessionFactory
    ) {
    }

    /**
     * Called by customer login after password verification and before creating
     * the authenticated session.
     *
     * @return array{challenge_token: string, redirect: string, expires_at: int}|null
     */
    public function createChallenge(Customer $customer, string $redirectUrl = '', int $rememberDuration = 0): ?array
    {
        $customerId = (int)$customer->getId();
        if ($customerId <= 0 || !$this->twoFactorAuthService->isEnabled($customerId)) {
            return null;
        }

        $token = bin2hex(random_bytes(24));
        $expiresAt = time() + self::EXPIRES_IN;
        $redirectUrl = $this->normalizeRedirectUrl($redirectUrl);

        $session = $this->sessionFactory->createFrontendSession();
        $challenges = $this->getChallenges();
        $challenges[$token] = [
            'customer_id' => $customerId,
            'redirect_url' => $redirectUrl,
            'remember_duration' => max(0, $rememberDuration),
            'expires_at' => $expiresAt,
        ];
        $session->set(self::SESSION_KEY, $challenges);

        return [
            'challenge_token' => $token,
            'redirect' => '/customer/account/challenge?challenge_token=' . rawurlencode($token),
            'expires_at' => $expiresAt,
        ];
    }

    public function getChallengeExpiresAt(string $challengeToken): ?int
    {
        $challenge = $this->getChallenge($challengeToken);
        if (!$challenge) {
            return null;
        }

        $expiresAt = (int)($challenge['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            $this->removeChallenge($challengeToken);
            return null;
        }

        return $expiresAt;
    }

    public function completeChallenge(string $challengeToken, string $code): array
    {
        $challenge = $this->getChallenge($challengeToken);
        if (!$challenge || (int)($challenge['expires_at'] ?? 0) <= time()) {
            $this->removeChallenge($challengeToken);
            throw new \RuntimeException((string)__('The login challenge is invalid or has expired.'));
        }

        $customerId = (int)($challenge['customer_id'] ?? 0);
        $code = trim($code);
        if ($customerId <= 0 || $code === '') {
            throw new \RuntimeException((string)__('Please enter the verification code.'));
        }

        $verified = $this->twoFactorAuthService->verify($customerId, $code)
            || $this->twoFactorAuthService->verifyBackupCode($customerId, $code);
        if (!$verified) {
            throw new \RuntimeException((string)__('Verification code is incorrect.'));
        }

        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class);
        $customer->load($customerId);
        if (!$customer->getId()) {
            throw new \RuntimeException((string)__('The login challenge is invalid or has expired.'));
        }

        $this->customerAccountService->loginCustomer($customer);
        $this->persistRememberToken($customer, (int)($challenge['remember_duration'] ?? 0));
        $this->removeChallenge($challengeToken);

        return [
            'redirect_url' => (string)($challenge['redirect_url'] ?? 'customer/account'),
            'status' => 'authenticated',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getChallenges(): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        $challenges = $session->get(self::SESSION_KEY);

        return is_array($challenges) ? $challenges : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getChallenge(string $challengeToken): ?array
    {
        $challengeToken = trim($challengeToken);
        if ($challengeToken === '') {
            return null;
        }

        $challenges = $this->getChallenges();
        $challenge = $challenges[$challengeToken] ?? null;

        return is_array($challenge) ? $challenge : null;
    }

    private function removeChallenge(string $challengeToken): void
    {
        $session = $this->sessionFactory->createFrontendSession();
        $challenges = $this->getChallenges();
        unset($challenges[$challengeToken]);
        $session->set(self::SESSION_KEY, $challenges);
    }

    private function normalizeRedirectUrl(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return 'customer/account';
        }

        if (str_contains($redirectUrl, '://')) {
            $path = trim((string)(parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
            $query = trim((string)(parse_url($redirectUrl, PHP_URL_QUERY) ?? ''));
            $redirectUrl = $path . ($query !== '' ? '?' . $query : '');
        }

        $redirectUrl = ltrim($redirectUrl, '/');
        return $redirectUrl !== '' ? $redirectUrl : 'customer/account';
    }

    private function persistRememberToken(Customer $customer, int $rememberDuration): void
    {
        if ($rememberDuration <= 0) {
            return;
        }

        $token = CustomerToken::generateToken();
        $expireTime = time() + $rememberDuration;

        /** @var CustomerToken $userToken */
        $userToken = ObjectManager::getInstance(CustomerToken::class);
        $userToken->reset()
            ->where(CustomerToken::schema_fields_user_id, $customer->getId())
            ->where(CustomerToken::schema_fields_type, 'remember_me')
            ->delete();

        $userToken->reset()
            ->setUserId($customer->getId())
            ->setToken($token)
            ->setType('remember_me')
            ->setTokenExpireTime($expireTime)
            ->save();

        Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
    }
}
