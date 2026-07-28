<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use Weline\SystemConfig\Api\ConfigStore;

final class GoogleOAuthService
{
    private const SESSION_KEY = 'weline_captcha_google_oauth';
    private const STATE_TTL = 900;
    private const CLOUD_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly ConfigStore $store,
        private readonly Url $url,
    ) {
    }

    /** @return array{authorization_url:string,state:string} */
    public function start(?string $scope = null): array
    {
        $clientId = $this->config->googleClientId();
        if ($clientId === '') {
            throw new \RuntimeException((string)__('请先在统一配置中心填写 Google OAuth Client ID'));
        }

        $state = \bin2hex(\random_bytes(24));
        $verifier = \rtrim(\strtr(\base64_encode(\random_bytes(48)), '+/', '-_'), '=');
        $challenge = \rtrim(\strtr(\base64_encode(\hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirectUri = $this->callbackUrl();
        $bucket = $this->session()->getData(self::SESSION_KEY);
        if (!\is_array($bucket)) {
            $bucket = [];
        }
        $bucket[$state] = [
            'verifier' => $verifier,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'created_at' => \time(),
        ];
        $this->session()->setData(self::SESSION_KEY, $bucket);

        return [
            'state' => $state,
            'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . \http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => self::CLOUD_SCOPE,
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent',
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ]),
        ];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    public function complete(array $params): array
    {
        $state = \trim((string)($params['state'] ?? ''));
        $payload = $this->consumeState($state);
        if ($payload === null) {
            throw new \RuntimeException((string)__('Google 授权状态无效或已过期，请重新发起授权'));
        }
        $code = \trim((string)($params['code'] ?? ''));
        if ($code === '') {
            throw new \RuntimeException((string)__('Google 授权回调缺少授权码'));
        }

        $token = $this->postForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->config->googleClientId(),
            'client_secret' => $this->config->googleClientSecret(),
            'redirect_uri' => (string)$payload['redirect_uri'],
            'grant_type' => 'authorization_code',
            'code_verifier' => (string)$payload['verifier'],
        ]);
        $accessToken = \trim((string)($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException((string)__('Google OAuth 未返回 Access Token'));
        }

        $scope = \is_string($payload['scope'] ?? null) ? $payload['scope'] : null;
        $refreshToken = \trim((string)($token['refresh_token'] ?? ''));
        $this->store->setScopedConfig(
            'captcha/google/access_token',
            $accessToken,
            CaptchaConfig::MODULE,
            CaptchaConfig::AREA,
            null,
            null,
            ['is_sensitive' => true, 'reason' => 'google_oauth_callback']
        );
        if ($refreshToken !== '') {
            $this->store->setScopedConfig(
                'captcha/google/refresh_token',
                $refreshToken,
                CaptchaConfig::MODULE,
                CaptchaConfig::AREA,
                null,
                null,
                ['is_sensitive' => true, 'reason' => 'google_oauth_callback']
            );
        }

        $createdKey = '';
        if ($this->config->googleProjectId() !== '' && $this->config->googleSiteKey() === '') {
            $createdKey = $this->createEnterpriseKey($accessToken);
            if ($createdKey !== '') {
                $this->store->setScopedConfig(
                    'captcha/google/site_key',
                    $createdKey,
                    CaptchaConfig::MODULE,
                    CaptchaConfig::AREA,
                    $scope,
                    null,
                    ['reason' => 'google_recaptcha_key_create']
                );
            }
        }

        return [
            'success' => true,
            'site_key' => $createdKey,
            'scope' => $scope,
            'needs_project' => $this->config->googleProjectId() === '',
            'message' => $createdKey !== ''
                ? (string)__('Google 授权成功，并已为当前网站创建 reCAPTCHA Enterprise Key')
                : (string)__('Google 授权成功'),
        ];
    }

    /** @return list<array{project_id:string,name:string,display_name:string}> */
    public function listProjects(): array
    {
        $accessToken = $this->validAccessToken();
        if ($accessToken === '') {
            throw new \RuntimeException((string)__('Google 授权已过期，请重新授权'));
        }
        $response = $this->getJson(
            'https://cloudresourcemanager.googleapis.com/v1/projects?filter='
            . \rawurlencode('lifecycleState:ACTIVE')
            . '&pageSize=200',
            ['Authorization: Bearer ' . $accessToken],
        );
        $projects = [];
        foreach ((array)($response['projects'] ?? []) as $project) {
            if (!\is_array($project)) {
                continue;
            }
            $projectId = \trim((string)($project['projectId'] ?? ''));
            if ($projectId === '') {
                continue;
            }
            $projects[] = [
                'project_id' => $projectId,
                'name' => (string)($project['name'] ?? ''),
                'display_name' => (string)($project['name'] ?? $projectId),
            ];
        }
        return $projects;
    }

    /** @return array<string,mixed> */
    public function bindProject(string $projectId, ?string $scope = null): array
    {
        $projectId = \trim($projectId);
        if (\preg_match('/\A[a-z][a-z0-9-]{4,61}[a-z0-9]\z/D', $projectId) !== 1) {
            throw new \InvalidArgumentException((string)__('Google Project ID 无效'));
        }
        $allowed = false;
        foreach ($this->listProjects() as $project) {
            if ($project['project_id'] === $projectId) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \RuntimeException((string)__('当前授权账户无权访问所选 Google Project'));
        }

        $this->store->setScopedConfig(
            'captcha/google/project_id',
            $projectId,
            CaptchaConfig::MODULE,
            CaptchaConfig::AREA,
            $scope,
            null,
            ['reason' => 'google_project_bind'],
        );
        $siteKey = $this->createEnterpriseKey($this->validAccessToken(), $projectId);
        if ($siteKey === '') {
            throw new \RuntimeException((string)__('Google Project 已绑定，但 Enterprise Key 创建失败'));
        }
        $this->store->setScopedConfig(
            'captcha/google/site_key',
            $siteKey,
            CaptchaConfig::MODULE,
            CaptchaConfig::AREA,
            $scope,
            null,
            ['reason' => 'google_recaptcha_key_create'],
        );
        $this->store->setScopedConfig(
            'captcha/google/enabled',
            true,
            CaptchaConfig::MODULE,
            CaptchaConfig::AREA,
            $scope,
            null,
            ['reason' => 'google_project_bind'],
        );

        return [
            'success' => true,
            'project_id' => $projectId,
            'site_key' => $siteKey,
            'message' => (string)__('Google Project 与当前网站域名已绑定'),
        ];
    }

    /** @return array<string,mixed> */
    public function testConnection(): array
    {
        $projects = $this->listProjects();
        return [
            'success' => true,
            'project_count' => \count($projects),
            'message' => (string)__('Google 连接测试成功，可访问 %{1} 个 Project', [\count($projects)]),
        ];
    }

    public function refreshAccessToken(): string
    {
        $refreshToken = $this->config->googleRefreshToken();
        if ($refreshToken === '' || $this->config->googleClientId() === '') {
            return '';
        }
        $token = $this->postForm('https://oauth2.googleapis.com/token', [
            'client_id' => $this->config->googleClientId(),
            'client_secret' => $this->config->googleClientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $accessToken = \trim((string)($token['access_token'] ?? ''));
        if ($accessToken !== '') {
            $this->store->setScopedConfig(
                'captcha/google/access_token',
                $accessToken,
                CaptchaConfig::MODULE,
                CaptchaConfig::AREA,
                null,
                null,
                ['is_sensitive' => true, 'reason' => 'google_oauth_refresh']
            );
        }
        return $accessToken;
    }

    public function revoke(): void
    {
        $token = $this->config->googleRefreshToken() ?: $this->config->googleAccessToken();
        if ($token !== '') {
            $this->postForm('https://oauth2.googleapis.com/revoke', ['token' => $token], false);
        }
        foreach (['captcha/google/access_token', 'captcha/google/refresh_token'] as $key) {
            $this->store->deleteScopedConfig(
                $key,
                CaptchaConfig::MODULE,
                CaptchaConfig::AREA,
                null,
                null,
                ['reason' => 'google_oauth_revoke']
            );
        }
    }

    public function callbackUrl(): string
    {
        return $this->url->getBackendUrl('captcha/backend/google/callback');
    }

    private function createEnterpriseKey(
        string $accessToken,
        ?string $explicitProjectId = null,
    ): string
    {
        $projectId = $explicitProjectId ?? $this->config->googleProjectId();
        $domains = $this->configuredDomains();
        if ($projectId === '' || $domains === []) {
            return '';
        }
        $response = $this->postJson(
            'https://recaptchaenterprise.googleapis.com/v1/projects/' . \rawurlencode($projectId) . '/keys',
            [
                'displayName' => 'Weline ' . ($domains[0] ?? 'website'),
                'webSettings' => [
                    'allowedDomains' => \array_values(\array_filter($domains, static fn(string $domain): bool => !\str_starts_with($domain, '*.'))),
                    'integrationType' => 'SCORE',
                    'allowAllDomains' => false,
                ],
            ],
            ['Authorization: Bearer ' . $accessToken]
        );
        $name = \trim((string)($response['name'] ?? ''));
        if ($name === '') {
            return '';
        }
        $parts = \explode('/', $name);
        return (string)\end($parts);
    }

    private function validAccessToken(): string
    {
        $accessToken = $this->config->googleAccessToken();
        if ($accessToken !== '') {
            return $accessToken;
        }
        return $this->refreshAccessToken();
    }

    /** @return list<string> */
    private function configuredDomains(): array
    {
        $data = new DataObject(['domains' => $this->config->allowedDomains()]);
        ObjectManager::getInstance(EventsManager::class)->dispatch('Weline_Captcha::domains::collect', $data);
        $domains = \is_array($data->getData('domains')) ? $data->getData('domains') : [];
        return \array_values(\array_unique(\array_filter(
            \array_map(
                static fn(mixed $domain): string => \strtolower(\trim((string)$domain)),
                $domains,
            ),
            static fn(string $domain): bool => $domain !== '' && !\str_starts_with($domain, '*.'),
        )));
    }

    /** @return array<string, mixed>|null */
    private function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }
        $bucket = $this->session()->getData(self::SESSION_KEY);
        if (!\is_array($bucket) || !\is_array($bucket[$state] ?? null)) {
            return null;
        }
        $payload = $bucket[$state];
        unset($bucket[$state]);
        $this->session()->setData(self::SESSION_KEY, $bucket);
        return \time() - (int)($payload['created_at'] ?? 0) <= self::STATE_TTL ? $payload : null;
    }

    private function session(): Session
    {
        return ObjectManager::getInstance(Session::class);
    }

    /** @return array<string, mixed> */
    private function postForm(string $url, array $body, bool $throw = true): array
    {
        return $this->request($url, \http_build_query($body), ['Content-Type: application/x-www-form-urlencoded'], $throw);
    }

    /** @return array<string, mixed> */
    private function postJson(string $url, array $body, array $headers = []): array
    {
        $headers[] = 'Content-Type: application/json';
        return $this->request($url, (string)\json_encode($body, JSON_UNESCAPED_SLASHES), $headers, true);
    }

    /** @return array<string,mixed> */
    private function getJson(string $url, array $headers = [], bool $allowRefresh = true): array
    {
        $headers[] = 'Accept: application/json';
        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException((string)__('无法初始化 Google API 请求'));
        }
        \curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);
        if ($status === 401 && $allowRefresh && $this->config->googleRefreshToken() !== '') {
            $accessToken = $this->refreshAccessToken();
            if ($accessToken !== '') {
                return $this->getJson(
                    $url,
                    ['Authorization: Bearer ' . $accessToken],
                    false,
                );
            }
        }
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !\is_array($decoded)) {
            throw new \RuntimeException((string)__('Google API 请求失败：HTTP %{1} %{2}', [$status, $error]));
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function request(string $url, string $body, array $headers, bool $throw): array
    {
        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException((string)__('无法初始化 Google OAuth 请求'));
        }
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = \curl_exec($ch);
        $status = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        if (($status < 200 || $status >= 300 || !\is_array($decoded)) && $throw) {
            throw new \RuntimeException((string)__('Google OAuth 请求失败：HTTP %{1} %{2}', [$status, $error]));
        }
        return \is_array($decoded) ? $decoded : [];
    }
}
