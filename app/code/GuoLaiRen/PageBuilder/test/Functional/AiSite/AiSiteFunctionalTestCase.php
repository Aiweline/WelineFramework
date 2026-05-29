<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Functional\AiSite;

use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractValidator;
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

}
