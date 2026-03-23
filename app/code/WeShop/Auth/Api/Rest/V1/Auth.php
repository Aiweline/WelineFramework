<?php

declare(strict_types=1);

namespace WeShop\Auth\Api\Rest\V1;

use WeShop\Auth\Service\AuthGrantService;
use Weline\Framework\App\Controller\FrontendRestController;

class Auth extends FrontendRestController
{
    public function __construct(
        private readonly AuthGrantService $authGrantService
    ) {
        parent::__construct();
    }

    public function postToken(): string
    {
        try {
            $grantType = strtolower((string) ($this->request->getBodyParam('grant_type') ?? $this->request->getPost('grant_type') ?? 'password'));
            $area = strtolower((string) ($this->request->getBodyParam('area') ?? $this->request->getPost('area') ?? 'frontend'));

            $data = match ($grantType) {
                'password' => $this->authGrantService->issuePasswordToken(
                    $area,
                    (string) ($this->request->getBodyParam('username') ?? $this->request->getBodyParam('email') ?? $this->request->getPost('username') ?? $this->request->getPost('email') ?? ''),
                    (string) ($this->request->getBodyParam('password') ?? $this->request->getPost('password') ?? '')
                ),
                'google_code' => $this->authGrantService->issueGoogleCodeToken(
                    $area,
                    (string) ($this->request->getBodyParam('code') ?? $this->request->getPost('code') ?? '')
                ),
                'refresh_token' => $this->authGrantService->refreshToken(
                    (string) ($this->request->getBodyParam('refresh_token') ?? $this->request->getPost('refresh_token') ?? '')
                ),
                'api_credentials' => $this->authGrantService->issueApiCredentialsToken(
                    (string) ($this->request->getBodyParam('api_key') ?? $this->request->getPost('api_key') ?? ''),
                    (string) ($this->request->getBodyParam('api_secret') ?? $this->request->getPost('api_secret') ?? '')
                ),
                default => throw new \InvalidArgumentException((string) __('Unsupported grant type.')),
            };

            return (string) $this->success(__('Authentication succeeded.'), $data);
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Authentication failed.'));
        }
    }

    public function postLogin(): string
    {
        try {
            return (string) $this->success(
                __('Authentication succeeded.'),
                $this->authGrantService->issuePasswordToken(
                    $this->readArea(),
                    $this->readUsername(),
                    $this->readPassword()
                )
            );
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Authentication failed.'));
        }
    }

    public function postRefresh(): string
    {
        try {
            return (string) $this->success(
                __('Authentication succeeded.'),
                $this->authGrantService->refreshToken($this->readRefreshToken())
            );
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Authentication failed.'));
        }
    }

    public function postExchange(): string
    {
        try {
            $challengeToken = (string) ($this->request->getBodyParam('challenge_token') ?? $this->request->getPost('challenge_token') ?? '');
            $code = (string) ($this->request->getBodyParam('code') ?? $this->request->getPost('code') ?? '');

            if ($challengeToken === '' || $code === '') {
                throw new \InvalidArgumentException((string) __('Challenge token and code are required.'));
            }

            return (string) $this->success(
                __('Challenge verification succeeded.'),
                $this->authGrantService->verifyChallenge($challengeToken, $code)
            );
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Challenge verification failed.'));
        }
    }

    public function postLogout(): string
    {
        try {
            $token = $this->getTokenFromRequest();
            if ($token === '') {
                throw new \InvalidArgumentException((string) __('Access token is required.'));
            }

            $this->authGrantService->logout($token);

            return (string) $this->success(__('Logout succeeded.'), [
                'status' => 'logged_out',
            ]);
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Logout failed.'));
        }
    }

    public function getMe(): string
    {
        try {
            $token = $this->getTokenFromRequest();
            if ($token === '') {
                throw new \InvalidArgumentException((string) __('Access token is required.'));
            }

            return (string) $this->success(__('Current actor resolved.'), $this->authGrantService->resolveMe($token));
        } catch (\Throwable $throwable) {
            return (string) $this->exception($throwable, __('Unable to resolve current actor.'));
        }
    }

    public function getTokenInfo(): string
    {
        return $this->getMe();
    }

    private function getTokenFromRequest(): string
    {
        return (string) (
            $this->request->getAuth('bearer')
            ?: $this->request->getHeader('X-API-Token')
            ?: $this->request->getParam('token')
            ?: $this->request->getBodyParam('token')
            ?: $this->request->getPost('token')
            ?: ''
        );
    }

    private function readArea(): string
    {
        return strtolower((string) ($this->request->getBodyParam('area') ?? $this->request->getPost('area') ?? 'frontend'));
    }

    private function readUsername(): string
    {
        return (string) (
            $this->request->getBodyParam('username')
            ?? $this->request->getBodyParam('email')
            ?? $this->request->getPost('username')
            ?? $this->request->getPost('email')
            ?? ''
        );
    }

    private function readPassword(): string
    {
        return (string) ($this->request->getBodyParam('password') ?? $this->request->getPost('password') ?? '');
    }

    private function readRefreshToken(): string
    {
        return (string) ($this->request->getBodyParam('refresh_token') ?? $this->request->getPost('refresh_token') ?? '');
    }
}
