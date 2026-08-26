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
    public function testStateIsHashedOneTimeAndCallbackBound(): void
    {
        $session = $this->session();
        $store = new CloudflareOAuthStateStore($session);
        $state = $store->issue(
            'https://admin.example.com/backend/cdn/backend/oauth/callback',
            'weline_mail/backend',
        );

        self::assertStringNotContainsString(
            $state,
            json_encode($session->values, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ['return_route' => 'weline_mail/backend'],
            $store->consume(
                $state,
                'https://admin.example.com/backend/cdn/backend/oauth/callback',
            ),
        );

        $this->expectException(\DomainException::class);
        $store->consume(
            $state,
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
