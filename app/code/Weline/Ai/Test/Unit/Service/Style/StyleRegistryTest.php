<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Style;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Style\StyleRegistry;

final class StyleRegistryTest extends TestCase
{
    public function testMatchStyleIgnoresKeywordsInsideNegativeConstraints(): void
    {
        $brief = 'Build a polished AI workflow automation SaaS website for operations teams. Avoid gaming, casino, APK, reward, card, neon, gambling, or entertainment visual language.';

        $match = (new StyleRegistry())->matchStyle('OpsFlow AI', $brief, 1);

        self::assertFalse((bool)$match['matched']);
        self::assertSame('', (string)($match['item']['code'] ?? ''));
        self::assertSame([], $match['matched_keywords']);
    }

    public function testMatchStyleStillUsesPositiveCardGameKeywords(): void
    {
        $match = (new StyleRegistry())->matchStyle(
            'Teen Patti APK',
            'Promote Teen Patti APK download for Indian card players.',
            1
        );

        self::assertTrue((bool)$match['matched']);
        self::assertSame('india-card-game-apk-dark-neon', (string)($match['item']['code'] ?? ''));
        self::assertContains('APK', $match['matched_keywords']);
    }
}
