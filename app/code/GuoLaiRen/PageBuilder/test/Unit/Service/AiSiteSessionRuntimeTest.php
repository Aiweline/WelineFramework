<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\test\Unit\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionArtifactService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteSessionRuntime;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeManifestPolicy;
use PHPUnit\Framework\TestCase;

final class AiSiteSessionRuntimeTest extends TestCase
{
    public function testWithArtifactRejectsEmptyKey(): void
    {
        $runtime = new AiSiteSessionRuntime();
        $session = new AiSiteAgentSession();
        $session->setId(1);

        $this->expectException(\InvalidArgumentException::class);
        $runtime->withArtifact($session, 1, '', static fn(mixed $value): mixed => $value);
    }

    public function testWithBlockRejectsMissingPageTypeOrBlockId(): void
    {
        $runtime = new AiSiteSessionRuntime();
        $session = new AiSiteAgentSession();
        $session->setId(1);

        $this->expectException(\InvalidArgumentException::class);
        $runtime->withBlock($session, 1, '', 'hero', static fn(array $data): array => $data);
    }

    public function testWithRenderedPageRejectsEmptyPageType(): void
    {
        $runtime = new AiSiteSessionRuntime();
        $session = new AiSiteAgentSession();
        $session->setId(1);

        $this->expectException(\InvalidArgumentException::class);
        $runtime->withRenderedPage($session, '', static fn(string $html): int => \strlen($html));
    }

    public function testDehydrateAfterWithArtifactSimulation(): void
    {
        $policy = new AiSiteScopeManifestPolicy();
        $manifest = [
            'build_blueprint' => ['pages' => [['blocks' => [['id' => 'hero']]]]],
            'design_tokens' => ['font_display' => 'A', 'font_body' => 'A'],
            'theme_css_ref' => ['hash' => 'sha256:abc', 'css' => '.pb-c-section{}'],
            'theme_css' => '.pb-c-section{}',
        ];
        $dehydrated = $policy->dehydrateScopePaths($manifest);
        self::assertSame([], $dehydrated['build_blueprint']);
        self::assertArrayNotHasKey('css', $dehydrated['theme_css_ref']);
        self::assertArrayNotHasKey('theme_css', $dehydrated);
    }

    public function testWithArtifactReleasesPayloadCacheAfterSuccessfulCallback(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadScopeManifest')
            ->with($session)
            ->willReturn(['plan_workbench' => ['summary' => 'ok']]);
        $sessionService->expects(self::never())->method('replaceScope');

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());
        $result = $runtime->withArtifact(
            $session,
            5,
            'plan_workbench',
            static fn(array $payload): string => (string)($payload['summary'] ?? '')
        );

        self::assertSame('ok', $result);
    }

    public function testWithArtifactDirtyCheckDoesNotEncodeWholePayloadTwice(): void
    {
        $source = (string)\file_get_contents((new \ReflectionClass(AiSiteSessionRuntime::class))->getFileName());
        $start = \strpos($source, 'public function withArtifact(');
        $end = \strpos($source, 'public function readArtifact(', $start === false ? 0 : $start);

        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $withArtifactSource = \substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString('artifactPayloadsEqual($payload, $working)', $withArtifactSource);
        self::assertStringNotContainsString('stableJson($payload)', $withArtifactSource);
        self::assertStringNotContainsString('stableJson($working)', $withArtifactSource);
    }

    public function testReadArtifactReleasesPayloadCacheAfterSuccessfulCallback(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->expects(self::once())
            ->method('loadScopeManifest')
            ->with($session)
            ->willReturn(['plan_workbench' => ['summary' => 'ok']]);

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());
        $result = $runtime->readArtifact(
            $session,
            'plan_workbench',
            static fn(array $payload): string => (string)($payload['summary'] ?? '')
        );

        self::assertSame('ok', $result);
    }

    public function testWithArtifactReleasesPayloadCacheWhenCallbackThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willReturn(['plan_workbench' => ['summary' => 'ok']]);

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $runtime->withArtifact(
            $session,
            5,
            'plan_workbench',
            static function (): void {
                throw new \RuntimeException('boom');
            }
        );
    }

    public function testWithArtifactReleasesPayloadCacheWhenManifestLoadThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willThrowException(new \RuntimeException('manifest load failed'));

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manifest load failed');
        $runtime->withArtifact(
            $session,
            5,
            'plan_workbench',
            static fn(mixed $payload): mixed => $payload
        );
    }

    public function testReadArtifactReleasesPayloadCacheWhenManifestLoadThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willThrowException(new \RuntimeException('manifest load failed'));

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manifest load failed');
        $runtime->readArtifact(
            $session,
            'plan_workbench',
            static fn(mixed $payload): mixed => $payload
        );
    }

    public function testWithArtifactReleasesPayloadCacheWhenArtifactLoadThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willReturn(['plan_workbench_ref' => ['path' => 'missing']]);

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())
            ->method('hydrateScope')
            ->willThrowException(new \RuntimeException('artifact load failed'));
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('artifact load failed');
        $runtime->withArtifact(
            $session,
            5,
            'plan_workbench',
            static fn(mixed $payload): mixed => $payload
        );
    }

    public function testReadArtifactReleasesPayloadCacheWhenArtifactLoadThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willReturn(['plan_workbench_ref' => ['path' => 'missing']]);

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())
            ->method('hydrateScope')
            ->willThrowException(new \RuntimeException('artifact load failed'));
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('artifact load failed');
        $runtime->readArtifact(
            $session,
            'plan_workbench',
            static fn(mixed $payload): mixed => $payload
        );
    }

    public function testReadArtifactReleasesPayloadCacheWhenCallbackThrows(): void
    {
        $session = new AiSiteAgentSession();
        $session->setId(17);
        $sessionService = $this->createMock(AiSiteAgentSessionService::class);
        $sessionService->method('loadScopeManifest')->willReturn(['plan_workbench' => ['summary' => 'ok']]);

        $artifactService = $this->createMock(AiSiteAgentSessionArtifactService::class);
        $artifactService->expects(self::once())->method('releasePayloadCache');

        $runtime = new AiSiteSessionRuntime($sessionService, $artifactService, new AiSiteScopeManifestPolicy());

        $this->expectException(\RuntimeException::class);
        $runtime->readArtifact(
            $session,
            'plan_workbench',
            static function (): void {
                throw new \RuntimeException('read boom');
            }
        );
    }
}
