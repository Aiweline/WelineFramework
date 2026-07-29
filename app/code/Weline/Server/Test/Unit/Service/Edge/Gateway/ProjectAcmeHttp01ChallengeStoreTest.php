<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;

final class ProjectAcmeHttp01ChallengeStoreTest extends TestCase
{
    private string $directory;
    private int $now = 1_000;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'wls-acme-store-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testLegacyProjectionMigratesThroughAuthorizedRouteDomain(): void
    {
        $token = 'TOKEN_legacy';
        self::assertNotFalse(\file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'legacy_example_test.json',
            \json_encode([
                'token' => $token,
                'keyAuth' => $token . '.' . \str_repeat('L', 43),
                'generation' => 7,
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertTrue(\touch(
            $this->directory . DIRECTORY_SEPARATOR . 'legacy_example_test.json',
            $this->now,
        ));

        $desired = $this->store()->desired(['legacy.example.test']);
        self::assertSame(7, $desired['generation']);
        self::assertSame(['legacy.example.test'], \array_column($desired['challenges'], 'domain'));
        self::assertSame($token, $desired['challenges'][0]['token']);
        self::assertFileExists($this->directory . DIRECTORY_SEPARATOR . '.desired.json');
    }

    public function testNeverUsedEmptyDesiredDoesNotCreateAnUnauthorizedMutation(): void
    {
        $desired = $this->store()->desired();
        self::assertSame(0, $desired['generation']);
        self::assertSame([], $desired['challenges']);
        self::assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . '.desired.json');
    }

    public function testMutationsPersistMonotonicDesiredGenerationAndLegacyProjection(): void
    {
        $store = $this->store();
        $first = $store->register(
            'one.example.test',
            'TOKEN_one',
            'TOKEN_one.' . \str_repeat('A', 43),
        );
        self::assertSame(1, $first['generation']);
        self::assertCount(1, $first['challenges']);
        self::assertSame(1_900, $first['challenges'][0]['expires_at']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $first['digest']);

        $legacy = \json_decode(
            (string)\file_get_contents(
                $this->directory . DIRECTORY_SEPARATOR . 'one_example_test.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('TOKEN_one', $legacy['token']);
        self::assertSame(1_900, $legacy['expires_at']);
        self::assertSame(1, $legacy['generation']);

        $second = $store->register(
            'two.example.test',
            'TOKEN_two',
            'TOKEN_two.' . \str_repeat('B', 43),
        );
        self::assertSame(2, $second['generation']);
        self::assertCount(2, $second['challenges']);

        $reloaded = $this->store()->desired();
        self::assertSame($second, $reloaded);

        $removed = $store->remove('one.example.test');
        self::assertSame(3, $removed['generation']);
        self::assertSame(['two.example.test'], \array_column($removed['challenges'], 'domain'));
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . 'one_example_test.json',
        );
    }

    public function testDesiredFiltersDomainsWithoutChangingProjectGeneration(): void
    {
        $store = $this->store();
        $store->register(
            'one.example.test',
            'TOKEN_one',
            'TOKEN_one.' . \str_repeat('A', 43),
        );
        $all = $store->register(
            'two.example.test',
            'TOKEN_two',
            'TOKEN_two.' . \str_repeat('B', 43),
        );
        $filtered = $store->desired(['two.example.test']);

        self::assertSame($all['generation'], $filtered['generation']);
        self::assertSame(['two.example.test'], \array_column($filtered['challenges'], 'domain'));
        self::assertNotSame($all['digest'], $filtered['digest']);
    }

    public function testExpiredLeaseIsPrunedAndAdvancesGenerationForClearReplay(): void
    {
        $store = $this->store();
        $store->register(
            'expired.example.test',
            'TOKEN_expired',
            'TOKEN_expired.' . \str_repeat('C', 43),
        );
        $this->now = 1_901;

        $expired = $store->desired();
        self::assertSame(2, $expired['generation']);
        self::assertSame([], $expired['challenges']);
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . 'expired_example_test.json',
        );
    }

    public function testRemovePersistsExpiryOnlyGenerationAdvance(): void
    {
        $store = $this->store();
        $store->register(
            'expired.example.test',
            'TOKEN_expired',
            'TOKEN_expired.' . \str_repeat('C', 43),
        );
        $this->now = 1_901;

        $removed = $store->remove('already-absent.example.test');
        self::assertSame(2, $removed['generation']);
        self::assertSame([], $removed['challenges']);

        $envelope = \json_decode(
            (string)\file_get_contents(
                $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            2,
            $envelope['payload']['generation'] ?? 0,
            'Expiry cleanup must persist the generation returned to the gateway.',
        );
    }

    public function testWildcardAndMalformedProofFailClosed(): void
    {
        $store = $this->store();
        foreach ([
            ['*.example.test', 'TOKEN', 'TOKEN.' . \str_repeat('A', 43)],
            ['example.test', 'bad token', 'bad token.' . \str_repeat('A', 43)],
            ['example.test', 'TOKEN', 'OTHER.' . \str_repeat('A', 43)],
        ] as [$domain, $token, $authorization]) {
            try {
                $store->register($domain, $token, $authorization);
                self::fail('Invalid ACME HTTP-01 challenge was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . '.desired.json',
        );
    }

    private function store(): ProjectAcmeHttp01ChallengeStore
    {
        return new ProjectAcmeHttp01ChallengeStore(
            $this->directory,
            fn (): int => $this->now,
        );
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (\is_dir($target) && !\is_link($target)) {
                $this->removeTree($target);
            } else {
                @\unlink($target);
            }
        }
        @\rmdir($path);
    }
}
