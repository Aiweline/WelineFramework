<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Backend\Visual\Api\Component;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class VisualApiComponentVirtualMetadataTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = (new ReflectionClass(Component::class))->newInstanceWithoutConstructor();
    }

    public function testVirtualMetadataFieldsAreGroupedForWorkspaceEditor(): void
    {
        $fields = $this->invokePrivate('buildVirtualComponentFields', [[
            'content.title' => 'Discover AI plugins',
            'content.description' => 'A long intro paragraph used by the generated hero section.',
            'style.bg_color' => '#123abc',
            'media.logo_url' => '/media/logo.png',
            'settings.enabled' => 'yes',
            '_ai_prompt' => 'internal prompt',
        ]]);

        self::assertArrayHasKey('content', $fields);
        self::assertArrayHasKey('style', $fields);
        self::assertArrayHasKey('media', $fields);
        self::assertArrayHasKey('settings', $fields);
        self::assertArrayNotHasKey('_ai_prompt', $fields);

        self::assertSame('text', $fields['content']['fields']['title']['type']);
        self::assertSame('textarea', $fields['content']['fields']['description']['type']);
        self::assertSame('color', $fields['style']['fields']['bg_color']['type']);
        self::assertSame('image', $fields['media']['fields']['logo_url']['type']);
        self::assertSame('select', $fields['settings']['fields']['enabled']['type']);
        self::assertSame(['yes', 'no'], $fields['settings']['fields']['enabled']['options']);
    }

    public function testVirtualLayoutEntryUsesRequestedContentIndexBeforeFallback(): void
    {
        $layout = [
            'content' => [
                [
                    'code' => 'content/shared-card',
                    'config' => ['content.title' => 'First card'],
                ],
                [
                    'code' => 'content/shared-card',
                    'config' => ['content.title' => 'Second card'],
                ],
            ],
        ];

        $entry = $this->invokePrivate('findVirtualLayoutComponentEntry', [
            $layout,
            'content/shared-card',
            'content',
            1,
        ]);

        self::assertSame(['content.title' => 'Second card'], $entry['config']);
    }

    public function testVirtualRequestRequiresPublicIdAndPageType(): void
    {
        self::assertTrue($this->invokePrivate('isVirtualRequest', [[
            'public_id' => '27824998dc4f96634522b3e8a87a6e6d',
            'page_type' => 'home_page',
        ]]));
        self::assertFalse($this->invokePrivate('isVirtualRequest', [[
            'public_id' => '',
            'page_type' => 'home_page',
        ]]));
        self::assertFalse($this->invokePrivate('isVirtualRequest', [[
            'public_id' => '27824998dc4f96634522b3e8a87a6e6d',
            'page_type' => '',
        ]]));
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args)
    {
        $method = new ReflectionMethod(Component::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, $args);
    }
}
