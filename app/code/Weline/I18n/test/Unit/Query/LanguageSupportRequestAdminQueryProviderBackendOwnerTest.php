<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestDirectoryInterface;
use Weline\I18n\Api\LanguageRequest\LanguageSupportRequestWorkflowInterface;
use Weline\I18n\Extends\Module\Weline_Framework\Query\LanguageSupportRequestAdminQueryProvider;

final class LanguageSupportRequestAdminQueryProviderBackendOwnerTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::remove(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        parent::tearDown();
    }

    public function testReviewUsesAttestedWorkerBackendUserIdWithoutStartingSession(): void
    {
        RequestContext::set(
            FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
            FrontendWorkerExecutionContext::backend($this->backendBinding(7)),
        );

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->expects(self::never())->method('createBackendSession');

        $workflow = $this->createMock(LanguageSupportRequestWorkflowInterface::class);
        $workflow->expects(self::once())
            ->method('review')
            ->with([3], 'accepted', 7, '')
            ->willReturn(1);

        $provider = new LanguageSupportRequestAdminQueryProvider(
            $this->createMock(LanguageSupportRequestDirectoryInterface::class),
            $workflow,
            $sessions,
        );

        $result = $provider->execute('review', [
            'item_ids' => [3],
            'status' => 'accepted',
            'note' => '',
        ]);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['updated']);
    }

    public function testLegacyBackendSessionIsUsedWhenWorkerContextIsAbsent(): void
    {
        $session = $this->createMock(AuthenticatedSessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->expects(self::never())->method('start');
        $session->method('isLoggedIn')->willReturn(true);
        $session->method('getUserId')->willReturn(9);

        $sessions = $this->createMock(SessionFactory::class);
        $sessions->method('createBackendSession')->willReturn($session);

        $workflow = $this->createMock(LanguageSupportRequestWorkflowInterface::class);
        $workflow->expects(self::once())
            ->method('review')
            ->with([1], 'rejected', 9, 'nope')
            ->willReturn(1);

        $provider = new LanguageSupportRequestAdminQueryProvider(
            $this->createMock(LanguageSupportRequestDirectoryInterface::class),
            $workflow,
            $sessions,
        );

        $result = $provider->execute('review', [
            'item_ids' => [1],
            'status' => 'rejected',
            'note' => 'nope',
        ]);

        self::assertTrue($result['success']);
        self::assertSame(1, $result['updated']);
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

        $provider = new LanguageSupportRequestAdminQueryProvider(
            $this->createMock(LanguageSupportRequestDirectoryInterface::class),
            $this->createMock(LanguageSupportRequestWorkflowInterface::class),
            $sessions,
        );

        try {
            $provider->execute('review', ['item_ids' => [1], 'status' => 'accepted']);
            self::fail('Missing backend session must fail closed as a QueryBin auth error.');
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
}
