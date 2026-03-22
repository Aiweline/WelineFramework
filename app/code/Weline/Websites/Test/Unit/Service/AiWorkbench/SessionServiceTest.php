<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

class SessionServiceTest extends AbstractAiWorkbenchPersistenceTest
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
}
