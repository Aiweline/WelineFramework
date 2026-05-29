<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\BuildPlanBlockGraphValidator;
use PHPUnit\Framework\TestCase;

final class BuildPlanBlockGraphValidatorTest extends TestCase
{
    public function testValidPageBlockGraphPasses(): void
    {
        $result = (new BuildPlanBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'blocks' => ['home.hero', 'home.games']],
            ],
            'blocks' => [
                ['block_id' => 'home.hero', 'page_id' => 'home'],
                ['block_id' => 'home.games', 'page_id' => 'home'],
            ],
        ]);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsPageReferenceToMissingBlock(): void
    {
        $result = (new BuildPlanBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'blocks' => ['home.hero']],
            ],
            'blocks' => [],
        ]);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'references missing block'));
    }

    public function testRejectsBlockReferenceToMissingPage(): void
    {
        $result = (new BuildPlanBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'blocks' => []],
            ],
            'blocks' => [
                ['block_id' => 'about.hero', 'page_id' => 'about'],
            ],
        ]);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'references missing page'));
    }

    /**
     * @param list<string> $errors
     */
    private function hasErrorContaining(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (\str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
