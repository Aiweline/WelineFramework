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
        $this->assertSame($config['native_entry_url'], $this->findToolUrl($config['tools'], 'resume_legacy_workspace'));
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

        $this->assertStringContainsString('pagebuilder/backend/aiSiteAgent/workspace', $config['native_entry_url']);
        $this->assertStringContainsString('public_id=pagebuilder-native-001', $config['native_entry_url']);
        $this->assertSame($config['native_entry_url'], $this->findToolUrl($config['tools'], 'resume_legacy_workspace'));
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
