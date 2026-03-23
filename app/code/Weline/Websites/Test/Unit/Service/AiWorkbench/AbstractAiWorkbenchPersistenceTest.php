<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Model\AiSiteBuilderArtifact;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderMessage;
use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Service\AiWorkbench\SessionService;

abstract class AbstractAiWorkbenchPersistenceTest extends TestCore
{
    protected SessionService $sessionService;

    protected AiSiteBuilderSession $sessionModel;

    protected AiSiteBuilderMessage $messageModel;

    protected AiSiteBuilderArtifact $artifactModel;

    protected AiSiteBuilderEvent $eventModel;

    /** @var int[] */
    private array $createdSessionIds = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->sessionService = ObjectManager::getInstance(SessionService::class);
        $this->sessionModel = ObjectManager::getInstance(AiSiteBuilderSession::class);
        $this->messageModel = ObjectManager::getInstance(AiSiteBuilderMessage::class);
        $this->artifactModel = ObjectManager::getInstance(AiSiteBuilderArtifact::class);
        $this->eventModel = ObjectManager::getInstance(AiSiteBuilderEvent::class);
    }

    public function tearDown(): void
    {
        $this->cleanupCreatedSessions();
        parent::tearDown();
    }

    protected function createTrackedSession(
        string $providerCode = 'websites_default',
        int $adminUserId = 1,
        array $scope = [],
        array $providerState = [],
        string $initialStage = AiSiteBuilderSession::STAGE_BRIEF
    ): AiSiteBuilderSession
    {
        $session = $this->sessionService->createSession($providerCode, $adminUserId, $scope, $providerState, $initialStage);
        $this->createdSessionIds[] = $session->getId();
        return $session;
    }

    private function cleanupCreatedSessions(): void
    {
        $sessionIds = \array_values(\array_unique(\array_filter($this->createdSessionIds)));
        if ($sessionIds === []) {
            return;
        }

        $this->messageModel->clearData()->clearQuery()
            ->where(AiSiteBuilderMessage::schema_fields_SESSION_ID, $sessionIds, 'IN')
            ->delete()
            ->fetch();

        $this->artifactModel->clearData()->clearQuery()
            ->where(AiSiteBuilderArtifact::schema_fields_SESSION_ID, $sessionIds, 'IN')
            ->delete()
            ->fetch();

        $this->eventModel->clearData()->clearQuery()
            ->where(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionIds, 'IN')
            ->delete()
            ->fetch();

        $this->sessionModel->clearData()->clearQuery()
            ->where(AiSiteBuilderSession::schema_fields_ID, $sessionIds, 'IN')
            ->delete()
            ->fetch();

        $this->createdSessionIds = [];
    }
}
