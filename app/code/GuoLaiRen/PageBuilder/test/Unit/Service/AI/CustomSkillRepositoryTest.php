<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\Skill\BuiltinSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillRepository;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillNormalizer;
use PHPUnit\Framework\TestCase;

final class CustomSkillRepositoryTest extends TestCase
{
    public function testSaveRejectsBuiltinCodeBeforeTouchingDatabase(): void
    {
        $repository = new CustomSkillRepository(
            null,
            new BuiltinSkillProvider(new SkillNormalizer()),
            new SkillNormalizer()
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts with a builtin skill');

        $repository->saveFromArray([
            'code' => 'claude-design',
            'name' => 'Override',
            'body' => 'Attempt to override builtin skill.',
        ]);
    }
}
