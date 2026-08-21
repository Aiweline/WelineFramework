<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\FrontendWorkerStateStoreInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

final class FrontendWorkerSessionServiceTest extends TestCase
{
    public function testStreamUrlUsesTheConfiguredRestFrontendPrefix(): void
    {
        $reflection = new \ReflectionClass(FrontendWorkerSessionService::class);
        /** @var FrontendWorkerSessionService $service */
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildStreamUrl');
        $ticket = 'stream-ticket';

        $url = (string)$method->invoke($service, $ticket);
        $prefix = \trim((string)(Env::getAreaRoutePrefix('rest_frontend') ?: 'api'), '/');
        $prefix = $prefix !== '' ? $prefix : 'api';

        self::assertSame('/' . $prefix . '/framework/stream?ticket=' . $ticket, $url);
    }

    public function testStreamTicketPreservesOwnerBinding(): void
    {
        $service = new FrontendWorkerSessionService();
        $owner = [
            'area' => 'backend',
            'principal' => 'backend:7',
        ];

        $created = $service->createStreamTicket('page_builder.aiSiteStream', ['public_id' => 'site-1'], $owner);
        self::assertSame($owner, $created['owner'] ?? null);

        $consumed = $service->consumeStreamTicket((string)$created['ticket']);
        self::assertSame('page_builder.aiSiteStream', $consumed['channel']);
        self::assertSame(['public_id' => 'site-1'], $consumed['params']);
        self::assertSame($owner, $consumed['owner']);
    }

    public function testScopeBindingUsesTheSharedSixtySecondClockSkewBoundary(): void
    {
        $service = (new \ReflectionClass(FrontendWorkerSessionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(FrontendWorkerSessionService::class, 'assertBindingUsable');
        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST);

        $method->invoke($service, new FrontendWorkerScopeBinding(
            $scope,
            'shop.example.test',
            hash('sha256', 'accepted-token'),
            1_060,
            2_860,
            true,
        ), 1_000);
        self::addToAssertionCount(1);

        try {
            $method->invoke($service, new FrontendWorkerScopeBinding(
                $scope,
                'shop.example.test',
                hash('sha256', 'rejected-token'),
                1_061,
                2_861,
                true,
            ), 1_000);
            self::fail('A Scope binding beyond the clock-skew boundary was accepted.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testScopeBootstrapWrongProofDoesNotConsumeAndValidProofConsumesOnce(): void
    {
        $service = new FrontendWorkerSessionService(new SessionMemoryStateStore());
        $now = time();
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(0, 'default', 'main', 'web', ScopeIdentity::MODE_TEST),
            'shop.example.test',
            hash('sha256', 'valid-token'),
            $now,
            $now + 1800,
            true,
        );
        $bootstrap = $service->createScopeBootstrap($binding);

        try {
            $service->createSessionFromScopeBootstrap(
                'test-deploy',
                'test-worker',
                $bootstrap['bootstrap_id'],
                hash('sha256', 'wrong-token'),
                $binding->digest(),
            );
            self::fail('Wrong bootstrap proof was accepted.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }

        $session = $service->createSessionFromScopeBootstrap(
            'test-deploy',
            'test-worker',
            $bootstrap['bootstrap_id'],
            $binding->tokenFingerprint,
            $binding->digest(),
        );
        self::assertTrue($session['scope_bound']);
        self::assertSame('test-deploy', $session['deploy_version']);
        self::assertSame('test-worker', $session['worker_build_id']);

        try {
            $service->createSessionFromScopeBootstrap(
                'test-deploy',
                'test-worker',
                $bootstrap['bootstrap_id'],
                $binding->tokenFingerprint,
                $binding->digest(),
            );
            self::fail('Consumed Scope bootstrap was replayed.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testRepairOwnedPrivateRegularFileTightensGroupWritableModes(): void
    {
        $path = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-fw-store-' . \bin2hex(\random_bytes(6));
        \file_put_contents($path, '{}');
        self::assertTrue(@\chmod($path, 0660));
        \clearstatcache(true, $path);
        self::assertSame(0660, \fileperms($path) & 0777);

        try {
            $service = (new \ReflectionClass(FrontendWorkerSessionService::class))->newInstanceWithoutConstructor();
            $method = new \ReflectionMethod(FrontendWorkerSessionService::class, 'repairOwnedPrivateRegularFile');
            $method->invoke($service, $path);

            \clearstatcache(true, $path);
            self::assertSame(0600, \fileperms($path) & 0777);
        } finally {
            @\unlink($path);
        }
    }
}

final class SessionMemoryStateStore implements FrontendWorkerStateStoreInterface
{
    /** @var array<string, mixed> */
    private array $state = [];

    public function transaction(callable $callback): mixed
    {
        return $callback($this->state);
    }

    public function driver(): string
    {
        return 'test-memory';
    }

    public function isShared(): bool
    {
        return false;
    }
}
