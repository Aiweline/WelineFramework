<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Extends\Module\Weline_Websites\AiSiteBuilderProvider;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Websites\AiSiteBuilderProvider\PageBuilderProvider;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Url;

class PageBuilderProviderTest extends TestCase
{
    public function testUsesWorkbenchHandoffUrlWhenNoNativeSessionExistsYet(): void
    {
        $provider = new PageBuilderProvider($this->createUrlMock());

        $config = $provider->getWorkbenchConfig(
            ['public_id' => 'websites-workspace-001'],
            7,
            [],
            [],
            []
        );

        $this->assertStringContainsString('websites/backend/site-builder-agent/pagebuilder-handoff', $config['native_entry_url']);
        $this->assertStringContainsString('public_id=websites-workspace-001', $config['native_entry_url']);
        $this->assertSame($config['native_entry_url'], $this->findToolUrl($config['tools'], 'open_pagebuilder_workspace'));
        $this->assertSame('prepare', $config['initial_stage']);
        $this->assertSame('pagebuilder_native_workspace', $config['scope']['provider_handoff_mode'] ?? '');
        $this->assertSame('pagebuilder_native', $config['scope']['provider_authority'] ?? '');
    }

    public function testUsesNativePageBuilderWorkspaceWhenLinkedWorkspaceExists(): void
    {
        $provider = new PageBuilderProvider($this->createUrlMock());

        $config = $provider->getWorkbenchConfig(
            ['public_id' => 'websites-workspace-001'],
            7,
            ['pagebuilder_workspace_public_id' => 'pagebuilder-native-001'],
            [],
            []
        );

        $this->assertStringContainsString('pagebuilder/backend/ai-site-agent/workspace', $config['native_entry_url']);
        $this->assertStringContainsString('public_id=pagebuilder-native-001', $config['native_entry_url']);
        $this->assertSame($config['native_entry_url'], $this->findToolUrl($config['tools'], 'open_pagebuilder_workspace'));
        $this->assertSame('generate', $config['initial_stage']);
    }

    public function testPrefersExplicitNativeWorkspaceUrlWhenAlreadyMirrored(): void
    {
        $provider = new PageBuilderProvider($this->createUrlMock());

        $config = $provider->getWorkbenchConfig(
            ['public_id' => 'websites-workspace-001'],
            7,
            [
                'pagebuilder_workspace_url' => 'https://backend.test/pagebuilder/backend/ai-site-agent/workspace?public_id=pagebuilder-native-002',
                'draft_website_id' => 88,
                'preview_page_id' => 321,
            ],
            [],
            []
        );

        $this->assertSame(
            'https://backend.test/pagebuilder/backend/ai-site-agent/workspace?public_id=pagebuilder-native-002',
            $config['native_entry_url']
        );
        $this->assertSame('generate', $config['initial_stage']);
        $this->assertStringContainsString('preview/full?visual_editor=1', (string)($config['stage_guides']['generate']['ai_recommendation'] ?? ''));
    }

    private function createUrlMock(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('getBackendUrl')->willReturnCallback(
            static function (string $path, array $params = []): string {
                $query = $params === [] ? '' : ('?' . \http_build_query($params));
                return 'https://backend.test/' . \ltrim($path, '/') . $query;
            }
        );

        return $url;
    }

    /**
     * @param list<array<string, mixed>> $tools
     */
    private function findToolUrl(array $tools, string $code): string
    {
        foreach ($tools as $tool) {
            if (($tool['code'] ?? '') === $code) {
                return (string)($tool['url'] ?? '');
            }
        }

        return '';
    }
}
