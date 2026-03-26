<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Service\AiWorkbench\ArtifactService;

class ArtifactServiceTest extends AbstractAiWorkbenchPersistenceCase
{
    private ArtifactService $artifactService;

    public function setUp(): void
    {
        parent::setUp();
        $this->artifactService = ObjectManager::getInstance(ArtifactService::class);
    }

    public function testUpsertArtifactUpdatesExistingRow(): void
    {
        $session = $this->createTrackedSession();

        $this->assertTrue($this->artifactService->upsertArtifact(
            $session->getId(),
            1,
            'theme_layout',
            'home',
            ['title' => 'Homepage'],
            'Homepage Draft',
            'draft'
        ));
        $this->assertTrue($this->artifactService->upsertArtifact(
            $session->getId(),
            1,
            'theme_layout',
            'home',
            ['title' => 'Homepage Final', 'sections' => 6],
            'Homepage Final',
            'ready'
        ));

        $artifact = $this->artifactService->getOne($session->getId(), 1, 'theme_layout', 'home');
        $artifacts = $this->artifactService->listByType($session->getId(), 1, 'theme_layout');

        $this->assertNotNull($artifact);
        $this->assertCount(1, $artifacts);
        $this->assertSame('home', $artifact['artifact_code']);
        $this->assertSame('Homepage Final', $artifact['title']);
        $this->assertSame('ready', $artifact['status']);
        $this->assertSame(
            ['title' => 'Homepage Final', 'sections' => 6],
            $artifact['payload']
        );
        $this->assertSame($artifact['artifact_id'], $artifacts[0]['artifact_id']);
        $this->assertSame('Homepage Final', $artifacts[0]['title']);
        $this->assertSame('ready', $artifacts[0]['status']);
        $this->assertSame(
            ['title' => 'Homepage Final', 'sections' => 6],
            $artifacts[0]['payload']
        );
    }
}
