<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service\AiWorkbench;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Websites\Api\AiSiteBuilderProviderInterface;
use Weline\Websites\Api\AiSiteBuilderWorkbenchProviderInterface;
use Weline\Websites\Service\AiWorkbench\ExtensionPointReader;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;

class ProviderWorkbenchServiceTest extends TestCore
{
    public function testWorkbenchConfigUsesProviderOverridesAndNormalizesToolsAndStages(): void
    {
        $service = new ProviderWorkbenchService(
            new ProviderRegistry(
                ObjectManager::getInstance(),
                new ProviderWorkbenchFakeExtensionPointReader([
                    'Weline_Websites' => [
                        'AiSiteBuilderProvider' => [
                            ['class_name' => ProviderWorkbenchRichProvider::class],
                        ],
                    ],
                ])
            )
        );

        $config = $service->buildWorkbenchConfig(
            'provider_rich',
            7,
            ['current_stage' => 'brief'],
            ['requested_key' => 'requested_value'],
            ['entry' => ['source' => 'hub']],
            ['source' => 'hub']
        );

        $this->assertSame('provider_rich', $config['code']);
        $this->assertSame('Rich Provider', $config['name']);
        $this->assertSame('Provider Badge', $config['badge']);
        $this->assertSame('https://example.test/workbench', $config['native_entry_url']);
        $this->assertSame('generate', $config['initial_stage']);
        $this->assertSame(
            [
                'requested_key' => 'requested_value',
                'provider_default' => 'yes',
            ],
            $config['scope']
        );
        $this->assertSame(
            [
                'entry' => ['source' => 'hub'],
                'provider' => ['mode' => 'rich'],
            ],
            $config['provider_state']
        );
        $this->assertCount(2, $config['tools']);
        $this->assertSame('open_workspace', $config['tools'][0]['code']);
        $this->assertSame('link', $config['tools'][0]['type']);
        $this->assertSame('prepare_visual_edit', $config['tools'][1]['code']);
        $this->assertSame('scope_patch', $config['tools'][1]['type']);
        $this->assertSame('generate', $config['tools'][1]['stage']);
        $this->assertSame(['preferred_editor' => 'rich'], $config['tools'][1]['scope_patch']);

        $this->assertCount(3, $config['stage_guides']);
        $this->assertSame('prepare', $config['stage_guides'][0]['code']);
        $this->assertSame('Custom generate title', $config['stage_guides'][1]['title']);
        $this->assertSame('Rich generate recommendation', $config['stage_guides'][1]['ai_recommendation']);
        $this->assertSame(['open_workspace'], $config['stage_guides'][2]['tool_codes']);
    }

    public function testWorkbenchConfigFallsBackForBasicProvider(): void
    {
        $service = new ProviderWorkbenchService(
            new ProviderRegistry(
                ObjectManager::getInstance(),
                new ProviderWorkbenchFakeExtensionPointReader([
                    'Weline_Websites' => [
                        'AiSiteBuilderProvider' => [
                            ['class_name' => ProviderWorkbenchBasicProvider::class],
                        ],
                    ],
                ])
            )
        );

        $config = $service->buildWorkbenchConfig('provider_basic', 1);

        $this->assertSame('provider_basic', $config['code']);
        $this->assertSame('Basic Provider', $config['name']);
        $this->assertNotSame('', $config['badge']);
        $this->assertSame('prepare', $config['initial_stage']);
        $this->assertSame([], $config['tools']);
        $this->assertSame('', $config['native_entry_url']);
        $this->assertNotSame('', $config['welcome_message']);
        $this->assertCount(3, $config['stage_guides']);
        $this->assertSame('prepare', $config['stage_guides'][0]['code']);
        $this->assertSame('complete', $config['stage_guides'][2]['code']);
    }
}

final class ProviderWorkbenchFakeExtensionPointReader extends ExtensionPointReader
{
    public function __construct(
        private array $entries,
    ) {
    }

    public function hasExtensionPoint(string $moduleName, string $extensionPointName, bool $forceReload = false): bool
    {
        return isset($this->entries[$moduleName][$extensionPointName]);
    }

    public function getExtensionEntries(string $moduleName, string $extensionPointName, bool $forceReload = false): array
    {
        return $this->entries[$moduleName][$extensionPointName] ?? [];
    }

    public function getRegistryFileMtime(): int
    {
        return 100;
    }

    public function resolveClassName(array $extension): ?string
    {
        return $extension['class_name'] ?? null;
    }
}

final class ProviderWorkbenchRichProvider implements AiSiteBuilderWorkbenchProviderInterface
{
    public function getCode(): string
    {
        return 'provider_rich';
    }

    public function getName(): string
    {
        return 'Rich Provider';
    }

    public function getDescription(): string
    {
        return 'Rich provider for workbench service tests.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function getWorkbenchConfig(
        ?array $sessionState,
        int $adminUserId,
        array $scope = [],
        array $providerState = [],
        array $context = []
    ): array {
        return [
            'badge' => 'Provider Badge',
            'target_url' => 'https://example.test/target',
            'target_label' => 'Open Target',
            'workspace_label' => 'Create Rich Workspace',
            'handoff_label' => 'Continue Rich Flow',
            'native_entry_url' => 'https://example.test/workbench',
            'welcome_message' => 'Rich welcome message',
            'initial_stage' => 'visual_edit',
            'scope' => [
                'provider_default' => 'yes',
            ],
            'provider_state' => [
                'provider' => ['mode' => 'rich'],
            ],
            'stage_guides' => [
                'generate' => [
                    'title' => 'Custom generate title',
                    'ai_recommendation' => 'Rich generate recommendation',
                ],
                'complete' => [
                    'tool_codes' => ['open_workspace'],
                ],
            ],
            'tools' => [
                [
                    'code' => 'open_workspace',
                    'label' => 'Open workspace',
                    'type' => 'link',
                    'url' => 'https://example.test/workbench',
                ],
                [
                    'code' => 'prepare_visual_edit',
                    'label' => 'Prepare visual edit',
                    'type' => 'scope_patch',
                    'stage' => 'visual_edit',
                    'scope_patch' => ['preferred_editor' => 'rich'],
                ],
                [
                    'code' => 'invalid_missing_url',
                    'label' => 'Invalid link',
                    'type' => 'link',
                ],
            ],
        ];
    }
}

final class ProviderWorkbenchBasicProvider implements AiSiteBuilderProviderInterface
{
    public function getCode(): string
    {
        return 'provider_basic';
    }

    public function getName(): string
    {
        return 'Basic Provider';
    }

    public function getDescription(): string
    {
        return 'Basic provider without workbench overrides.';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getSortOrder(): int
    {
        return 20;
    }
}
