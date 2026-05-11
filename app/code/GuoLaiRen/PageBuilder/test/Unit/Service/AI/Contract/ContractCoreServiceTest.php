<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractMetaBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractPatchValidator;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractQaReportBuilder;
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
        $matrix = (new PermissionMatrix())->forStage(ContractType::STAGE_BUILD_PLAN);
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

    public function testQaReportSeparatesContractViolationsFromContentQuality(): void
    {
        $report = (new ContractQaReportBuilder(
            new ContractMetaBuilder(
                null,
                static fn(array $seed): string => 'id_' . $seed['type'] . '_' . $seed['status'],
                static fn(): string => '2026-04-30T00:00:00+00:00'
            )
        ))->build([
            ContractType::TYPE_RENDER_DATA => [
                'contract_meta' => [
                    'id' => 'contract_render',
                    'type' => ContractType::TYPE_RENDER_DATA,
                    'version' => ContractType::VERSION_V1,
                    'status' => ContractType::STATUS_DRAFT,
                ],
                'source_contracts' => [
                    [
                        'id' => 'contract_block_task',
                        'type' => ContractType::TYPE_BLOCK_TASK_CONTRACT,
                        'version' => ContractType::VERSION_V1,
                        'status' => ContractType::STATUS_CONFIRMED,
                    ],
                ],
                'payload' => [],
            ],
        ], [
            ContractType::TYPE_RENDER_DATA => [
                ContractType::TYPE_BLOCK_TASK_CONTRACT,
                ContractType::TYPE_BLOCK_PLAN,
            ],
        ]);

        self::assertSame(ContractType::TYPE_QA_REPORT, $report['contract_meta']['type']);
        self::assertSame(ContractType::STATUS_FAILED, $report['contract_meta']['status']);
        self::assertSame(QaGateHelper::STATUS_FAIL, $report['payload']['status']);
        self::assertSame(QaGateHelper::STATUS_FAIL, $report['qa_gates']['source_contracts']['status']);
        self::assertSame(QaGateHelper::STATUS_PENDING, $report['qa_gates']['content_quality']['status']);
        self::assertSame('not_evaluated', $report['payload']['content_quality']['status']);
        self::assertSame('source_contracts', $report['payload']['findings'][0]['category']);
        self::assertStringContainsString(ContractType::TYPE_BLOCK_PLAN, $report['payload']['findings'][0]['message']);
    }

    public function testQaReportDetectsFrozenFieldMutation(): void
    {
        $previous = [
            'contract_meta' => [
                'id' => 'contract_render',
                'type' => ContractType::TYPE_RENDER_DATA,
                'version' => ContractType::VERSION_V1,
            ],
            'frozen_fields' => ['payload.page_type_layouts'],
            'payload' => [
                'page_type_layouts' => [
                    'home_page' => ['content' => [['code' => 'hero']]],
                ],
            ],
        ];
        $next = $previous;
        $next['payload']['page_type_layouts']['home_page']['content'][0]['code'] = 'changed-hero';

        $report = (new ContractQaReportBuilder())->build([
            ContractType::TYPE_RENDER_DATA => $next,
        ], [], [
            ContractType::TYPE_RENDER_DATA => $previous,
        ]);

        self::assertSame(QaGateHelper::STATUS_FAIL, $report['payload']['status']);
        self::assertSame(QaGateHelper::STATUS_FAIL, $report['qa_gates']['frozen_fields']['status']);
        self::assertSame('frozen_fields', $report['payload']['findings'][0]['category']);
        self::assertStringContainsString('payload.page_type_layouts', $report['payload']['findings'][0]['message']);
    }

    public function testQaReportCarriesContentQualityFindingsSeparately(): void
    {
        $report = (new ContractQaReportBuilder())->build([], [], [], [
            [
                'severity' => 'warning',
                'category' => 'copy',
                'rule' => 'copy.generic_or_placeholder',
                'message' => 'Section copy looks generic.',
                'target_path' => 'payload.page_type_layouts.home_page.content.0',
            ],
        ]);

        self::assertSame(QaGateHelper::STATUS_WARN, $report['payload']['status']);
        self::assertSame(QaGateHelper::STATUS_PASS, $report['payload']['contract_quality']['status']);
        self::assertSame(QaGateHelper::STATUS_WARN, $report['payload']['content_quality']['status']);
        self::assertSame([], $report['payload']['findings']);
        self::assertSame('copy', $report['payload']['content_quality']['findings'][0]['category']);
        self::assertSame(QaGateHelper::STATUS_WARN, $report['qa_gates']['content_quality']['status']);
    }
}
