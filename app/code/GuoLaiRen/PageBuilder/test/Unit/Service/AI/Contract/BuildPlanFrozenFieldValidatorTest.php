<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanFrozenFieldValidator;
use PHPUnit\Framework\TestCase;

final class BuildPlanFrozenFieldValidatorTest extends TestCase
{
    public function testBlocksRepairCandidateAgainstBuildPlanFrozenPath(): void
    {
        $validator = new BuildPlanFrozenFieldValidator();

        $result = $validator->validateRepairCandidatePath('tasks.0.executor', [
            'frozen_fields' => ['tasks', 'pages', 'blocks'],
        ]);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('frozen', \strtolower($result['errors'][0]));
    }

    public function testAllowsMutableContentManifestItemPath(): void
    {
        $validator = new BuildPlanFrozenFieldValidator();

        $result = $validator->validateRepairCandidatePath('content_manifest.items.hero.title', [
            'frozen_fields' => ['tasks', 'pages', 'blocks'],
        ]);

        self::assertTrue($result['valid']);
    }
}
