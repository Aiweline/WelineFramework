<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractPatchValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use GuoLaiRen\PageBuilder\Service\AI\Contract\PermissionMatrix;
use GuoLaiRen\PageBuilder\Service\AI\Contract\QaGateHelper;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceContractHelper;
use PHPUnit\Framework\TestCase;

final class ContractCoreServiceTest extends TestCase
{
    public function testMetaBuilderBuildsEveryV1Type(): void
    {
        $types = new ContractType();
        $builder = new ContractMetaBuilder(
            $types,
            static fn(array $seed): string => 'id_' . $seed['type'],
            static fn(): string => '2026-04-30T00:00:00+00:00'
        );

        foreach ($types->v1Types() as $type) {
            $meta = $builder->build($type);

            self::assertSame('id_' . $type, $meta['id']);
            self::assertSame($meta['id'], $meta['contract_id']);
            self::assertSame(ContractType::VERSION_V1, $meta['version']);
            self::assertSame($type, $meta['type']);
            self::assertNotSame('', $meta['stage']);
            self::assertSame(ContractType::STATUS_DRAFT, $meta['status']);
            self::assertSame('2026-04-30T00:00:00+00:00', $meta['created_at']);
        }
    }

    public function testFrozenFieldValidatorRejectsFrozenMutationAndAllowsMutableChange(): void
    {
        $previous = [
            'frozen_fields' => ['page_contract.pages'],
            'page_contract' => [
                'pages' => ['home_page' => ['title' => 'Home']],
            ],
            'mutable_fields' => [
                'notes' => 'before',
            ],
        ];
        $next = $previous;
        $next['mutable_fields']['notes'] = 'after';

        $validator = new ContractPatchValidator();
        self::assertTrue($validator->validate($previous, $next)['valid']);

        $next['page_contract']['pages']['home_page']['title'] = 'Changed';
        $result = $validator->validate($previous, $next);

        self::assertFalse($result['valid']);
        self::assertSame(['Frozen field changed: page_contract.pages'], $result['errors']);
    }

    public function testPermissionMatrixReadOnlyPathRejectsDownstreamMutation(): void
    {
        $matrix = (new PermissionMatrix())->forStage(ContractType::STAGE_STAGE2);
        $previous = [
            'site_brief' => ['site_title' => 'Original'],
            'block_task_contract' => ['tasks' => []],
        ];
        $next = $previous;
        $next['site_brief']['site_title'] = 'Mutated';

        $result = (new ContractPatchValidator())->validate($previous, $next, $matrix);

        self::assertFalse($result['valid']);
        self::assertContains('Read-only field changed: site_brief.*', $result['errors']);
    }

    public function testSourceContractsAndQaGatesAreStructured(): void
    {
        $sourceHelper = new SourceContractHelper();
        $sources = $sourceHelper->normalize([
            'site_brief' => 'contract_site',
            ['id' => 'contract_page', 'type' => ContractType::TYPE_PAGE_CONTRACT, 'status' => ContractType::STATUS_CONFIRMED],
        ]);

        self::assertSame('contract_site', $sources[0]['id']);
        self::assertSame('site_brief', $sources[0]['type']);
        self::assertTrue($sourceHelper->validateRequired([
            'source_contracts' => $sources,
        ], [ContractType::TYPE_PAGE_CONTRACT])['valid']);

        $gates = (new QaGateHelper())->pendingSet(['schema', 'frozen_fields']);
        self::assertSame(QaGateHelper::STATUS_PENDING, $gates['schema']['status']);
        self::assertSame('frozen_fields', $gates['frozen_fields']['key']);
    }
}
