<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Service;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Api\Auth\CustomerIdentity;
use Weline\Customer\Api\CustomerLoginChallengeCreatorInterface;
use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Framework\Session\SessionFactory;

class CustomerTwoFactorLoginChallengeHandler implements CustomerLoginChallengeHandlerInterface, CustomerLoginChallengeCreatorInterface
{
    private const SESSION_KEY = 'weline_customer_2fa_login_challenges';
    private const EXPIRES_IN = 300;

    public function __construct(
        private readonly TwoFactorAuthService $twoFactorAuthService,
        private readonly CustomerAccountFacadeInterface $customerAccounts,
        private readonly SessionFactory $sessionFactory
    ) {
    }

    /**
     * Called by customer login after password verification and before creating
     * the authenticated session.
     *
     * @return array{challenge_token: string, redirect: string, expires_at: int}|null
     */
    public function createChallenge(int $customerId, string $redirectUrl = '', int $rememberDuration = 0): ?array
    {
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

        $customer = $this->customerAccounts->find($customerId);
        if ($customer === null) {
            throw new \RuntimeException((string)__('The login challenge is invalid or has expired.'));
        }

        $this->customerAccounts->login($customer);
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

    private function persistRememberToken(CustomerIdentity $customer, int $rememberDuration): void
    {
        $this->customerAccounts->issueRememberToken($customer, $rememberDuration);
    }
}
