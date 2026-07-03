<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class PanelAccessService
{
    private const DEFAULT_COOKIE_NAME = 'w_weline_panel';
    private const DEFAULT_SESSION_TTL = 3600;
    private const DEVELOPER_WORKSPACE_MODULE = 'Weline_DeveloperWorkspace';
    private const VISITOR_MODULE = 'Weline_Visitor';

    /** @var array{hashes: list<string>, tokens: list<string>}|null */
    private ?array $tokenMaterials = null;
    private ?SystemConfig $systemConfig = null;

    public function isDeveloperMode(): bool
    {
        return (\defined('DEV') && DEV)
            || (\defined('DEBUG') && DEBUG)
            || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE);
    }

    public function shouldInjectBootstrap(): bool
    {
        if ($this->isDeveloperMode()) {
            return true;
        }

        return $this->hasProductionPanelConfig();
    }

    public function requiresTokenForUi(): bool
    {
        return !$this->isDeveloperMode() && $this->hasProductionPanelConfig();
    }

    public function hasProductionPanelConfig(): bool
    {
        return $this->truthy(Env::get('dev_tool.panel.enable_in_prod', false))
            && $this->configuredTokenMaterial() !== '';
    }

    public function canAccessPanel(?Request $request = null): bool
    {
        if ($this->isDeveloperMode()) {
            return true;
        }

        if (!$this->hasProductionPanelConfig()) {
            return false;
        }

        return $this->hasValidSession() || ($request !== null && $this->hasValidBearerToken($request));
    }

    public function canAccessApi(?Request $request = null): bool
    {
        return $this->canAccessPanel($request);
    }

    public function canAccessRawHttp(string $rawRequest = ''): bool
    {
        if ($this->isDeveloperMode()) {
            return true;
        }

        if (!$this->hasProductionPanelConfig()) {
            return false;
        }

        $cookieHeader = $this->rawHeaderValue($rawRequest, 'Cookie');
        if ($this->hasValidSessionCookie($this->rawCookieValue($cookieHeader, $this->cookieName()))) {
            return true;
        }

        $authorization = $this->rawHeaderValue($rawRequest, 'Authorization');
        if (\preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
            return $this->verifyToken(\trim((string)$match[1]));
        }

        $token = $this->rawHeaderValue($rawRequest, 'X_WELINE_PANEL_TOKEN');
        if ($token === '') {
            $token = $this->rawHeaderValue($rawRequest, 'X-Weline-Panel-Token');
        }

        return $token !== '' && $this->verifyToken($token);
    }

    public function authenticate(Request $request): bool
    {
        if (!$this->hasProductionPanelConfig()) {
            return false;
        }

        return $this->verifyToken($this->extractSubmittedToken($request));
    }

    public function issueSession(Response $response): Response
    {
        $ttl = $this->sessionTtl();
        $issuedAt = \time();
        $expiresAt = $issuedAt + $ttl;
        $nonce = $this->randomNonce();
        $payload = $issuedAt . '.' . $expiresAt . '.' . $nonce;
        $signature = \hash_hmac('sha256', $payload, $this->sessionSigningKey());
        $cookieValue = $this->base64UrlEncode($payload . '.' . $signature);

        return $response
            ->setCookie($this->cookieName(), $cookieValue, $expiresAt, '/', '', $this->isSecureRequest(), true, 'Lax')
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');
    }

    public function cookieName(): string
    {
        $name = \trim((string)Env::get('dev_tool.panel.cookie_name', self::DEFAULT_COOKIE_NAME));

        return $name !== '' ? $name : self::DEFAULT_COOKIE_NAME;
    }

    public function sessionTtl(): int
    {
        $ttl = (int)Env::get('dev_tool.panel.session_ttl', self::DEFAULT_SESSION_TTL);

        return $ttl > 0 ? $ttl : self::DEFAULT_SESSION_TTL;
    }

    public function noStore(Response $response): Response
    {
        return $response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');
    }

    private function extractSubmittedToken(Request $request): string
    {
        $token = $this->scalarString($request->getPost('token', ''));
        if ($token !== '') {
            return $token;
        }

        $body = $request->getBodyParams(true);
        if (\is_array($body)) {
            $token = $this->scalarString($body['token'] ?? '');
            if ($token !== '') {
                return $token;
            }
        }

        $rawBody = $request->getBodyParams(false);
        if (\is_string($rawBody) && \trim($rawBody) !== '') {
            $decoded = \json_decode($rawBody, true);
            if (\is_array($decoded)) {
                return $this->scalarString($decoded['token'] ?? '');
            }
        }

        return '';
    }

    private function hasValidBearerToken(Request $request): bool
    {
        $authorization = $this->headerValue($request, 'Authorization');
        if (\preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
            return $this->verifyToken(\trim((string)$match[1]));
        }

        $token = $this->headerValue($request, 'X_WELINE_PANEL_TOKEN');
        if ($token === '') {
            $token = $this->headerValue($request, 'X-Weline-Panel-Token');
        }

        return $token !== '' && $this->verifyToken($token);
    }

    private function headerValue(Request $request, string $name): string
    {
        $value = $request->getHeader($name);
        if (\is_array($value)) {
            $value = (string)($value[0] ?? '');
        }

        return \trim((string)$value);
    }

    private function hasValidSession(): bool
    {
        return $this->hasValidSessionCookie((string)Cookie::get($this->cookieName(), ''));
    }

    public function hasValidSessionCookie(string $cookieValue): bool
    {
        if ($cookieValue === '') {
            return false;
        }

        $decoded = $this->base64UrlDecode($cookieValue);
        if ($decoded === '') {
            return false;
        }

        $parts = \explode('.', $decoded);
        if (\count($parts) !== 4) {
            return false;
        }

        [$issuedAt, $expiresAt, $nonce, $signature] = $parts;
        if (!\ctype_digit($issuedAt) || !\ctype_digit($expiresAt) || $nonce === '' || $signature === '') {
            return false;
        }

        if ((int)$expiresAt < \time()) {
            return false;
        }

        $payload = $issuedAt . '.' . $expiresAt . '.' . $nonce;
        $expected = \hash_hmac('sha256', $payload, $this->sessionSigningKey());

        return \hash_equals($expected, $signature);
    }

    private function verifyToken(string $token): bool
    {
        $token = \trim($token);
        if ($token === '') {
            return false;
        }

        $materials = $this->tokenMaterials();
        foreach ($materials['hashes'] as $hash) {
            if ($this->verifyTokenHash($token, $hash)) {
                return true;
            }
        }

        foreach ($materials['tokens'] as $plain) {
            if (\hash_equals($plain, $token)) {
                return true;
            }
        }

        return false;
    }

    private function verifyTokenHash(string $token, string $hash): bool
    {
        $hash = \trim($hash);
        if ($hash === '') {
            return false;
        }

        $passwordInfo = \password_get_info($hash);
        $algo = $passwordInfo['algo'] ?? 0;
        if ($algo !== 0 && $algo !== null) {
            return \password_verify($token, $hash);
        }

        $sha256 = \hash('sha256', $token);

        return \hash_equals($hash, $sha256) || \hash_equals($hash, $token);
    }

    private function configuredTokenMaterial(): string
    {
        $materials = $this->tokenMaterials();

        return \implode('|', \array_merge($materials['hashes'], $materials['tokens']));
    }

    private function sessionSigningKey(): string
    {
        $material = $this->configuredTokenMaterial();
        $salt = \defined('BP') ? (string)BP : __DIR__;

        return \hash('sha256', $material . '|' . $salt);
    }

    /**
     * @return array{hashes: list<string>, tokens: list<string>}
     */
    private function tokenMaterials(): array
    {
        if ($this->tokenMaterials !== null) {
            return $this->tokenMaterials;
        }

        $hashes = [];
        $tokens = [];

        $this->appendMaterial($hashes, Env::get('dev_tool.panel.token_hash', ''));
        $this->appendMaterial($tokens, Env::get('dev_tool.panel.token', ''));

        foreach ($this->configuredTokenHashKeys() as $module => $keys) {
            foreach ($keys as $key) {
                $this->appendMaterial($hashes, $this->configValue($module, $key));
            }
        }

        foreach ($this->configuredPlainTokenKeys() as $module => $keys) {
            foreach ($keys as $key) {
                $this->appendMaterial($tokens, $this->configValue($module, $key));
            }
        }

        $this->tokenMaterials = [
            'hashes' => \array_values(\array_unique($hashes)),
            'tokens' => \array_values(\array_unique($tokens)),
        ];

        return $this->tokenMaterials;
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredTokenHashKeys(): array
    {
        return [
            self::DEVELOPER_WORKSPACE_MODULE => [
                'dev_tool.panel.token_hash',
            ],
            self::VISITOR_MODULE => [
                'visitor/panel/access_token_hash',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function configuredPlainTokenKeys(): array
    {
        return [
            self::DEVELOPER_WORKSPACE_MODULE => [
                'dev_tool.panel.token',
            ],
            self::VISITOR_MODULE => [
                'visitor/panel/access_token',
            ],
        ];
    }

    /**
     * @param list<string> $materials
     */
    private function appendMaterial(array &$materials, mixed $value): void
    {
        $value = $this->scalarString($value);
        if ($value !== '') {
            $materials[] = $value;
        }
    }

    private function configValue(string $module, string $key): string
    {
        try {
            if (!\class_exists(SystemConfig::class)) {
                return '';
            }

            return $this->scalarString(
                $this->systemConfig()->getConfig($key, $module, SystemConfig::area_BACKEND, '')
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function systemConfig(): SystemConfig
    {
        if (!$this->systemConfig) {
            $this->systemConfig = ObjectManager::getInstance(SystemConfig::class);
        }

        return $this->systemConfig;
    }

    private function scalarString(mixed $value): string
    {
        return \is_scalar($value) ? \trim((string)$value) : '';
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (bool)$value;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $value = \strtr($value, '-_', '+/');
        $padding = \strlen($value) % 4;
        if ($padding > 0) {
            $value .= \str_repeat('=', 4 - $padding);
        }

        $decoded = \base64_decode($value, true);

        return \is_string($decoded) ? $decoded : '';
    }

    private function rawHeaderValue(string $rawRequest, string $name): string
    {
        if ($rawRequest === '') {
            return '';
        }

        foreach (\preg_split('/\r\n|\n|\r/', $rawRequest) ?: [] as $line) {
            if (!\is_string($line) || !\str_contains($line, ':')) {
                continue;
            }
            [$headerName, $value] = \explode(':', $line, 2);
            if (\strcasecmp(\trim($headerName), $name) === 0) {
                return \trim($value);
            }
        }

        return '';
    }

    private function rawCookieValue(string $cookieHeader, string $name): string
    {
        if ($cookieHeader === '' || $name === '') {
            return '';
        }

        foreach (\explode(';', $cookieHeader) as $cookie) {
            if (!\str_contains($cookie, '=')) {
                continue;
            }
            [$cookieName, $value] = \explode('=', $cookie, 2);
            if (\trim($cookieName) === $name) {
                return \trim($value);
            }
        }

        return '';
    }

    private function randomNonce(): string
    {
        try {
            return \bin2hex(\random_bytes(12));
        } catch (\Throwable) {
            return \str_replace('.', '', \uniqid('panel', true));
        }
    }

    private function isSecureRequest(): bool
    {
        $https = \strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }

        $forwardedProto = \strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        return $forwardedProto === 'https';
    }
}
