<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\ArtifactService;
use Weline\Websites\Service\AiWorkbench\EventStreamService;
use Weline\Websites\Service\AiWorkbench\MessageService;

class SessionServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    public function testCreateAndMutateSession(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, ['site_title' => 'Demo']);

        $this->assertGreaterThan(0, $session->getId());
        $this->assertSame('websites_default', $session->getProviderCode());
        $this->assertSame('brief', $session->getCurrentStage());
        $this->assertSame(['site_title' => 'Demo'], $session->getScopeArray());

        $this->assertTrue($this->sessionService->saveProviderState($session->getId(), 1, ['provider' => ['step' => 2]]));
        $this->assertTrue($this->sessionService->saveScope($session->getId(), 1, ['site_title' => 'Updated']));
        $this->assertTrue($this->sessionService->setStage($session->getId(), 1, 'domain_candidates'));
        $this->assertTrue($this->sessionService->bindWebsite($session->getId(), 1, 88));
        $this->assertTrue($this->sessionService->bindDomain($session->getId(), 1, 'Example.com', 12));
        $this->assertTrue($this->sessionService->setPreviewUrl($session->getId(), 1, 'https://preview.local/demo'));

        $loadedByPublicId = $this->sessionService->loadByPublicId($session->getPublicId(), 1);
        $loadedById = $this->sessionService->loadById($session->getId(), 1);
        $recentSessions = $this->sessionService->listRecentSessionsForAdmin(1, 10);

        $this->assertNotNull($loadedByPublicId);
        $this->assertNotNull($loadedById);
        $this->assertSame(['site_title' => 'Updated'], $loadedById?->getScopeArray());
        $this->assertSame(['provider' => ['step' => 2]], $loadedById?->getProviderStateArray());
        $this->assertSame('domain_candidates', $loadedById?->getCurrentStage());
        $this->assertSame(88, $loadedById?->getWebsiteId());
        $this->assertSame('example.com', $loadedById?->getSelectedDomain());
        $this->assertSame(12, $loadedById?->getRegistrarAccountId());
        $this->assertSame('https://preview.local/demo', $loadedById?->getPreviewUrl());
        $this->assertNotEmpty($recentSessions);
        $this->assertSame($session->getId(), $recentSessions[0]['session_id']);
    }

    public function testCreateSessionSupportsInitialStage(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, ['site_title' => 'Stage Demo'], [], 'visual_edit');

        $this->assertGreaterThan(0, $session->getId());
        $this->assertSame('visual_edit', $session->getCurrentStage());
    }

    public function testDeleteSessionRemovesSessionAndRelatedRecords(): void
    {
        $session = $this->createTrackedSession('websites_default', 1, ['site_title' => 'Delete Demo']);
        /** @var MessageService $messageService */
        $messageService = ObjectManager::getInstance(MessageService::class);
        /** @var ArtifactService $artifactService */
        $artifactService = ObjectManager::getInstance(ArtifactService::class);
        /** @var EventStreamService $eventStreamService */
        $eventStreamService = ObjectManager::getInstance(EventStreamService::class);

        $this->assertTrue($messageService->appendMessage($session->getId(), 1, 'user', 'delete me'));
        $this->assertTrue($artifactService->upsertArtifact($session->getId(), 1, 'workspace', 'snapshot', ['ok' => true], 'Delete Demo'));
        $this->assertGreaterThan(0, $eventStreamService->appendEvent($session->getId(), 1, 'brief', 'created', ['ok' => true]));

        $this->assertTrue($this->sessionService->deleteSessionByPublicId($session->getPublicId(), 1));
        $this->assertNull($this->sessionService->loadByPublicId($session->getPublicId(), 1));
        $this->assertFalse($this->sessionService->deleteSessionByPublicId($session->getPublicId(), 1));

        $this->assertSame([], $this->messageModel->clearData()->clearQuery()
            ->where(\Weline\Websites\Model\AiSiteBuilderMessage::schema_fields_SESSION_ID, $session->getId())
            ->select()
            ->fetchArray());
        $this->assertSame([], $this->artifactModel->clearData()->clearQuery()
            ->where(\Weline\Websites\Model\AiSiteBuilderArtifact::schema_fields_SESSION_ID, $session->getId())
            ->select()
            ->fetchArray());
        $this->assertSame([], $this->eventModel->clearData()->clearQuery()
            ->where(\Weline\Websites\Model\AiSiteBuilderEvent::schema_fields_SESSION_ID, $session->getId())
            ->select()
            ->fetchArray());
    }
}
