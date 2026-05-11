<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AI\AiSiteContractAdapterSelector;
use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;
use PHPUnit\Framework\TestCase;

final class AiSiteContractAdapterSelectorTest extends TestCase
{
    public function testStageOneAndBuildPlanUseStrictJsonDefaults(): void
    {
        $selector = new AiSiteContractAdapterSelector();

        $stageOne = $selector->select(ContractType::STAGE_STAGE1, 'planning');
        self::assertSame(AiSiteContractAdapterSelector::ADAPTER_JSON_STRICT, $stageOne['adapter_type']);
        self::assertSame(['type' => 'json_object'], $stageOne['request_params']['response_format']);

        $buildPlan = $selector->select(ContractType::STAGE_BUILD_PLAN, 'build_plan');
        self::assertSame(AiSiteContractAdapterSelector::ADAPTER_JSON_STRICT, $buildPlan['adapter_type']);
        self::assertTrue($buildPlan['request_params']['disable_conversation_history']);
        self::assertTrue($buildPlan['request_params']['disable_conversation_persist']);
    }

    public function testQaMapsToRulesEngineAndAllowsOverrides(): void
    {
        $selected = (new AiSiteContractAdapterSelector())->select(ContractType::STAGE_QA, 'qa', [
            'temperature' => 0,
        ]);

        self::assertSame(AiSiteContractAdapterSelector::ADAPTER_RULES_ENGINE, $selected['adapter_type']);
        self::assertTrue($selected['request_params']['rules_engine']);
        self::assertSame(0, $selected['request_params']['temperature']);
    }
}
