<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Api\CustomerLoginChallengeCreatorInterface;
use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Public storefront sign-in for core customer accounts.
 */
class Login extends \Weline\Framework\App\Controller\FrontendController
{
    /** 登录后不允许作为跳转目标的认证相关路径前缀（相对路径，无首尾 /） */
    private const AUTH_REDIRECT_PATH_PREFIXES = [
        'customer/account/login',
        'customer/account/register',
        'customer/account/forgot-password',
        'customer/account/challenge',
        'customer/account/logout',
    ];

    private Template $template;

    protected ?string $layoutType = 'account.auth';

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    public function getIndex()
    {
        if ($this->isLoggedIn()) {
            return $this->redirect('/customer/account');
        }

        $refererRaw = $this->request->getParam('referer') ?: $this->request->getReferer();
        $refererStored = is_string($refererRaw) && $refererRaw !== ''
            ? $this->normalizeRedirectTarget($refererRaw)
            : '';
        if ($refererStored !== '') {
            $this->session->set('login_referer', $refererStored);
        }

        $redirectUrl = trim((string) ($this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? ''));
        if ($redirectUrl === '' && $refererStored !== '') {
            $redirectUrl = $refererStored;
        }
        $redirectUrl = $this->normalizeRedirectTarget($redirectUrl);

        $this->assign('redirect_url', $redirectUrl);
        $this->assign('title', __('登录'));
        $this->assign('meta', [
            'showHeader' => false,
            'showFooter' => false,
        ]);

        return $this->fetch('Weline_Customer::templates/frontend/account/login.phtml');
    }

    public function postIndex()
    {
        if ($this->isLoggedIn()) {
            if ($this->expectsJsonResponse()) {
                return $this->json([
                    'success' => true,
                    'message' => __('你已经登录了，请先退出登录.'),
                    'redirect' => '/customer/account',
                ]);
            }

            $this->getMessageManager()->addWarning(__('你已经登录了，请先退出登录.'));
            return (string) $this->redirect('/customer/account');
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
                (string) __('请输入用户名/邮箱和密码。'),
                $redirectUrl
            );
        }

        try {
            $user = $this->findLocalCustomerByLogin($username);

            if (!$user) {
                return $this->respondFailure(
                    (string) __('用户不存在.'),
                    $redirectUrl
                );
            }

            if ($user->getAttemptTimes() > 5) {
                return $this->respondFailure(
                    (string) __('登录尝试次数过多，请稍后再试.'),
                    $redirectUrl
                );
            }

            if (!password_verify($password, $user->getPassword())) {
                $user->addAttemptTimes()
                    ->setAttemptIp($this->request->clientIP())
                    ->save();

                return $this->respondFailure(
                    (string) __('密码错误.'),
                    $redirectUrl
                );
            }

            /** @var CustomerLoginChallengeHandlerInterface $challengeHandler */
            $challengeHandler = ObjectManager::getInstance(CustomerLoginChallengeHandlerInterface::class);
            if ($challengeHandler instanceof CustomerLoginChallengeCreatorInterface) {
                $challenge = $challengeHandler->createChallenge((int)$user->getId(), $redirectUrl, $rememberDuration);
                if (is_array($challenge) && !empty($challenge['redirect'])) {
                    return $this->respondChallenge(
                        (string)__('请完成两步验证。'),
                        (string)$challenge['redirect'],
                        [
                            'challenge_token' => (string)($challenge['challenge_token'] ?? ''),
                            'expires_at' => (int)($challenge['expires_at'] ?? 0),
                        ]
                    );
                }
            }

            $this->session->login($user);
            $user->setSessionId($this->session->getId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();
            $this->syncSandboxCookie($user->isSandboxAccount());

            if ($rememberDuration > 0) {
                $token = CustomerToken::generateToken();
                $expireTime = time() + $rememberDuration;

                /** @var CustomerToken $userToken */
                $userToken = ObjectManager::getInstance(CustomerToken::class);
                $userToken->reset()
                    ->where(CustomerToken::schema_fields_user_id, $user->getId())
                    ->where(CustomerToken::schema_fields_type, 'remember_me')
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

            $referer = $this->session->get('login_referer');
            $this->session->delete('login_referer');

            $redirectTarget = '/customer/account';
            if ($redirectUrl !== '') {
                $redirectTarget = $this->formatClientRedirect($redirectUrl);
            } elseif (is_string($referer) && $referer !== '' && $this->isValidReferer($referer)) {
                $redirectTarget = $this->formatClientRedirect($referer);
            }

            return $this->respondSuccess(
                (string) __('登录成功.'),
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
        } catch (\Weline\Framework\Http\ResponseTerminateException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('登录失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }

            return $this->respondFailure(
                (string) __('登录失败: %{1}', [$e->getMessage()]),
                $redirectUrl
            );
        }
    }

    private function findLocalCustomerByLogin(string $login): ?Customer
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        /** @var Customer $user */
        $user = ObjectManager::getInstance(Customer::class);

        $email = strtolower($login);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user->where(Customer::schema_fields_email, $email)->find()->fetch();
            if ($user->getId()) {
                return $user;
            }
        }

        $user->reset();
        $user->where(Customer::schema_fields_username, $login)->find()->fetch();

        return $user->getId() ? $user : null;
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

    private function normalizeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = $this->decodeRedirectTarget($redirectUrl);
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
        if ($redirectUrl === '') {
            return '';
        }

        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($redirectUrl);
            if ($decoded === $redirectUrl) {
                break;
            }
            $redirectUrl = trim($decoded);
        }

        return $redirectUrl;
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

        return (string) $this->redirect($formattedRedirect);
    }

    private function respondChallenge(string $message, string $redirectUrl, array $extra = []): string
    {
        $formattedRedirect = $this->formatClientRedirect($redirectUrl);
        if ($this->expectsJsonResponse()) {
            return $this->json(array_merge([
                'success' => true,
                'status' => 'challenge_required',
                'message' => $message,
                'redirect' => $formattedRedirect,
            ], $extra));
        }

        if ($message !== '') {
            $this->getMessageManager()->addWarning($message);
        }

        return (string) $this->redirect($formattedRedirect);
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
        return (string) $this->redirect($this->buildLoginPageUrl($redirectUrl));
    }

    private function isValidReferer(string $referer): bool
    {
        $referer = trim($referer);
        if ($referer === '') {
            return false;
        }

        if (str_starts_with($referer, '//')
            || str_contains($referer, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $referer) === 1
        ) {
            return false;
        }

        if (str_starts_with($referer, '/')) {
            return true;
        }

        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $referer)) {
            return $this->isSameOriginUrl($referer);
        }

        $path = parse_url($referer, PHP_URL_PATH);
        return is_string($path) && trim($path, '/') !== '';
    }

    private function isSameOriginUrl(string $url): bool
    {
        $baseUrl = (string) (Env::getInstance()->getBaseUrl() ?? '');
        if ($baseUrl === '') {
            return false;
        }

        $target = parse_url($url);
        $base = parse_url($baseUrl);
        if (!is_array($target) || !is_array($base)) {
            return false;
        }

        $targetScheme = strtolower((string) ($target['scheme'] ?? ''));
        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $targetHost = strtolower((string) ($target['host'] ?? ''));
        $baseHost = strtolower((string) ($base['host'] ?? ''));

        if ($targetScheme === '' || $targetHost === '' || $targetScheme !== $baseScheme || $targetHost !== $baseHost) {
            return false;
        }

        $targetPort = (int) ($target['port'] ?? $this->defaultPortForScheme($targetScheme));
        $basePort = (int) ($base['port'] ?? $this->defaultPortForScheme($baseScheme));

        return $targetPort === $basePort;
    }

    private function defaultPortForScheme(string $scheme): int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => 0,
        };
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
