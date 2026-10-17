<?php

declare(strict_types=1);

namespace Weline\Agent\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Agent\Extends\Module\Weline_Framework\Query\AgentQueryProvider;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAgentQueryContractsPass().
 */
final class AgentQueryContractsTest extends TestCase
{
    public function testAllAgentQueryContractsPass(): void
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
            'AgentQueryProviderWriteParams::testSaveRoleDeclaresAdminFormFields' => function () {
            $this->assert_AgentQueryProviderWriteParams_testSaveRoleDeclaresAdminFormFields();
        },
            'AgentQueryProviderWriteParams::testSuggestRoleDeclaresGuideFields' => function () {
            $this->assert_AgentQueryProviderWriteParams_testSuggestRoleDeclaresGuideFields();
        },
            'AgentQueryProviderWriteParams::testSaveScheduleDeclaresAdminFormFields' => function () {
            $this->assert_AgentQueryProviderWriteParams_testSaveScheduleDeclaresAdminFormFields();
        },
            'AgentQueryProviderWriteParams::testSendMessageDeclaresChatConsoleFields' => function () {
            $this->assert_AgentQueryProviderWriteParams_testSendMessageDeclaresChatConsoleFields();
        },
            'AgentQueryProviderWriteParams::testGetChatHistoryDeclaresFields' => function () {
            $this->assert_AgentQueryProviderWriteParams_testGetChatHistoryDeclaresFields();
        },
        ];
    }

private function assert_AgentQueryProviderWriteParams_testSaveRoleDeclaresAdminFormFields(): void
{
    $params = $this->operationParams_AgentQueryProviderWriteParams('saveRole');
    foreach (['payload', 'id', 'code', 'name', 'system_prompt', 'model_id', 'scenario_adapter_code', 'status', 'description', 'icon', 'permissions', 'permissions_text', 'skills', 'model_config', 'model_config_text'] as $field) {
        self::assertArrayHasKey($field, $params, "saveRole missing param: {$field}");
    }
}

private function assert_AgentQueryProviderWriteParams_testSuggestRoleDeclaresGuideFields(): void
{
    $params = $this->operationParams_AgentQueryProviderWriteParams('suggestRole');
    foreach (['payload', 'template_code', 'brief', 'project_count', 'target_outcome', 'workflow_style', 'risk_level', 'agent_profile', 'role_name', 'role_code', 'model_id', 'icon'] as $field) {
        self::assertArrayHasKey($field, $params, "suggestRole missing param: {$field}");
    }
}

private function assert_AgentQueryProviderWriteParams_testSaveScheduleDeclaresAdminFormFields(): void
{
    $params = $this->operationParams_AgentQueryProviderWriteParams('saveSchedule');
    foreach (['payload', 'id', 'role_id', 'name', 'description', 'trigger_expr', 'prompt', 'context', 'status'] as $field) {
        self::assertArrayHasKey($field, $params, "saveSchedule missing param: {$field}");
    }
}

private function assert_AgentQueryProviderWriteParams_testSendMessageDeclaresChatConsoleFields(): void
{
    $params = $this->operationParams_AgentQueryProviderWriteParams('sendMessage');
    foreach (['payload', 'role_code', 'message', 'session_id', 'context_id', 'channel'] as $field) {
        self::assertArrayHasKey($field, $params, "sendMessage missing param: {$field}");
    }
}

private function assert_AgentQueryProviderWriteParams_testGetChatHistoryDeclaresFields(): void
{
    $params = $this->operationParams_AgentQueryProviderWriteParams('getChatHistory');
    foreach (['session_id', 'limit'] as $field) {
        self::assertArrayHasKey($field, $params, "getChatHistory missing param: {$field}");
    }
}

/**
 * @return array<string, mixed>
 */
private function operationParams_AgentQueryProviderWriteParams(string $name): array
{
    $ref = new ReflectionClass(AgentQueryProvider::class);
    /** @var AgentQueryProvider $provider */
    $provider = $ref->newInstanceWithoutConstructor();
    $descriptor = $provider->getDescriptor();
    foreach ($descriptor['operations'] ?? [] as $op) {
        if (($op['name'] ?? '') === $name) {
            return is_array($op['params'] ?? null) ? $op['params'] : [];
        }
    }
    self::fail("operation not found: {$name}");
}
}
