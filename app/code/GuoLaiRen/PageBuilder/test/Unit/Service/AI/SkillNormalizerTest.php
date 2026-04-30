<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillNormalizer;
use PHPUnit\Framework\TestCase;

final class SkillNormalizerTest extends TestCase
{
    public function testEquivalentLineEndingsProduceSameHash(): void
    {
        $normalizer = new SkillNormalizer();

        $left = $normalizer->normalizeBody("Alpha\r\nBeta\r\n");
        $right = $normalizer->normalizeBody("\nAlpha\nBeta\n\n");

        self::assertSame("Alpha\nBeta", $left);
        self::assertSame($left, $right);
        self::assertSame($normalizer->hashBody($left), $normalizer->hashBody($right));
    }

    public function testEmptyAndOversizedBodiesReturnReadableErrors(): void
    {
        $normalizer = new SkillNormalizer();

        try {
            $normalizer->normalizeBody(" \r\n ");
            self::fail('Expected empty body validation error.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be empty', $exception->getMessage());
        }

        try {
            $normalizer->normalizeBody(\str_repeat('x', SkillNormalizer::MAX_BODY_BYTES + 1));
            self::fail('Expected oversized body validation error.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot exceed', $exception->getMessage());
        }
    }
}
