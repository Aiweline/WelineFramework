<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Customer\Service\SocialLogin\SocialLoginAccountLinker;
use Weline\Customer\Service\SocialLogin\SocialLoginOAuthService;
use Weline\Customer\Service\SocialLogin\SocialLoginQuickAuthService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\RedirectException;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Storefront OAuth start/callback and account-center bind/unbind for social login.
 */
class SocialLogin extends FrontendController
{
    protected ?string $layoutType = 'account.auth';

    public function getStart()
    {
        // Maintenance recovery probes (HEAD / _maintenance_recovery_probe) must not
        // start OAuth or leave side effects — they previously raced real browser GETs.
        if ($this->isMaintenanceRecoveryProbe()) {
            return $this->respondOAuthProbeSkip();
        }

        $provider = strtolower(trim((string) ($this->request->getParam('provider') ?? '')));
        $intent = strtolower(trim((string) ($this->request->getParam('intent') ?? '')));
        $returnUrl = (string) ($this->request->getParam('return_url')
            ?? $this->request->getParam('redirect_url')
            ?? '');
        $authReturn = ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);
        $current = $accounts->current();

        if ($intent === '' || $intent === SocialLoginOAuthService::INTENT_LOGIN) {
            $intent = $current !== null
                ? SocialLoginOAuthService::INTENT_BIND
                : SocialLoginOAuthService::INTENT_LOGIN;
        }

        if ($intent === SocialLoginOAuthService::INTENT_BIND) {
            if ($current === null) {
                MessageManager::error((string) __('请先登录后再绑定社媒账户'));

                return $this->redirect($this->getUrl('customer/account/login', [
                    'redirect_url' => '/customer/account/index#social-login',
                ]));
            }
            if ($returnUrl === '') {
                $returnUrl = '/customer/account/index#social-login';
            }
        } elseif ($current !== null) {
            return $this->redirect('/customer/account/index#social-login');
        }

        try {
            $captured = $authReturn->capture($this->session, $returnUrl, (string) $this->request->getReferer());
            $oauth = ObjectManager::getInstance(SocialLoginOAuthService::class);
            $started = $oauth->start($provider, $captured, [
                'intent' => $intent,
                'customer_id' => $current?->getId() ?? 0,
            ]);

            throw new RedirectException((string) $started['authorization_url'], 302);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $fallback = $intent === SocialLoginOAuthService::INTENT_BIND
                ? '/customer/account/index#social-login'
                : $this->getUrl('customer/account/login', $returnUrl !== '' ? ['redirect_url' => $returnUrl] : []);

            return $this->redirect($fallback);
        }
    }

    public function getCallback()
    {
        // One-time OAuth `code`/`state` must not be consumed by maintenance HEAD/GET probes.
        // Log evidence: callback?...&_maintenance_recovery_probe=… method=HEAD then same code GET failed.
        if ($this->isMaintenanceRecoveryProbe()) {
            return $this->respondOAuthProbeSkip();
        }

        $authReturn = ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
        $oauth = ObjectManager::getInstance(SocialLoginOAuthService::class);
        $linker = ObjectManager::getInstance(SocialLoginAccountLinker::class);
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);

        try {
            $profile = $oauth->complete([
                'state' => (string) ($this->request->getParam('state') ?? ''),
                'code' => (string) ($this->request->getParam('code') ?? ''),
                'error' => (string) ($this->request->getParam('error') ?? ''),
                'error_description' => (string) ($this->request->getParam('error_description') ?? ''),
            ]);

            $intent = (string) ($profile['intent'] ?? SocialLoginOAuthService::INTENT_LOGIN);
            if ($intent === SocialLoginOAuthService::INTENT_BIND) {
                $current = $accounts->current();
                $expectedId = (int) ($profile['customer_id'] ?? 0);
                if ($current === null || ($expectedId > 0 && $current->getId() !== $expectedId)) {
                    throw new \RuntimeException((string) __('绑定社媒账户需要登录同一账户'));
                }
                $linker->bindProfileToCustomer($profile, $current->getId());
                MessageManager::success((string) __('社媒账户已绑定'));

                return $this->redirect('/customer/account/index#social-login');
            }

            $bound = $linker->findBoundIdentity($profile);
            if ($bound !== null) {
                $accounts->login($bound);

                return $this->redirect(
                    $this->formatSocialLoginSuccessRedirect(
                        $authReturn,
                        (string) ($profile['return_url'] ?? '')
                    )
                );
            }

            $token = $oauth->storePending($profile);

            return $this->redirect(
                $this->getUrl('customer/account/social-login/choose', ['token' => $token])
            );
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            $fallback = $authReturn->resolve($this->session);

            return $this->redirect($this->getUrl('customer/account/login', $fallback !== '' ? [
                'redirect_url' => $fallback,
            ] : []));
        }
    }

    public function getChoose()
    {
        if ($this->session->isLoggedIn()) {
            return $this->redirect('/customer/account/index#social-login');
        }

        $token = trim((string) ($this->request->getParam('token') ?? ''));
        $oauth = ObjectManager::getInstance(SocialLoginOAuthService::class);
        $profile = $oauth->peekPending($token);
        if ($profile === null) {
            MessageManager::error((string) __('社媒登录会话已过期，请重试'));

            return $this->redirect('/customer/account/login');
        }

        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);
        $postedUsername = trim((string) ($this->request->getParam('username') ?? ''));
        $profileEmail = strtolower(trim((string) ($profile['email'] ?? '')));
        $suggestedEmail = $postedUsername;
        if ($suggestedEmail === '' && $profileEmail !== '' && !str_ends_with($profileEmail, '@social-login.local')) {
            // Only prefill Google/Meta email when a local account already uses it.
            if ($accounts->findByEmail($profileEmail) !== null) {
                $suggestedEmail = $profileEmail;
            }
        }

        $this->getTemplate()->assign('pending_token', $token);
        $this->getTemplate()->assign('pending_profile', $profile);
        $this->getTemplate()->assign('suggested_email', $suggestedEmail);
        $this->getTemplate()->assign(
            'bind_hint',
            $suggestedEmail === ''
                ? (string) __('请填写本站已有账户的用户名/邮箱与密码（不是 Google 登录密码）。若尚未注册，请使用下方「新建账户」。')
                : ''
        );
        $this->getTemplate()->assign('bind_error', trim((string) ($this->request->getParam('bind_error') ?? '')));

        return $this->fetch('Weline_Customer::templates/frontend/account/social-login-choose.phtml');
    }

    public function postBindExisting()
    {
        $authReturn = ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
        $oauth = ObjectManager::getInstance(SocialLoginOAuthService::class);
        $linker = ObjectManager::getInstance(SocialLoginAccountLinker::class);
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);

        $token = trim((string) ($this->request->getPost('token') ?? $this->request->getParam('token') ?? ''));
        $login = (string) ($this->request->getPost('username') ?? $this->request->getPost('email') ?? '');
        $password = (string) ($this->request->getPost('password') ?? '');

        try {
            $profile = $oauth->peekPending($token);
            if ($profile === null) {
                throw new \RuntimeException((string) __('社媒登录会话已过期，请重试'));
            }
            $identity = $linker->authenticateLocal($login, $password);
            $linker->bindProfileToCustomer($profile, $identity->getId());
            $oauth->consumePending($token);
            $accounts->login($identity);
            MessageManager::success((string) __('已绑定社媒账户并登录'));

            return $this->redirect(
                $this->formatSocialLoginSuccessRedirect(
                    $authReturn,
                    (string) ($profile['return_url'] ?? '')
                )
            );
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            if ($token !== '' && $oauth->peekPending($token) !== null) {
                return $this->redirect(
                    $this->getUrl('customer/account/social-login/choose', [
                        'token' => $token,
                        'username' => $login,
                        'bind_error' => $e->getMessage(),
                    ])
                );
            }

            return $this->redirect('/customer/account/login');
        }
    }

    public function postCreateNew()
    {
        $authReturn = ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
        $oauth = ObjectManager::getInstance(SocialLoginOAuthService::class);
        $linker = ObjectManager::getInstance(SocialLoginAccountLinker::class);
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);
        $token = trim((string) ($this->request->getPost('token') ?? $this->request->getParam('token') ?? ''));

        try {
            $profile = $oauth->peekPending($token);
            if ($profile === null) {
                throw new \RuntimeException((string) __('社媒登录会话已过期，请重试'));
            }
            $identity = $linker->createFromProfile($profile);
            $oauth->consumePending($token);
            $accounts->login($identity);
            MessageManager::success((string) __('已创建账户并完成社媒登录'));

            return $this->redirect(
                $this->formatSocialLoginSuccessRedirect(
                    $authReturn,
                    (string) ($profile['return_url'] ?? '')
                )
            );
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
            if ($token !== '' && $oauth->peekPending($token) !== null) {
                return $this->redirect(
                    $this->getUrl('customer/account/social-login/choose', [
                        'token' => $token,
                        'bind_error' => $e->getMessage(),
                    ])
                );
            }

            return $this->redirect('/customer/account/login');
        }
    }

    /**
     * Google One Tap / GIS credential callback (JSON).
     */
    public function postQuickGoogle()
    {
        $credential = (string) (
            $this->request->getPost('credential')
            ?? $this->request->getBodyParam('credential')
            ?? ''
        );

        return $this->respondQuickAuth(function (SocialLoginQuickAuthService $quick, string $returnUrl) use ($credential): array {
            return $quick->completeGoogleIdToken($credential, $returnUrl);
        });
    }

    /**
     * Facebook JS SDK access-token callback (JSON).
     */
    public function postQuickFacebook()
    {
        $token = (string) (
            $this->request->getPost('access_token')
            ?? $this->request->getBodyParam('access_token')
            ?? ''
        );

        return $this->respondQuickAuth(function (SocialLoginQuickAuthService $quick, string $returnUrl) use ($token): array {
            return $quick->completeFacebookAccessToken($token, $returnUrl);
        });
    }

    /**
     * @param callable(SocialLoginQuickAuthService,string):array{redirect:string,status:string} $runner
     */
    private function respondQuickAuth(callable $runner)
    {
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);
        $authReturn = ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
        $returnUrl = (string) ($this->request->getPost('return_url')
            ?? $this->request->getBodyParam('return_url')
            ?? $this->request->getParam('return_url')
            ?? $this->request->getParam('redirect_url')
            ?? '');
        $captured = $authReturn->capture(
            $this->session,
            $returnUrl,
            (string) $this->request->getReferer()
        );

        if ($accounts->current() !== null) {
            return $this->json([
                'ok' => true,
                'status' => 'already_logged_in',
                'redirect' => $this->formatSocialLoginSuccessRedirect($authReturn, $captured),
            ]);
        }

        try {
            $quick = ObjectManager::getInstance(SocialLoginQuickAuthService::class);
            $result = $runner($quick, $captured);

            return $this->json([
                'ok' => true,
                'status' => $result['status'],
                'redirect' => $result['redirect'],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    private function formatSocialLoginSuccessRedirect(
        CustomerAuthReturnUrlService $authReturn,
        string $explicitReturnUrl = ''
    ): string {
        $target = $authReturn->consume($this->session, $explicitReturnUrl);

        return $authReturn->formatAuthSuccessRedirect($target);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    }

    /**
     * True for Maintenance recovery probes that must not mutate OAuth state.
     */
    private function isMaintenanceRecoveryProbe(): bool
    {
        $method = strtoupper(trim((string) ($this->request->getMethod() ?? '')));
        if ($method === 'HEAD') {
            return true;
        }
        $probe = trim((string) ($this->request->getParam('_maintenance_recovery_probe') ?? ''));
        if ($probe !== '') {
            return true;
        }
        $header = trim((string) ($this->request->getServer('HTTP_X_MAINTENANCE_RECOVERY_CHECK') ?? ''));

        return $header !== '';
    }

    /**
     * No-op response for probes: avoid RedirectException / state consume.
     */
    private function respondOAuthProbeSkip(): string
    {
        http_response_code(204);
        header('Cache-Control: no-store');

        return '';
    }

    public function postUnbind()
    {
        $accounts = ObjectManager::getInstance(CustomerAccountFacadeInterface::class);
        $linker = ObjectManager::getInstance(SocialLoginAccountLinker::class);
        $current = $accounts->current();
        if ($current === null) {
            return $this->redirect('/customer/account/login');
        }

        $provider = strtolower(trim((string) ($this->request->getPost('provider') ?? '')));
        $returnUrl = trim((string) ($this->request->getPost('return_url') ?? ''));
        if ($returnUrl === '' || !str_starts_with($returnUrl, '/')) {
            $returnUrl = '/customer/account/index#social-login';
        }
        try {
            $linker->unbind($current->getId(), $provider);
            MessageManager::success((string) __('已解除社媒绑定'));
        } catch (\Throwable $e) {
            MessageManager::error($e->getMessage());
        }

        return $this->redirect($returnUrl);
    }
}
