<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

/**
 * One-time, session-bound OAuth state storage. Raw state is never persisted.
 */
final class CloudflareOAuthStateStore
{
    private const SESSION_KEY = '__weline_cdn_cloudflare_oauth_states';
    private const TTL_SECONDS = 600;
    private const MAX_STATES = 5;

    public function __construct(private readonly ?Session $injectedSession = null)
    {
    }

    /**
     * @return string Raw state sent only to the browser and Cloudflare
     */
    public function issue(string $callbackUrl, string $returnRoute): string
    {
        $this->assertCallbackUrl($callbackUrl);
        $this->prune();

        $state = bin2hex(random_bytes(32));
        $key = hash('sha256', $state);
        $states = $this->states();
        $states[$key] = [
            'callback_hash' => hash('sha256', $callbackUrl),
            'return_route' => $returnRoute,
            'expires_at' => time() + self::TTL_SECONDS,
        ];
        while (count($states) > self::MAX_STATES) {
            array_shift($states);
        }
        $this->session()->setData(self::SESSION_KEY, $states);

        return $state;
    }

    /**
     * Consume before token exchange so a code cannot be replayed.
     *
     * @return array{return_route: string}
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

        return ['return_route' => (string)($entry['return_route'] ?? 'cdn/backend/account')];
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
}
