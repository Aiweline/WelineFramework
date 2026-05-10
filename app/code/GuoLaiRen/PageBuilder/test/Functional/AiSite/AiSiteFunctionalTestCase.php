<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthCoverageLinter;
use PHPUnit\Framework\TestCase;

abstract class AiSiteFunctionalTestCase extends TestCase
{
    /**
     * @return array{
     *   brief:string,
     *   instruction?:string,
     *   page_types?:list<string>,
     *   expected_locale?:string,
     *   expected_keywords?:list<string>
     * }
     */
    abstract protected function getTestCaseData(): array;

    protected function buildSourceTruth(): SourceTruthContractBuilder
    {
        return new SourceTruthContractBuilder();
    }

    protected function buildContractValidator(): SourceTruthContractValidator
    {
        return new SourceTruthContractValidator();
    }

    protected function buildCoverageLinter(): SourceTruthCoverageLinter
    {
        return new SourceTruthCoverageLinter();
    }

    public function testSourceTruthContracts(): void
    {
        $data = $this->getTestCaseData();
        $builder = $this->buildSourceTruth();
        $contract = $builder->build(
            ['brief_description' => $data['brief']],
            [],
            [],
            $data['instruction'] ?? '',
            $data['page_types'] ?? ['home_page'],
            $data['expected_locale'] ?? 'en_US'
        );

        $validator = $this->buildContractValidator();
        $result = $validator->validate($contract);
        self::assertTrue($result['valid'], \implode("\n", $result['errors']));

        self::assertNotEmpty($contract['must_include_facts']);
        self::assertNotEmpty($contract['conversion_goals']);
    }

    public function testSourceTruthCoverageLint(): void
    {
        $data = $this->getTestCaseData();
        $builder = $this->buildSourceTruth();
        $contract = $builder->build(
            ['brief_description' => $data['brief']],
            [],
            [],
            $data['instruction'] ?? '',
            $data['page_types'] ?? ['home_page'],
            $data['expected_locale'] ?? 'en_US'
        );

        $factLine = '';
        foreach (\is_array($contract['must_include_facts'] ?? null) ? $contract['must_include_facts'] : [] as $fact) {
            if (\is_array($fact)) {
                $factLine .= ' ' . (string)($fact['text'] ?? '');
            }
        }

        $requiredBlocks = \is_array($contract['required_home_blocks'] ?? null) ? $contract['required_home_blocks'] : [];
        $blocks = [];
        foreach ($requiredBlocks as $rk) {
            $rk = (string)$rk;
            $blocks[] = [
                'block_key' => $rk . '_primary',
                'goal' => $rk,
                'content' => $factLine,
                'field_plan' => [['sample' => $data['brief']]],
                'execution_script' => ['core_copy' => $factLine],
            ];
        }
        if ($blocks === []) {
            $blocks[] = [
                'block_key' => 'hero_download_main',
                'goal' => 'Hero',
                'content' => $factLine,
                'field_plan' => [['sample' => $data['brief']]],
                'execution_script' => ['core_copy' => $factLine],
            ];
        }

        $pagePlan = [
            'page_goal' => $factLine,
            'theme_alignment_summary' => $data['brief'],
        ];
        $blockPlans = [
            'blocks' => $blocks,
        ];

        $lint = $this->buildCoverageLinter()->lint($contract, $pagePlan, $blockPlans);
        self::assertGreaterThanOrEqual(0.95, $lint['coverage']);
        self::assertSame([], $lint['missing_facts']);
    }
}
