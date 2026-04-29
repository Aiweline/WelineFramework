<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\AiGenerate;
use GuoLaiRen\PageBuilder\Model\Page as PageModel;
use GuoLaiRen\PageBuilder\Model\Style;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AiGenerateVirtualBlockGenerationTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new AiGenerate(
            $this->createMock(PageModel::class),
            $this->createMock(Style::class)
        );
    }

    public function testVirtualBlockEntryMatchesContentComponentAlias(): void
    {
        $entry = $this->invokePrivate('resolveVirtualBlockEntryForGeneration', [[
            'virtual_page' => [
                'blocks' => [
                    [
                        'block_id' => 'ai-generated-section',
                        'type' => 'ai_generated_section',
                        'config' => ['headline' => 'Old headline'],
                    ],
                ],
            ],
        ], 'content/ai-generated-section', 'content', 0]);

        self::assertSame('ai-generated-section', $entry['block_id'] ?? '');
        self::assertSame(['headline' => 'Old headline'], $entry['config'] ?? []);
    }

    public function testVirtualBlockMetadataPreservesExactConfigKeysForAiOutput(): void
    {
        $metadata = $this->invokePrivate('buildVirtualBlockMetadataForGeneration', [[
            'block_id' => 'home-hero',
            'type' => 'ai_generated_section',
            'config' => [
                'headline' => 'Old headline',
                'description' => 'Old description',
                '_ai_prompt' => 'private prompt',
            ],
            'field_schema' => [
                'content' => [
                    'label' => 'Content',
                    'fields' => [
                        'headline' => ['type' => 'text', 'label' => 'Headline'],
                        'description' => ['type' => 'textarea', 'label' => 'Description'],
                        '_ai_prompt' => ['type' => 'textarea', 'label' => 'Prompt'],
                    ],
                ],
            ],
        ], 'home-hero', 'content']);

        self::assertIsArray($metadata);
        self::assertSame('headline', $metadata['fields']['content']['fields']['headline']['key'] ?? null);
        self::assertSame('description', $metadata['fields']['content']['fields']['description']['key'] ?? null);
        self::assertArrayNotHasKey('_ai_prompt', $metadata['fields']['content']['fields']);

        $textConfigs = $this->invokePrivate('getComponentTextConfigs', [$metadata]);
        self::assertSame(['headline', 'description'], \array_column($textConfigs, 'key'));
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args)
    {
        $method = new ReflectionMethod(AiGenerate::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, $args);
    }
}
