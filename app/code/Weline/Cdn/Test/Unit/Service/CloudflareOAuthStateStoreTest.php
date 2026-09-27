<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CloudflareOAuthStateStore;
use Weline\Framework\Session\Session;

if (!defined('BP')) {
    require dirname(__DIR__, 7) . '/app/bootstrap.php';
}

final class CloudflareOAuthStateStoreTest extends TestCase
{
    public function testStateIsHashedOneTimeAndCallbackBoundWithPkce(): void
    {
        $session = $this->session();
        $store = new CloudflareOAuthStateStore($session);
        $issued = $store->issue(
            'https://admin.example.com/backend/cdn/backend/oauth/callback',
            'weline_mail/backend',
        );

        self::assertArrayHasKey('state', $issued);
        self::assertArrayHasKey('code_challenge', $issued);
        self::assertSame('S256', $issued['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]/', $issued['code_challenge']);
        self::assertStringNotContainsString(
            $issued['state'],
            json_encode($session->values, JSON_THROW_ON_ERROR),
        );

        $context = $store->consume(
            $issued['state'],
            'https://admin.example.com/backend/cdn/backend/oauth/callback',
        );
        self::assertSame('weline_mail/backend', $context['return_route']);
        self::assertGreaterThanOrEqual(43, strlen($context['code_verifier']));
        $expectedChallenge = rtrim(
            strtr(base64_encode(hash('sha256', $context['code_verifier'], true)), '+/', '-_'),
            '=',
        );
        self::assertSame($expectedChallenge, $issued['code_challenge']);

        $this->expectException(\DomainException::class);
        $store->consume(
            $issued['state'],
            'https://admin.example.com/backend/cdn/backend/oauth/callback',
        );
    }

    public function testInvalidStateIsRejected(): void
    {
        $store = new CloudflareOAuthStateStore($this->session());

        $this->expectException(\DomainException::class);
        $store->consume(
            'unknown',
            'https://admin.example.com/backend/cdn/backend/oauth/callback',
        );
    }

    private function session(): Session
    {
        return new class extends Session {
            /** @var array<string, mixed> */
            public array $values = [];

            public function __construct()
            {
            }

            public function getData(string $name = ''): mixed
            {
                return $name === '' ? $this->values : ($this->values[$name] ?? null);
            }

            public function setData(string $name, mixed $value): static
            {
                $this->values[$name] = $value;
                return $this;
            }
        };
    }
}
