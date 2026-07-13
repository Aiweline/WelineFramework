<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Api\CustomerLoginChallengeCreatorInterface;
use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
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
    private Template $template;
    private CustomerAuthReturnUrlService $authReturnUrlService;

    protected ?string $layoutType = 'account.auth';

    public function __construct(
        Template $template,
        ?CustomerAuthReturnUrlService $authReturnUrlService = null
    ) {
        $this->template = $template;
        $this->authReturnUrlService = $authReturnUrlService
            ?? ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
    }

    public function getIndex()
    {
        if ($this->isLoggedIn()) {
            return $this->redirect('/customer/account');
        }

        $explicitTarget = $this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? '';
        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        $redirectUrl = $this->authReturnUrlService->capture(
            $this->session,
            is_string($explicitTarget) ? $explicitTarget : '',
            is_string($referer) ? $referer : ''
        );

        $this->assign('redirect_url', $redirectUrl);
        $this->assign(
            'register_url',
            $this->authReturnUrlService->buildAuthPageUrl('customer/account/register', $redirectUrl)
        );
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
        $redirectUrl = $this->authReturnUrlService->resolve(
            $this->session,
            is_string($redirectUrl) ? $redirectUrl : ''
        );

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

            $redirectTarget = $this->authReturnUrlService->consume($this->session, $redirectUrl);

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
        $formattedRedirect = $this->authReturnUrlService->formatRedirect($redirectUrl);
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
        $formattedRedirect = $this->authReturnUrlService->formatInternalNavigation(
            $redirectUrl,
            '/customer/account/login'
        );
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
        return (string) $this->redirect(
            $this->authReturnUrlService->buildAuthPageUrl('customer/account/login', $redirectUrl)
        );
    }

    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
