<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;

final class MediaManagerQueryProviderBackendOwnerTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        parent::tearDown();
    }

    public function testConfigUsesAttestedWorkerBackendUserIdWithoutStartingSession(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php'
        );
        self::assertStringNotContainsString('ResumableTaskOwnerResolver', $source);
        self::assertStringNotContainsString('ResumableTaskAccessDeniedException', $source);
        self::assertStringNotContainsString('backendAdminId', $source);
        self::assertStringContainsString('backendUserId', $source);
        self::assertStringContainsString('FrontendWorkerExecutionContext', $source);

        RequestContext::set(
            FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            FrontendWorkerExecutionContext::backend($this->backendBinding(7)),
        );

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->expects(self::never())->method('createBackendSession');

        $aiDraw = $this->createMock(AiDrawService::class);
        $aiDraw->expects(self::once())
            ->method('getConfigStatus')
            ->willReturn(['ready' => true, 'model' => 'mock']);

        $provider = new MediaManagerQueryProvider($aiDraw, $sessions, $this->accessContextFactory());
        $result = $provider->execute('config', []);

        self::assertSame(['ready' => true, 'model' => 'mock'], $result);
    }

    public function testLegacyBackendSessionIsUsedWhenWorkerContextIsAbsent(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->expects(self::never())->method('start');
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getUserId')->willReturn(7);

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->method('createBackendSession')->willReturn($session);

        $aiDraw = $this->createMock(AiDrawService::class);
        $aiDraw->expects(self::once())
            ->method('getConfigStatus')
            ->willReturn(['ready' => true, 'model' => 'mock']);

        $provider = new MediaManagerQueryProvider($aiDraw, $sessions, $this->accessContextFactory());
        $result = $provider->execute('config', []);

        self::assertSame(['ready' => true, 'model' => 'mock'], $result);
    }

    public function testMissingBackendSessionMapsToAuthErrorNotInternalServerError(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isStarted')->willReturn(false);
        $session->method('start');
        $session->method('isLoggedIn')->willReturn(false);
        $session->method('getUserId')->willReturn(null);

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->method('createBackendSession')->willReturn($session);

        $provider = new MediaManagerQueryProvider(
            $this->createMock(AiDrawService::class),
            $sessions,
            $this->accessContextFactory(),
        );

        try {
            $provider->execute('config', []);
            self::fail('Missing backend session must fail closed as a QueryBin auth error.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testFrontendWorkerContextCannotUseBackendMediaOperations(): void
    {
        RequestContext::set(
            FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            FrontendWorkerExecutionContext::frontend(),
        );

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->expects(self::never())->method('createBackendSession');

        $provider = new MediaManagerQueryProvider(
            $this->createMock(AiDrawService::class),
            $sessions,
            $this->accessContextFactory(),
        );

        try {
            $provider->execute('config', []);
            self::fail('Frontend Worker context must not fall back to PHP Session start.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('auth_error', $exception->getErrorCode());
            self::assertSame(401, $exception->getHttpStatus());
        }
    }

    private function backendBinding(int $userId): FrontendWorkerBackendBinding
    {
        $now = \time();

        return new FrontendWorkerBackendBinding(
            $userId,
            \str_repeat('ab', 32),
            '127.0.0.1:9555',
            $now,
            $now + 3600,
        );
    }

    private function accessContextFactory(): MediaFileAccessContextFactory
    {
        return new MediaFileAccessContextFactory(
            $this->createMock(FileAssetLibraryInterface::class),
        );
    }
}
