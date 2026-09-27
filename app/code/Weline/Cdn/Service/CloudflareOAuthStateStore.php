<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

/**
 * One-time, session-bound OAuth state + PKCE verifier storage.
 * Raw state is never persisted; verifier is session-only and consumed with state.
 */
final class CloudflareOAuthStateStore
{
    private const SESSION_KEY = '__weline_cdn_cloudflare_oauth_states';
    private const TTL_SECONDS = 600;
    private const MAX_STATES = 5;
    private const VERIFIER_BYTES = 32;

    public function __construct(private readonly ?Session $injectedSession = null)
    {
    }

    /**
     * @return array{state: string, code_challenge: string, code_challenge_method: string}
     */
    public function issue(string $callbackUrl, string $returnRoute): array
    {
        $this->assertCallbackUrl($callbackUrl);
        $this->prune();

        $state = bin2hex(random_bytes(32));
        $verifier = $this->createCodeVerifier();
        $challenge = $this->createCodeChallenge($verifier);
        $key = hash('sha256', $state);
        $states = $this->states();
        $states[$key] = [
            'callback_hash' => hash('sha256', $callbackUrl),
            'return_route' => $returnRoute,
            'code_verifier' => $verifier,
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        while (count($states) > self::MAX_STATES) {
            array_shift($states);
        }
        $this->session()->setData(self::SESSION_KEY, $states);

        return [
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
    }

    /**
     * Consume before token exchange so a code cannot be replayed.
     *
     * @return array{return_route: string, code_verifier: string}
     */
    public function consume(string $state, string $callbackUrl): array
    {
        $this->assertCallbackUrl($callbackUrl);
        $this->prune();

        if ($state === '') {
            throw new \DomainException((string)__('Cloudflare OAuth state 无效或已过期，请重新连接。'));
        }

        $key = hash('sha256', $state);
        $states = $this->states();
        $entry = $states[$key] ?? null;
        unset($states[$key]);
        $this->session()->setData(self::SESSION_KEY, $states);

        if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < time()) {
            throw new \DomainException((string)__('Cloudflare OAuth state 无效或已过期，请重新连接。'));
        }
        $expectedCallback = (string)($entry['callback_hash'] ?? '');
        if ($expectedCallback === '' || !hash_equals($expectedCallback, hash('sha256', $callbackUrl))) {
            throw new \DomainException((string)__('Cloudflare OAuth 回调地址不匹配。'));
        }

        $verifier = trim((string)($entry['code_verifier'] ?? ''));
        if ($verifier === '' || strlen($verifier) < 43) {
            throw new \DomainException((string)__('Cloudflare OAuth PKCE 校验失败，请重新连接。'));
        }

        return [
            'return_route' => (string)($entry['return_route'] ?? 'cdn/backend/account'),
            'code_verifier' => $verifier,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function states(): array
    {
        $states = $this->session()->getData(self::SESSION_KEY);

        return is_array($states) ? $states : [];
    }

    private function prune(): void
    {
        $now = time();
        $states = $this->states();
        foreach ($states as $key => $entry) {
            if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < $now) {
                unset($states[$key]);
            }
        }
        $this->session()->setData(self::SESSION_KEY, $states);
    }

    private function session(): Session
    {
        return $this->injectedSession ?? ObjectManager::getInstance(Session::class);
    }

    private function assertCallbackUrl(string $callbackUrl): void
    {
        $parts = parse_url($callbackUrl);
        if (
            !is_array($parts)
            || !in_array((string)($parts['scheme'] ?? ''), ['https', 'http'], true)
            || trim((string)($parts['host'] ?? '')) === ''
        ) {
            throw new \InvalidArgumentException('Invalid Cloudflare OAuth callback URL.');
        }
    }

    /**
     * RFC 7636 code_verifier: 43–128 chars from unreserved set.
     * Cloudflare Access rejects challenges that start with -/_ when corrupted in URLs;
     * regenerate until the S256 challenge starts with [A-Za-z0-9].
     */
    private function createCodeVerifier(): string
    {
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $verifier = rtrim(strtr(base64_encode(random_bytes(self::VERIFIER_BYTES)), '+/', '-_'), '=');
            if (strlen($verifier) < 43) {
                continue;
            }
            $challenge = $this->createCodeChallenge($verifier);
            if ($challenge !== '' && preg_match('/^[A-Za-z0-9]/', $challenge) === 1) {
                return $verifier;
            }
        }

        throw new \RuntimeException('Unable to generate Cloudflare OAuth PKCE verifier.');
    }

    private function createCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
