<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\PlanJsonBlockGraphValidator;
use PHPUnit\Framework\TestCase;

final class PlanJsonBlockGraphValidatorTest extends TestCase
{
    public function testValidPageBlockGraphPasses(): void
    {
        $result = (new PlanJsonBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'block_node_ids' => ['home.hero', 'home.games']],
            ],
            'block_nodes' => [
                ['block_id' => 'home.hero', 'page_id' => 'home'],
                ['block_id' => 'home.games', 'page_id' => 'home'],
            ],
        ]);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
    }

    public function testRejectsPageReferenceToMissingBlock(): void
    {
        $result = (new PlanJsonBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'block_node_ids' => ['home.hero']],
            ],
            'block_nodes' => [],
        ]);

        self::assertFalse($result['valid']);
        self::assertTrue($this->hasErrorContaining($result['errors'], 'references missing block'));
    }

    public function testRejectsBlockReferenceToMissingPage(): void
    {
        $result = (new PlanJsonBlockGraphValidator())->validate([
            'pages' => [
                ['page_id' => 'home', 'block_node_ids' => []],
            ],
            'block_nodes' => [
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
