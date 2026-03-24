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
            $username = $this->readUsername();
            $password = $this->readPassword();
            $googleCode = $this->readGoogleCode();
            $refreshToken = $this->readRefreshToken();
            $apiKey = $this->readApiKey();
            $apiSecret = $this->readApiSecret();

            $data = match ($grantType) {
                'password' => $this->authGrantService->issuePasswordToken(
                    $area,
                    $this->requireValue($username, __('Username or email and password are required.')),
                    $this->requireValue($password, __('Username or email and password are required.'))
                ),
                'google_code' => $this->authGrantService->issueGoogleCodeToken(
                    $area,
                    $this->requireValue($googleCode, __('Google authorization code is required.'))
                ),
                'refresh_token' => $this->authGrantService->refreshToken(
                    $this->requireValue($refreshToken, __('Refresh token is required.'))
                ),
                'api_credentials' => $this->authGrantService->issueApiCredentialsToken(
                    $this->requireValue($apiKey, __('API key and secret are required.')),
                    $this->requireValue($apiSecret, __('API key and secret are required.'))
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

    private function readGoogleCode(): string
    {
        return (string) ($this->request->getBodyParam('code') ?? $this->request->getPost('code') ?? '');
    }

    private function readRefreshToken(): string
    {
        return (string) ($this->request->getBodyParam('refresh_token') ?? $this->request->getPost('refresh_token') ?? '');
    }

    private function readApiKey(): string
    {
        return (string) ($this->request->getBodyParam('api_key') ?? $this->request->getPost('api_key') ?? '');
    }

    private function readApiSecret(): string
    {
        return (string) ($this->request->getBodyParam('api_secret') ?? $this->request->getPost('api_secret') ?? '');
    }

    private function requireValue(string $value, \Stringable|string $message): string
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException((string) $message);
        }

        return $value;
    }
}
