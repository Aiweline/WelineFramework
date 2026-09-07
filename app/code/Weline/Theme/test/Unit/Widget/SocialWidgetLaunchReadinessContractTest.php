<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

final class SocialWidgetLaunchReadinessContractTest extends TestCase
{
    /** @var list<string> */
    private const WIDGETS = [
        'social/footer-social/default.phtml',
        'sidebar/sidebar-social/default.phtml',
    ];

    public function testUnconfiguredWidgetsDoNotRenderFakeSocialLinks(): void
    {
        foreach (self::WIDGETS as $widget) {
            self::assertSame('', trim($this->render($widget)), $widget);
        }
    }

    public function testInvalidCustomSocialUrlsAreSuppressed(): void
    {
        $customLinks = json_encode([
            ['platform' => 'facebook', 'url' => 'javascript:alert(1)'],
            ['platform' => 'instagram', 'url' => '#'],
            ['platform' => 'youtube', 'url' => '//example.com/channel'],
        ], JSON_THROW_ON_ERROR);

        foreach (self::WIDGETS as $widget) {
            $html = $this->render($widget, ['custom_links' => $customLinks]);
            self::assertSame('', trim($html), $widget);
        }
    }

    public function testConfiguredHttpsSocialLinkStillRenders(): void
    {
        $customLinks = json_encode([
            ['platform' => 'instagram', 'url' => 'https://www.instagram.com/yunshang.hanfu'],
        ], JSON_THROW_ON_ERROR);

        foreach (self::WIDGETS as $widget) {
            $html = $this->render($widget, ['custom_links' => $customLinks]);
            self::assertStringContainsString(
                'href="https://www.instagram.com/yunshang.hanfu"',
                $html,
                $widget,
            );
            self::assertStringNotContainsString('href="#"', $html, $widget);
        }
    }

    /** @param array<string, mixed> $data */
    private function render(string $widget, array $data = []): string
    {
        $renderer = new class($data) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function render(string $path): string
            {
                ob_start();
                try {
                    include $path;
                    return (string)ob_get_clean();
                } catch (\Throwable $throwable) {
                    ob_end_clean();
                    throw $throwable;
                }
            }
        };

        return $renderer->render(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/' . $widget,
        );
    }
}
