<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Service\LocalChallengeImage;

final class LocalChallengeImageMarkupTest extends TestCase
{
    public function testMarkupDoesNotEmbedAnswerAsSvgText(): void
    {
        $answer = 'A2B3C4';
        $html = LocalChallengeImage::markup($answer, 'captcha');

        self::assertStringNotContainsString('>' . $answer . '<', $html);
        self::assertDoesNotMatchRegularExpression('/<text\b[^>]*>' . \preg_quote($answer, '/') . '/i', $html);
        self::assertTrue(
            \str_contains($html, 'data:image/png;base64,') || \str_contains($html, '<svg'),
            'Markup must render as PNG img or inline SVG'
        );
        self::assertMatchesRegularExpression('/\b(?:width|viewBox)[^>]*(?:168|0 0 168 40)/', $html);
    }

    public function testStrokeSvgCoversChallengeAlphabet(): void
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        foreach (\str_split($alphabet) as $char) {
            $svg = LocalChallengeImage::inlineSvg($char . $char . $char . $char . $char . $char, 'label');
            self::assertStringContainsString('<path d=', $svg, 'Missing stroke path for ' . $char);
            self::assertStringNotContainsString('<text', $svg);
            self::assertStringContainsString('width="168"', $svg);
            self::assertStringContainsString('height="40"', $svg);
        }
    }
}
