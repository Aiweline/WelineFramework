<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonNoReasonLinter;
use PHPUnit\Framework\TestCase;

final class PlanJsonNoReasonLinterTest extends TestCase
{
    public function testAllowsExecutableFieldsWithoutExplanationKeys(): void
    {
        $result = (new PlanJsonNoReasonLinter())->validate([
            'pages' => [
                'home_page' => [
                    'hero' => ['acceptance_rule_ids' => ['layout.4_8_spacing']],
                ],
            ],
        ]);

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
    }

    public function testRejectsNestedReasonFields(): void
    {
        $result = (new PlanJsonNoReasonLinter())->validate([
            'pages' => [
                'home_page' => [
                    'hero' => [
                    'block_id' => 'home.hero',
                    'design_reason' => 'Because it looks premium.',
                ],
                ],
            ],
        ]);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('Forbidden explanatory field', $result['errors'][0]);
        self::assertStringContainsString('design_reason', $result['errors'][0]);
    }
}
