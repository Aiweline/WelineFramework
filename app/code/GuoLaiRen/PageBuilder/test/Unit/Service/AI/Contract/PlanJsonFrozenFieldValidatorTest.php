<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonFrozenFieldValidator;
use PHPUnit\Framework\TestCase;

final class PlanJsonFrozenFieldValidatorTest extends TestCase
{
    public function testBlocksRepairCandidateAgainstPlanJsonFrozenPath(): void
    {
        $validator = new PlanJsonFrozenFieldValidator();

        $result = $validator->validateRepairCandidatePath('tasks.0.executor', [
            'frozen_fields' => ['tasks', 'pages', 'blocks'],
        ]);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('frozen', \strtolower($result['errors'][0]));
    }

    public function testAllowsMutableContentManifestItemPath(): void
    {
        $validator = new PlanJsonFrozenFieldValidator();

        $result = $validator->validateRepairCandidatePath('content_manifest.items.hero.title', [
            'frozen_fields' => ['tasks', 'pages', 'blocks'],
        ]);

        self::assertTrue($result['valid']);
    }
}
