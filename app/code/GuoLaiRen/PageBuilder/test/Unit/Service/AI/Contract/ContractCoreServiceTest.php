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
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractBuilder;
use GuoLaiRen\PageBuilder\Service\AI\Contract\SourceTruthContractValidator;
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
        $matrix = (new PermissionMatrix())->forStage(ContractType::STAGE_PLAN_JSON);
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

    public function testPermissionMatrixPatchRulesRejectNonPatchableMutation(): void
    {
        $previous = [
            'pages' => [['page_id' => 'home']],
            'content_manifest' => ['items' => ['home.title' => 'Before']],
            'qa_gates' => ['schema_valid' => ['status' => 'pending']],
        ];
        $next = $previous;
        $next['pages'][0]['page_id'] = 'changed-home';

        $result = (new ContractPatchValidator())->validate($previous, $next, [
            'patch' => ['content_manifest.items.*', 'qa_gates.*'],
            'forbidden' => ['pages', 'blocks', 'tasks'],
        ]);

        self::assertFalse($result['valid']);
        self::assertContains('Forbidden field changed: pages.0.page_id', $result['errors']);
        self::assertContains('Path is not patchable by permission_matrix: pages.0.page_id', $result['errors']);
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

    public function testSourceTruthContractUsesUnifiedShellAndPassesValidation(): void
    {
        $contract = (new SourceTruthContractBuilder())->build(
            ['site_title' => 'Teenipiya', 'brief_description' => 'Explain refund rules clearly for visitors.'],
            ['site_title' => 'Teenipiya'],
            [],
            '',
            ['refund_policy'],
            'en_US'
        );

        $result = (new SourceTruthContractValidator())->validate($contract);

        self::assertTrue($result['valid'], \implode("\n", $result['errors']));
        self::assertSame(ContractType::TYPE_SOURCE_TRUTH, $contract['contract_meta']['type']);
        self::assertSame(ContractType::STAGE_STAGE1, $contract['contract_meta']['stage']);
        self::assertArrayHasKey('schema_shape', $contract['qa_gates']);
        self::assertSame([], $contract['source_contracts']);
    }

    public function testSourceTruthContractTreatsAvoidedTermsAsForbiddenNotMustInclude(): void
    {
        $contract = (new SourceTruthContractBuilder())->build(
            [
                'site_title' => 'OpsFlow AI',
                'brief_description' => 'Build a polished AI workflow automation SaaS website for operations teams. The service helps teams design approval flows, automate task handoffs, monitor status, and book a product demo. Keep the visual direction professional, clean, credible, and product-led. Avoid gaming, casino, APK, reward, card, neon, and gambling visual language.',
            ],
            ['site_title' => 'OpsFlow AI'],
            [],
            '',
            ['home_page'],
            'en_US'
        );

        $facts = \json_encode($contract['must_include_facts'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $forbidden = \json_encode($contract['must_not_do'], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        self::assertIsString($facts);
        self::assertStringNotContainsString('casino', $facts);
        self::assertStringNotContainsString('APK', $facts);
        self::assertStringNotContainsString('reward', $facts);
        self::assertStringNotContainsString('neon', $facts);
        self::assertStringContainsString('Build a polished AI workflow automation SaaS website for operations teams', $facts);
        self::assertStringContainsString('design approval flows, automate task handoffs, monitor status, and book a product demo', $facts);
        self::assertStringNotContainsString('"text":"automate task handoffs"', $facts);
        self::assertStringNotContainsString('"text":"monitor status"', $facts);
        self::assertStringNotContainsString('"text":"clean"', $facts);
        self::assertStringNotContainsString('"text":"credible"', $facts);
        self::assertStringNotContainsString('Keep the visual direction', $facts);
        self::assertIsString($forbidden);
        self::assertStringContainsString('casino', $forbidden);
        self::assertStringContainsString('APK', $forbidden);
        self::assertNotContains('hero_download', $contract['required_home_blocks']);
        self::assertNotContains('Drive APK/app download click', $contract['conversion_goals']);
    }

    public function testQaReportSeparatesContractViolationsFromStructureQuality(): void
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
                ContractType::TYPE_PAGE_CONTRACT,
            ],
        ]);

        self::assertSame(ContractType::TYPE_QA_REPORT, $report['contract_meta']['type']);
        self::assertSame(ContractType::STATUS_FAILED, $report['contract_meta']['status']);
        self::assertSame(QaGateHelper::STATUS_FAIL, $report['payload']['status']);
        self::assertSame(QaGateHelper::STATUS_FAIL, $report['qa_gates']['source_contracts']['status']);
        self::assertSame(QaGateHelper::STATUS_PASS, $report['qa_gates']['structure_quality']['status']);
        self::assertSame(QaGateHelper::STATUS_PASS, $report['payload']['structure_quality']['status']);
        self::assertSame('source_contracts', $report['payload']['findings'][0]['category']);
        self::assertStringContainsString(ContractType::TYPE_PAGE_CONTRACT, $report['payload']['findings'][0]['message']);
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

    public function testQaReportCarriesStructureQualityFindingsSeparately(): void
    {
        $report = (new ContractQaReportBuilder())->build([], [], [], [
            [
                'severity' => 'warning',
                'category' => 'structure',
                'rule' => 'structure.missing_section_identity',
                'message' => 'Section code is missing.',
                'target_path' => 'payload.page_type_layouts.home_page.content.0',
            ],
        ]);

        self::assertSame(QaGateHelper::STATUS_WARN, $report['payload']['status']);
        self::assertSame(QaGateHelper::STATUS_PASS, $report['payload']['contract_quality']['status']);
        self::assertSame(QaGateHelper::STATUS_WARN, $report['payload']['structure_quality']['status']);
        self::assertSame([], $report['payload']['findings']);
        self::assertSame('structure', $report['payload']['structure_quality']['findings'][0]['category']);
        self::assertSame(QaGateHelper::STATUS_WARN, $report['qa_gates']['structure_quality']['status']);
    }
}
