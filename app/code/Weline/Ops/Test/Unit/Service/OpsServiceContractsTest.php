<?php

declare(strict_types=1);

namespace Weline\Ops\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ops\Model\OpsTask;
use Weline\Ops\Queue\OpsTaskDispatchQueue;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllOpsServiceContractsPass().
 */
final class OpsServiceContractsTest extends TestCase
{
    public function testAllOpsServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'OpsDispatchMapping::test_dispatch_service_source_freezes_assignee_and_approval_gates' => function () {
            $this->assert_OpsDispatchMapping_test_dispatch_service_source_freezes_assignee_and_approval_gates();
        },
            'OpsDispatchMapping::test_desk_and_task_templates_allow_retry_approve_on_failed_outbound' => function () {
            $this->assert_OpsDispatchMapping_test_desk_and_task_templates_allow_retry_approve_on_failed_outbound();
        },
            'OpsDispatchMapping::test_queue_consumer_claims_then_execute_agent' => function () {
            $this->assert_OpsDispatchMapping_test_queue_consumer_claims_then_execute_agent();
        },
            'OpsDispatchMapping::test_website_ops_agent_has_dispatch_tool' => function () {
            $this->assert_OpsDispatchMapping_test_website_ops_agent_has_dispatch_tool();
        },
            'OpsLaneDeepLink::test_helper_and_lanes_pass_website_scope' => function () {
            $this->assert_OpsLaneDeepLink_test_helper_and_lanes_pass_website_scope();
        },
            'OpsPersonaService::test_persona_requires_real_ai_and_forbids_description_paste' => function () {
            $this->assert_OpsPersonaService_test_persona_requires_real_ai_and_forbids_description_paste();
        },
            'OpsPersonaService::test_progress_steps_cover_evidence_pipeline' => function () {
            $this->assert_OpsPersonaService_test_progress_steps_cover_evidence_pipeline();
        },
            'OpsProgramDefaultOff::test_new_program_defaults_to_disabled' => function () {
            $this->assert_OpsProgramDefaultOff_test_new_program_defaults_to_disabled();
        },
            'OpsProgramDefaultOff::test_model_column_default_is_zero' => function () {
            $this->assert_OpsProgramDefaultOff_test_model_column_default_is_zero();
        },
            'OpsProgramDefaultOff::test_site_setup_assistant_provider_guides_manual_enable' => function () {
            $this->assert_OpsProgramDefaultOff_test_site_setup_assistant_provider_guides_manual_enable();
        },
            'OpsWebsiteContextReader::test_reader_collects_multi_source_evidence_pack' => function () {
            $this->assert_OpsWebsiteContextReader_test_reader_collects_multi_source_evidence_pack();
        },
        ];
    }

private function assert_OpsDispatchMapping_test_dispatch_service_source_freezes_assignee_and_approval_gates(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Service/OpsDispatchService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('AGENT_BLOG_PUBLISH', $src);
    self::assertStringContainsString('AGENT_SOCIAL_OPS', $src);
    self::assertStringContainsString('IdempotentQueueAdmission', $src);
    self::assertStringContainsString('ops_task:', $src);
    self::assertStringContainsString('createPublishBatch', $src);
    self::assertStringContainsString("'website_id' => \$websiteId", $src);
    self::assertStringContainsString('isApproveEligible', $src);
    self::assertStringContainsString('isTerminalPublishFailure', $src);
    self::assertStringContainsString('STATUS_AWAITING_APPROVAL', $src);
    self::assertStringContainsString('recoverable', $src);
    self::assertStringContainsString('STATUS_PUBLISHED', $src);
    self::assertSame('blog_publish', OpsTask::AGENT_BLOG_PUBLISH);
    self::assertSame('social_ops', OpsTask::AGENT_SOCIAL_OPS);
}

private function assert_OpsDispatchMapping_test_desk_and_task_templates_allow_retry_approve_on_failed_outbound(): void
{
    $desk = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/view/templates/backend/Desk/index.phtml');
    $task = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/view/templates/backend/Task/index.phtml');
    self::assertStringContainsString('canApproveOutbound', $desk);
    self::assertStringContainsString('再次批准外发', $desk);
    self::assertStringContainsString('canApproveOutbound', $task);
    self::assertStringContainsString('再次批准外发', $task);
}

private function assert_OpsDispatchMapping_test_queue_consumer_claims_then_execute_agent(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Queue/OpsTaskDispatchQueue.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('claimRunning', $src);
    self::assertStringContainsString('executeAgent', $src);
    self::assertStringContainsString('STATUS_AWAITING_APPROVAL', $src);
    self::assertTrue(is_a(OpsTaskDispatchQueue::class, \Weline\Queue\Api\QueueConsumerInterface::class, true));
}

private function assert_OpsDispatchMapping_test_website_ops_agent_has_dispatch_tool(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/extends/module/Weline_Ai/Agent/WebsiteOpsAgent.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('accept_ops_dispatch_config', $src);
    self::assertStringContainsString('OpsDispatchService', $src);
}

private function assert_OpsLaneDeepLink_test_helper_and_lanes_pass_website_scope(): void
{
    $helper = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Service/OpsLaneDeepLink.php';
    self::assertFileExists($helper);
    $helperSrc = (string) file_get_contents($helper);
    self::assertStringContainsString("'website_id'", $helperSrc);
    self::assertStringContainsString('scope_key', $helperSrc);
    self::assertStringContainsString('websiteId', $helperSrc);
    self::assertStringContainsString('weline_social/backend/social', $helperSrc);
    self::assertStringNotContainsString("'*/backend/social'", $helperSrc);
    self::assertStringContainsString('visitor/backend/pixel-dashboard', $helperSrc);
    self::assertStringNotContainsString('pixel-dashboard/index', $helperSrc);
    $contentLane = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/extends/module/Weline_Ops/Lane/ContentLane.php';
    $contentSrc = (string) file_get_contents($contentLane);
    self::assertStringContainsString('blog/backend/post-admin/index', $contentSrc);
    self::assertStringNotContainsString('blog/backend/post/index', $contentSrc);
    $lanes = ['/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/extends/module/Weline_Ops/Lane/SiteLane.php', $contentLane, '/Users/weline/Project/Official/框架/app/code/Weline' . '/Seo/extends/module/Weline_Ops/Lane/SeoLane.php', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Social/extends/module/Weline_Ops/Lane/SocialLane.php', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Visitor/extends/module/Weline_Ops/Lane/VisitorLane.php', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Marketing/extends/module/Weline_Ops/Lane/GrowthLane.php', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Promotion/extends/module/Weline_Ops/Lane/PromotionLane.php'];
    foreach ($lanes as $path) {
        self::assertFileExists($path, $path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('OpsLaneDeepLink', $src, $path);
        self::assertStringNotContainsString("getBackendUrl('seo/backend/account/index')", $src, $path . ' must not drop website scope');
    }
}

private function assert_OpsPersonaService_test_persona_requires_real_ai_and_forbids_description_paste(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Service/OpsPersonaService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString('$summary = $description', $src);
    self::assertStringNotContainsString('inferHeuristically', $src);
    self::assertStringNotContainsString('ops_persona_heuristic', $src);
    self::assertStringNotContainsString('buildOperationalSummary', $src);
    self::assertStringContainsString('inferViaAi', $src);
    self::assertStringContainsString('validateAiPersona', $src);
    self::assertStringContainsString('containsLongDescriptionChunk', $src);
    self::assertStringContainsString('resolvePersonaModelCode', $src);
    self::assertStringContainsString('isLocalOllamaSupplier', $src);
    self::assertStringContainsString('ops_persona_ai_v4', $src);
    self::assertStringContainsString('generateFromContextStreaming', $src);
    self::assertStringContainsString('EVIDENCE', $src);
    self::assertStringContainsString('LOW_TRUST_BLURB', $src);
    self::assertStringContainsString('deepseek-flash', $src);
    self::assertStringContainsString('禁止回退为网站简介或启发式模板', $src);
    self::assertStringContainsString('AiRuntimeInterface', $src);
    self::assertStringNotContainsString('ops_persona_ai_v3', $src);
}

private function assert_OpsPersonaService_test_progress_steps_cover_evidence_pipeline(): void
{
    $keys = array_column(\Weline\Ops\Service\OpsPersonaService::progressSteps(), 'key');
    foreach (['site', 'catalog', 'products', 'policy', 'market', 'compress', 'ai', 'persist'] as $key) {
        self::assertContains($key, $keys);
    }
}

private function assert_OpsProgramDefaultOff_test_new_program_defaults_to_disabled(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Service/OpsProgramService.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('schema_fields_ENABLED, 0', $src);
    self::assertStringContainsString('新建程序必须关闭', $src);
    self::assertStringContainsString('function isEnabled', $src);
}

private function assert_OpsProgramDefaultOff_test_model_column_default_is_zero(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Model/OpsWebsiteProgram.php';
    $src = (string) file_get_contents($path);
    self::assertMatchesRegularExpression("/#\\[Col\\([^\\]]*default:\\s*0[^\\]]*是否启用[^\\]]*\\)\\][\\s\\S]{0,80}schema_fields_ENABLED\\s*=\\s*'enabled'/u", $src);
}

private function assert_OpsProgramDefaultOff_test_site_setup_assistant_provider_guides_manual_enable(): void
{
    $provider = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/extends/module/Weline_SiteSetupAssistant/SetupTask/OpsSetupTaskProvider.php';
    self::assertFileExists($provider);
    $src = (string) file_get_contents($provider);
    self::assertStringContainsString('extends AbstractSetupTaskProvider', $src);
    self::assertStringContainsString('ops_ai_program_enable', $src);
    self::assertStringContainsString('ops_ai_program_policy', $src);
    self::assertStringContainsString('default_off', $src);
    self::assertStringContainsString('手动开启 AI运营', $src);
    self::assertStringContainsString('weline_ops/backend/desk/index', $src);
    $extends = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/extends.php');
    self::assertStringContainsString('SetupTaskProviderInterface::class', $extends);
    self::assertStringContainsString('OpsSetupTaskProvider::class', $extends);
}

private function assert_OpsWebsiteContextReader_test_reader_collects_multi_source_evidence_pack(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Ops' . '/Service/OpsWebsiteContextReader.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('site_facts', $src);
    self::assertStringContainsString('evidence_gaps', $src);
    self::assertStringContainsString('StorefrontCategoryTreeIndex', $src);
    self::assertStringContainsString('PolicyDocumentDefaults', $src);
    self::assertStringContainsString('getWebsiteLanguageCodes', $src);
    self::assertStringContainsString('STATUS_PUBLISHED', $src);
    self::assertStringContainsString('FaqItem', $src);
    self::assertStringNotContainsString('changanhanfu', $src);
    self::assertStringNotContainsString('daocharms', $src);
}
}
