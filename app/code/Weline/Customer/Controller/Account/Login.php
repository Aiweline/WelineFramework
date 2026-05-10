<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Public storefront sign-in controller for classic customer accounts and the
 * WeShop customer bridge. AJAX requests keep the JSON contract while normal
 * form posts fall back to redirects so early submits do not leak credentials
 * into the URL.
 */
class Login extends \Weline\Framework\App\Controller\FrontendController
{
    private Template $template;

    protected ?string $layoutType = 'account.auth';

    public function __construct(
        Template $template,
        private readonly ?CustomerWebAuthService $customerWebAuthService = null
    ) {
        $this->template = $template;
    }

    public function getIndex()
    {
        if ($this->isLoggedIn()) {
            return $this->redirect('/customer/account');
        }

        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        if ($referer && !str_contains($referer, '/account/login')) {
            $this->session->getSession()->set('login_referer', $referer);
        }

        $redirectUrl = trim((string) ($this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? ''));
        if ($redirectUrl === '' && is_string($referer) && $referer !== '') {
            $redirectUrl = $referer;
        }

        $this->assign('redirect_url', $redirectUrl);
        $this->assign('title', __('Sign In'));

        return $this->fetch('Weline_Customer::templates/frontend/account/login.phtml');
    }

    public function postIndex()
    {
        if ($this->isLoggedIn()) {
            if ($this->expectsJsonResponse()) {
                return $this->json([
                    'success' => true,
                    'message' => __('You are already signed in.'),
                    'redirect' => '/customer/account',
                ]);
            }

            $this->getMessageManager()->addWarning(__('You are already signed in.'));
            return $this->redirect('/customer/account');
        }

        $username = $this->request->getBodyParam('username');
        if ($username === null) {
            $username = $this->request->getPost('username');
        }
        $username = is_string($username) ? trim($username) : '';

        $password = $this->request->getBodyParam('password');
        if ($password === null) {
            $password = $this->request->getPost('password');
        }
        $password = is_string($password) ? $password : '';

        $rememberDuration = $this->request->getBodyParam('remember_duration');
        if ($rememberDuration === null) {
            $rememberDuration = $this->request->getPost('remember_duration', 0);
        }
        $rememberDuration = (int) $rememberDuration;

        $redirectUrl = $this->request->getBodyParam('redirect_url');
        if ($redirectUrl === null) {
            $redirectUrl = $this->request->getPost('redirect_url', '');
        }
        $redirectUrl = $this->normalizeRedirectTarget(is_string($redirectUrl) ? $redirectUrl : '');

        if ($username === '' || $password === '') {
            return $this->respondFailure(
                (string) __('Username/email and password are required.'),
                $redirectUrl
            );
        }

        try {
            $weShopResponse = $this->handleWeShopPasswordLogin($username, $password, $rememberDuration, $redirectUrl);
            if ($weShopResponse !== null) {
                return $weShopResponse;
            }

            /** @var Customer $user */
            $user = ObjectManager::getInstance(Customer::class);
            $user->where('username', $username)->find()->fetch();

            if (!$user->getId()) {
                return $this->respondFailure(
                    (string) __('The user does not exist.'),
                    $redirectUrl
                );
            }

            if ($user->getAttemptTimes() > 5) {
                return $this->respondFailure(
                    (string) __('Too many login attempts. Please try again later.'),
                    $redirectUrl
                );
            }

            if (!password_verify($password, $user->getPassword())) {
                $user->addAttemptTimes()
                    ->setAttemptIp($this->request->clientIP())
                    ->save();

                return $this->respondFailure(
                    (string) __('The password is incorrect.'),
                    $redirectUrl
                );
            }

            $this->session->login($user);
            $user->setSessionId($this->session->getSession()->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();
            $this->syncSandboxCookie($user->isSandboxAccount());

            if ($rememberDuration > 0) {
                $token = CustomerToken::generateToken();
                $expireTime = time() + $rememberDuration;

                /** @var CustomerToken $userToken */
                $userToken = ObjectManager::getInstance(CustomerToken::class);
                $userToken->builder()
                    ->where('user_id', $user->getId())
                    ->where('type', 'remember_me')
                    ->delete();

                $userToken->reset()
                    ->setUserId($user->getId())
                    ->setToken($token)
                    ->setType('remember_me')
                    ->setTokenExpireTime($expireTime)
                    ->save();

                Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
            }

            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new \Weline\Framework\DataObject\DataObject([
                'user' => $user,
                'request' => $this->request,
                'session' => $this->session,
            ]);
            $eventManager->dispatch('Weline_Customer_Account_Login::login_after', $eventData);

            $referer = $this->session->getSession()->get('login_referer');
            $this->session->getSession()->delete('login_referer');

            $redirectTarget = '/customer/account';
            if ($referer && $this->isValidReferer($referer)) {
                $redirectTarget = $referer;
            }

            return $this->respondSuccess(
                (string) __('Login succeeded.'),
                $redirectTarget,
                [
                    'user' => [
                        'user_id' => $user->getId(),
                        'username' => $user->getUsername(),
                        'email' => $user->getEmail(),
                        'is_sandbox' => $user->isSandboxAccount(),
                    ],
                ]
            );
        } catch (\Exception $e) {
            if (defined('DEV') && DEV) {
                w_log_error('Login error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }

            return $this->respondFailure(
                (string) __('Login failed: %{1}', [$e->getMessage()]),
                $redirectUrl
            );
        }
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if (!empty($adminPath)) {
            Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    private function handleWeShopPasswordLogin(string $username, string $password, int $rememberDuration, string $redirectUrl): ?string
    {
        if (!str_contains($username, '@')) {
            return null;
        }

        $customerWebAuthService = $this->resolveCustomerWebAuthService();

        $effectiveRedirect = $redirectUrl;
        if ($effectiveRedirect === '') {
            $effectiveRedirect = $this->normalizeRedirectTarget($this->getStoredLoginReferer());
        }

        $result = $customerWebAuthService->beginPasswordLogin(
            $username,
            $password,
            $rememberDuration > 0,
            $effectiveRedirect,
            $rememberDuration > 0 ? $rememberDuration : 604800
        );

        $this->clearStoredLoginReferer();

        if (($result['status'] ?? '') === 'challenge_required') {
            $challengeToken = (string) ($result['challenge_token'] ?? '');
            $challengePath = $challengeToken !== ''
                ? 'customer/account/challenge?challenge_token=' . rawurlencode($challengeToken)
                : 'customer/account/login';

            return $this->respondChallenge(
                (string) __('Please complete two-factor verification to finish sign in.'),
                $challengePath
            );
        }

        return $this->respondSuccess(
            (string) __('Login succeeded.'),
            (string) ($result['redirect_url'] ?? $effectiveRedirect),
            ['status' => 'authenticated']
        );
    }

    private function resolveCustomerWebAuthService(): CustomerWebAuthService
    {
        if ($this->customerWebAuthService instanceof CustomerWebAuthService) {
            return $this->customerWebAuthService;
        }

        try {
            /** @var CustomerWebAuthService $customerWebAuthService */
            $customerWebAuthService = ObjectManager::getInstance(CustomerWebAuthService::class);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                (string) __('WeShop login bridge is unavailable: %{1}', [$throwable->getMessage()]),
                previous: $throwable
            );
        }

        return $customerWebAuthService;
    }

    private function getStoredLoginReferer(): string
    {
        if (!isset($this->session)) {
            return '';
        }

        $referer = $this->session->getSession()->get('login_referer');
        return is_string($referer) ? trim($referer) : '';
    }

    private function clearStoredLoginReferer(): void
    {
        if (!isset($this->session)) {
            return;
        }

        $this->session->getSession()->delete('login_referer');
    }

    private function normalizeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '' || str_starts_with($redirectUrl, '//')) {
            return '';
        }

        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl)) {
            $absoluteUrl = $redirectUrl;
            if (!$this->isValidReferer($redirectUrl)) {
                return '';
            }

            $path = trim((string) (parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
            if ($path === '') {
                return '';
            }

            $redirectUrl = $path;
            $query = trim((string) (parse_url($absoluteUrl, PHP_URL_QUERY) ?? ''));
            if ($query !== '') {
                $redirectUrl .= '?' . $query;
            }
        } else {
            $redirectUrl = ltrim($redirectUrl, '/');
        }

        $redirectUrl = ltrim($redirectUrl, '/');
        if ($redirectUrl === '') {
            return '';
        }

        if (preg_match('#^(customer/account/login|weshop/customer/account/login)(\\?|$)#', $redirectUrl) === 1) {
            return '';
        }

        return $redirectUrl;
    }

    private function formatClientRedirect(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return '/customer/account';
        }

        if (str_contains($redirectUrl, '://') && $this->isValidReferer($redirectUrl)) {
            return $redirectUrl;
        }

        $normalized = ltrim($redirectUrl, '/');
        if ($normalized === '' || $normalized === 'weshop/customer/account/index' || $normalized === 'customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }

    private function buildLoginPageUrl(string $redirectUrl = ''): string
    {
        $target = $this->normalizeRedirectTarget($redirectUrl);
        if ($target === '') {
            return '/customer/account/login';
        }

        return '/customer/account/login?redirect_url=' . rawurlencode($target);
    }

    private function expectsJsonResponse(): bool
    {
        if ($this->request->isAjax()) {
            return true;
        }

        $acceptHeader = strtolower((string) ($this->request->getHeader('Accept') ?? ''));
        return str_contains($acceptHeader, 'application/json');
    }

    private function respondSuccess(string $message, string $redirectUrl, array $extra = []): string
    {
        $formattedRedirect = $this->formatClientRedirect($redirectUrl);
        if ($this->expectsJsonResponse()) {
            return $this->json(array_merge([
                'success' => true,
                'message' => $message,
                'redirect' => $formattedRedirect,
            ], $extra));
        }

        if ($message !== '') {
            $this->getMessageManager()->addSuccess($message);
        }

        return $this->redirect($formattedRedirect);
    }

    private function respondChallenge(string $message, string $challengePath): string
    {
        $formattedRedirect = $this->formatClientRedirect($challengePath);
        if ($this->expectsJsonResponse()) {
            return $this->json([
                'success' => true,
                'status' => 'challenge_required',
                'requires_challenge' => true,
                'message' => $message,
                'redirect' => $formattedRedirect,
            ]);
        }

        $this->getMessageManager()->addWarning($message);
        return $this->redirect($formattedRedirect);
    }

    private function respondFailure(string $message, string $redirectUrl = ''): string
    {
        if ($this->expectsJsonResponse()) {
            return $this->json([
                'success' => false,
                'message' => $message,
            ]);
        }

        $this->getMessageManager()->addError($message);
        return $this->redirect($this->buildLoginPageUrl($redirectUrl));
    }

    private function isValidReferer(string $referer): bool
    {
        $baseUrl = (string) (Env::getInstance()->getBaseUrl() ?? '');
        return str_starts_with($referer, '/')
            || ($baseUrl !== '' && str_starts_with($referer, $baseUrl));
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
