<?php
declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Framework\Query;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Customer\Service\PasswordResetService;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

class AccountQueryProvider implements QueryProviderInterface
{
    private const AUTH_REDIRECT_PATH_PREFIXES = [
        'customer/account/login',
        'customer/account/register',
        'customer/account/forgot-password',
        'customer/account/challenge',
        'customer/account/logout',
    ];

    public function __construct(
        private readonly Customer $customerModel,
        private readonly CustomerAccountService $customerAccountService,
        private readonly PasswordResetService $passwordResetService,
        private readonly SessionFactory $sessionFactory,
        private readonly Request $request,
        private readonly EventsManager $eventsManager
    ) {
    }

    public function getProviderName(): string
    {
        return 'account';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'login' => $this->login($params),
            'register' => $this->register($params),
            'current' => $this->current(),
            'logout' => $this->logout(),
            'updateProfile' => $this->updateProfile($params),
            'updatePassword' => $this->updatePassword($params),
            'requestPasswordReset' => $this->requestPasswordReset($params),
            'resetPassword' => $this->resetPassword($params),
            'completeChallenge' => $this->completeChallenge($params),
            default => throw new \InvalidArgumentException('Account query provider does not support operation: ' . $operation),
        };
    }

    private function current(): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        $user = $session->getUser();
        if (!$session->isLoggedIn() || !$user instanceof Customer || !$user->getId()) {
            return [
                'success' => false,
                'isLogin' => false,
                'logged_in' => false,
                'user' => null,
                'message' => 'Not signed in.',
            ];
        }

        return $this->success('Signed in.', [
            'isLogin' => true,
            'logged_in' => true,
            'user' => $this->customerPayload($user),
        ]);
    }

    private function logout(): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        $userId = (int)($session->getUserId() ?? 0);
        $session->logout();
        if ($userId > 0) {
            $this->clearRememberMeToken($userId);
        }
        Cookie::set('w_ut', '', -3600, ['path' => '/']);
        Cookie::set('w_sandbox', '', -3600, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if ($adminPath !== '') {
            Cookie::set('w_sandbox', '', -3600, ['path' => '/' . ltrim($adminPath, '/')]);
        }

        return $this->success('Signed out successfully.', [
            'redirect' => '/customer/account/login',
        ]);
    }

    private function login(array $params): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        $redirectUrl = $this->normalizeRedirectTarget((string)($params['redirect_url'] ?? $params['redirect'] ?? ''));
        if ($session->isLoggedIn()) {
            return $this->success('Already signed in.', [
                'redirect' => '/customer/account',
            ]);
        }

        $username = trim((string)($params['username'] ?? $params['login'] ?? $params['email'] ?? ''));
        $password = (string)($params['password'] ?? '');
        $rememberDuration = max(0, (int)($params['remember_duration'] ?? 0));
        if ($username === '' || $password === '') {
            return $this->failure('Username/email and password are required.');
        }

        $user = $this->findCustomerByLogin($username);
        if (!$user || !$user->getId()) {
            return $this->failure('User does not exist.');
        }
        if ($user->getAttemptTimes() > 5) {
            return $this->failure('Too many sign-in attempts. Please try again later.');
        }
        if (!password_verify($password, $user->getPassword())) {
            $user->addAttemptTimes()
                ->setAttemptIp($this->request->clientIP())
                ->save();
            return $this->failure('The password is incorrect.');
        }

        $challengeHandler = $this->getChallengeHandler();
        if (method_exists($challengeHandler, 'createChallenge')) {
            $challenge = $challengeHandler->createChallenge($user, $redirectUrl, $rememberDuration);
            if (is_array($challenge) && !empty($challenge['redirect'])) {
                return $this->success('Please complete two-factor verification.', [
                    'status' => 'challenge_required',
                    'redirect' => $this->formatClientRedirect((string)$challenge['redirect']),
                    'challenge_token' => (string)($challenge['challenge_token'] ?? ''),
                    'expires_at' => (int)($challenge['expires_at'] ?? 0),
                ]);
            }
        }

        $session->login($user);
        $user->setSessionId($session->getId())
            ->setLoginIp($this->request->clientIP())
            ->resetAttemptTimes()
            ->save();
        $this->syncSandboxCookie($user->isSandboxAccount());
        $this->issueRememberMeToken($user, $rememberDuration);

        $this->eventsManager->dispatch('Weline_Customer_Account_Login::login_after', new \Weline\Framework\DataObject\DataObject([
            'user' => $user,
            'request' => $this->request,
            'session' => $session,
        ]));

        $referer = $session->get('login_referer');
        $session->delete('login_referer');
        $target = $redirectUrl !== ''
            ? $this->formatClientRedirect($redirectUrl)
            : (is_string($referer) && $referer !== '' && $this->isValidReferer($referer)
                ? $this->formatClientRedirect($referer)
                : '/customer/account');

        return $this->success('Signed in successfully.', [
            'redirect' => $target,
            'user' => $this->customerPayload($user),
        ]);
    }

    private function register(array $params): array
    {
        $session = $this->sessionFactory->createFrontendSession();
        if ($session->isLoggedIn()) {
            return $this->success('Already signed in.', [
                'redirect' => '/customer/account',
            ]);
        }

        $firstName = trim((string)($params['firstname'] ?? $params['first_name'] ?? ''));
        $lastName = trim((string)($params['lastname'] ?? $params['last_name'] ?? ''));
        $email = trim((string)($params['email'] ?? $params['username'] ?? ''));
        $password = (string)($params['password'] ?? '');
        $confirmPassword = (string)($params['confirm_password'] ?? $params['password_confirm'] ?? '');
        $agreeTerms = $this->toBool($params['agree_terms'] ?? false);
        $redirectUrl = $this->normalizeRedirectTarget((string)($params['redirect_url'] ?? $params['redirect'] ?? ''));
        $referralCode = trim((string)($params['ref'] ?? $params['referral_code'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            return $this->failure('First name and last name are required.');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failure('Please enter a valid email address.');
        }
        if ($password === '') {
            return $this->failure('Password is required.');
        }
        if ($password !== $confirmPassword) {
            return $this->failure('The password confirmation does not match.');
        }
        if (!$agreeTerms) {
            return $this->failure('Please accept the terms and privacy policy.');
        }

        try {
            $result = $this->customerAccountService->register($email, $password, [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'referral_code' => $referralCode,
            ]);
            $user = $result['customer'] ?? null;
            if (!$user instanceof Customer || !$user->getId()) {
                return $this->failure('Registration failed.');
            }

            $this->customerAccountService->loginCustomer($user);
            return $this->success('Registration succeeded. Welcome.', [
                'redirect' => $redirectUrl !== '' ? $this->formatClientRedirect($redirectUrl) : '/customer/account',
                'user' => $this->customerPayload($user),
            ]);
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage());
        }
    }

    private function updateProfile(array $params): array
    {
        $user = $this->requireCustomer();
        if (array_key_exists('avatar', $params)) {
            $user->setAvatar(trim((string)$params['avatar']));
        }
        $user->save();

        return $this->success('Profile updated successfully.', [
            'user' => $this->customerPayload($user),
        ]);
    }

    private function updatePassword(array $params): array
    {
        $user = $this->requireCustomer();
        $oldPassword = (string)($params['old_password'] ?? '');
        $newPassword = (string)($params['new_password'] ?? '');
        $confirmPassword = (string)($params['confirm_password'] ?? '');

        if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return $this->failure('Please fill in all password fields.');
        }
        if (!password_verify($oldPassword, $user->getPassword())) {
            return $this->failure('The current password is incorrect.');
        }
        if (strlen($newPassword) < 6) {
            return $this->failure('The new password is too short.');
        }
        if ($newPassword !== $confirmPassword) {
            return $this->failure('The password confirmation does not match.');
        }

        $user->setPassword($newPassword)->save();
        return $this->success('Password updated successfully.');
    }

    private function requestPasswordReset(array $params): array
    {
        $email = trim((string)($params['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failure('Please enter a valid email address.');
        }

        $resetUrl = (string)($params['reset_url'] ?? '');
        if ($resetUrl === '') {
            $resetUrl = '/customer/account/forgot-password';
        }

        $sent = $this->passwordResetService->requestReset($email, $resetUrl);
        if (!$sent) {
            return $this->failure('The email is not registered.');
        }

        return $this->success('A reset link has been sent to your email.');
    }

    private function resetPassword(array $params): array
    {
        $token = trim((string)($params['token'] ?? ''));
        $password = (string)($params['password'] ?? '');
        $confirmPassword = (string)($params['password_confirm'] ?? $params['confirm_password'] ?? '');
        if ($token === '' || $password === '') {
            return $this->failure('The reset token and new password are required.');
        }
        if ($password !== $confirmPassword) {
            return $this->failure('The password confirmation does not match.');
        }

        $reset = $this->passwordResetService->resetPassword($token, $password);
        if (!$reset) {
            return $this->failure('The reset link is invalid or has expired.');
        }

        return $this->success('Your password has been reset.', [
            'redirect' => '/customer/account/login',
        ]);
    }

    private function completeChallenge(array $params): array
    {
        $challengeToken = trim((string)($params['challenge_token'] ?? ''));
        $code = trim((string)($params['code'] ?? ''));
        if ($challengeToken === '' || $code === '') {
            return $this->failure('Please enter the verification code.');
        }

        $result = $this->getChallengeHandler()->completeChallenge($challengeToken, $code);
        return $this->success('Two-factor verification succeeded.', [
            'status' => 'authenticated',
            'redirect' => $this->formatClientRedirect((string)($result['redirect_url'] ?? 'customer/account')),
        ]);
    }

    private function findCustomerByLogin(string $login): ?Customer
    {
        $email = strtolower(trim($login));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = $this->customerModel->reset()
                ->where(Customer::schema_fields_email, $email)
                ->find()
                ->fetch();
            if ($user->getId()) {
                return $user;
            }
        }

        $user = $this->customerModel->reset()
            ->where(Customer::schema_fields_username, $login)
            ->find()
            ->fetch();

        return $user->getId() ? $user : null;
    }

    private function requireCustomer(): Customer
    {
        $user = $this->sessionFactory->createFrontendSession()->getUser();
        if (!$user instanceof Customer || !$user->getId()) {
            throw new \RuntimeException('Please sign in to continue.');
        }

        return $user;
    }

    private function getChallengeHandler(): CustomerLoginChallengeHandlerInterface
    {
        return ObjectManager::getInstance(CustomerLoginChallengeHandlerInterface::class);
    }

    private function issueRememberMeToken(Customer $user, int $rememberDuration): void
    {
        if ($rememberDuration <= 0) {
            return;
        }

        $token = CustomerToken::generateToken();
        $expireTime = time() + $rememberDuration;
        /** @var CustomerToken $userToken */
        $userToken = ObjectManager::getInstance(CustomerToken::class);
        $userToken->reset()
            ->where(CustomerToken::schema_fields_user_id, $user->getId())
            ->where(CustomerToken::schema_fields_type, 'remember_me')
            ->delete()
            ->fetch();
        $userToken->reset()
            ->setUserId((int)$user->getId())
            ->setToken($token)
            ->setType('remember_me')
            ->setTokenExpireTime($expireTime)
            ->save();

        Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
    }

    private function clearRememberMeToken(int $userId): void
    {
        /** @var CustomerToken $userToken */
        $userToken = ObjectManager::getInstance(CustomerToken::class);
        $userToken->reset()
            ->where(CustomerToken::schema_fields_user_id, $userId)
            ->where(CustomerToken::schema_fields_type, 'remember_me')
            ->delete()
            ->fetch();
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        Cookie::set('w_sandbox', $enabled ? '1' : '', $enabled ? 0 : -1, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if ($adminPath !== '') {
            Cookie::set('w_sandbox', $enabled ? '1' : '', $enabled ? 0 : -1, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    private function normalizeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = $this->decodeRedirectTarget($redirectUrl);
        if ($redirectUrl === '' || str_starts_with($redirectUrl, '//')) {
            return '';
        }
        if ((bool)preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl)) {
            if (!$this->isValidReferer($redirectUrl)) {
                return '';
            }
            $path = trim((string)(parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
            $query = trim((string)(parse_url($redirectUrl, PHP_URL_QUERY) ?? ''));
            $redirectUrl = $path . ($query !== '' ? '?' . $query : '');
        } else {
            $redirectUrl = ltrim($redirectUrl, '/');
        }
        if ($redirectUrl === '') {
            return '';
        }
        foreach (self::AUTH_REDIRECT_PATH_PREFIXES as $blocked) {
            if (preg_match('#^' . preg_quote($blocked, '#') . '(\\?|$)#', $redirectUrl) === 1) {
                return '';
            }
        }

        return $redirectUrl;
    }

    private function formatClientRedirect(string $redirectUrl): string
    {
        $redirectUrl = $this->decodeRedirectTarget($redirectUrl);
        if ($redirectUrl === '') {
            return '/customer/account';
        }
        if (str_contains($redirectUrl, '://') && $this->isValidReferer($redirectUrl)) {
            return $redirectUrl;
        }
        $normalized = ltrim($redirectUrl, '/');
        if ($normalized === '' || $normalized === 'customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }

    private function decodeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        for ($i = 0; $i < 2 && $redirectUrl !== ''; $i++) {
            $decoded = rawurldecode($redirectUrl);
            if ($decoded === $redirectUrl) {
                break;
            }
            $redirectUrl = trim($decoded);
        }

        return $redirectUrl;
    }

    private function isValidReferer(string $referer): bool
    {
        if ($referer === '' || str_starts_with($referer, '//') || str_contains($referer, '\\')) {
            return false;
        }
        if (str_starts_with($referer, '/')) {
            return true;
        }
        if ((bool)preg_match('/^[a-z][a-z0-9+.-]*:/i', $referer)) {
            $target = parse_url($referer);
            $base = parse_url((string)(Env::getInstance()->getBaseUrl() ?? ''));
            return is_array($target) && is_array($base)
                && strtolower((string)($target['scheme'] ?? '')) === strtolower((string)($base['scheme'] ?? ''))
                && strtolower((string)($target['host'] ?? '')) === strtolower((string)($base['host'] ?? ''))
                && (int)($target['port'] ?? $this->defaultPort((string)($target['scheme'] ?? ''))) === (int)($base['port'] ?? $this->defaultPort((string)($base['scheme'] ?? '')));
        }

        return trim((string)parse_url($referer, PHP_URL_PATH), '/') !== '';
    }

    private function defaultPort(string $scheme): int
    {
        return match (strtolower($scheme)) {
            'https' => 443,
            'http' => 80,
            default => 0,
        };
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        $value = strtolower(trim((string)$value));
        return \in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function customerPayload(Customer $user): array
    {
        return [
            'user_id' => (int)$user->getId(),
            'username' => (string)$user->getUsername(),
            'email' => $user->getEmail(),
            'avatar' => (string)($user->getAvatar() ?? ''),
            'is_sandbox' => $user->isSandboxAccount(),
        ];
    }

    private function success(string $message, array $data = []): array
    {
        return ['success' => true, 'message' => $message, 'data' => $data] + $data;
    }

    private function failure(string $message, array $data = []): array
    {
        return ['success' => false, 'message' => $message, 'data' => $data] + $data;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'account',
            'name' => 'Frontend account worker API',
            'description' => 'Storefront account form operations exposed through Weline.Api.',
            'module' => 'Weline_Customer',
            'operations' => [
                [
                    'name' => 'current',
                    'frontend' => true,
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'cache_ttl' => 0,
                    'auth' => 'any',
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Read current storefront customer session',
                ],
                [
                    'name' => 'login',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'auth' => 'guest',
                    'params' => [
                        'username' => ['type' => 'string', 'max_length' => 160],
                        'login' => ['type' => 'string', 'max_length' => 160],
                        'email' => ['type' => 'string', 'max_length' => 160],
                        'password' => ['type' => 'string', 'max_length' => 256],
                        'remember_duration' => ['type' => 'int', 'min' => 0, 'max' => 31536000],
                        'redirect_url' => ['type' => 'string', 'max_length' => 512],
                        'redirect' => ['type' => 'string', 'max_length' => 512],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Sign in storefront customer',
                ],
                [
                    'name' => 'register',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 8,
                    'auth' => 'guest',
                    'params' => [
                        'firstname' => ['type' => 'string', 'max_length' => 80],
                        'lastname' => ['type' => 'string', 'max_length' => 80],
                        'email' => ['type' => 'string', 'max_length' => 160],
                        'password' => ['type' => 'string', 'max_length' => 256],
                        'confirm_password' => ['type' => 'string', 'max_length' => 256],
                        'agree_terms' => ['type' => 'bool'],
                        'redirect_url' => ['type' => 'string', 'max_length' => 512],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Register storefront customer',
                ],
                [
                    'name' => 'logout',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 2,
                    'auth' => 'customer',
                    'params' => [],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Sign out storefront customer',
                ],
                [
                    'name' => 'updateProfile',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 3,
                    'auth' => 'customer',
                    'params' => [
                        'avatar' => ['type' => 'string', 'max_length' => 512],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Update signed-in account profile',
                ],
                [
                    'name' => 'updatePassword',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'auth' => 'customer',
                    'params' => [
                        'old_password' => ['type' => 'string', 'max_length' => 256],
                        'new_password' => ['type' => 'string', 'max_length' => 256],
                        'confirm_password' => ['type' => 'string', 'max_length' => 256],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Change signed-in account password',
                ],
                [
                    'name' => 'requestPasswordReset',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'auth' => 'guest',
                    'params' => [
                        'email' => ['type' => 'string', 'max_length' => 160],
                        'reset_url' => ['type' => 'string', 'max_length' => 512],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Request account password reset email',
                ],
                [
                    'name' => 'resetPassword',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'auth' => 'guest',
                    'params' => [
                        'token' => ['type' => 'string', 'max_length' => 128],
                        'password' => ['type' => 'string', 'max_length' => 256],
                        'password_confirm' => ['type' => 'string', 'max_length' => 256],
                        'confirm_password' => ['type' => 'string', 'max_length' => 256],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Complete account password reset',
                ],
                [
                    'name' => 'completeChallenge',
                    'frontend' => true,
                    'mode' => 'write',
                    'graph' => false,
                    'cost' => 5,
                    'auth' => 'guest',
                    'params' => [
                        'challenge_token' => ['type' => 'string', 'max_length' => 128],
                        'code' => ['type' => 'string', 'max_length' => 16],
                    ],
                    'returns' => ['type' => 'array'],
                    'summary' => 'Complete storefront account login challenge',
                ],
            ],
        ];
    }
}
