<?php

declare(strict_types=1);

namespace Weline\Agent\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Agent\Extends\Module\Weline_Framework\Query\AgentQueryProvider;

/**
 * Frontend worker rejects undeclared params (Unknown frontend worker param).
 * saveRole / suggestRole / saveSchedule must declare every flat field the admin UI sends.
 */
class AgentQueryProviderWriteParamsContractTest extends TestCase
{
    public function testSaveRoleDeclaresAdminFormFields(): void
    {
        $params = $this->operationParams('saveRole');
        foreach ([
            'payload', 'id', 'code', 'name', 'system_prompt', 'model_id',
            'scenario_adapter_code', 'status', 'description', 'icon',
            'permissions', 'permissions_text', 'skills', 'model_config', 'model_config_text',
        ] as $field) {
            self::assertArrayHasKey($field, $params, "saveRole missing param: {$field}");
        }
    }

    public function testSuggestRoleDeclaresGuideFields(): void
    {
        $params = $this->operationParams('suggestRole');
        foreach ([
            'payload', 'template_code', 'brief', 'project_count', 'target_outcome',
            'workflow_style', 'risk_level', 'agent_profile', 'role_name', 'role_code',
            'model_id', 'icon',
        ] as $field) {
            self::assertArrayHasKey($field, $params, "suggestRole missing param: {$field}");
        }
    }

    public function testSaveScheduleDeclaresAdminFormFields(): void
    {
        $params = $this->operationParams('saveSchedule');
        foreach ([
            'payload', 'id', 'role_id', 'name', 'description',
            'trigger_expr', 'prompt', 'context', 'status',
        ] as $field) {
            self::assertArrayHasKey($field, $params, "saveSchedule missing param: {$field}");
        }
    }

    public function testSendMessageDeclaresChatConsoleFields(): void
    {
        $params = $this->operationParams('sendMessage');
        foreach (['payload', 'role_code', 'message', 'session_id', 'context_id', 'channel'] as $field) {
            self::assertArrayHasKey($field, $params, "sendMessage missing param: {$field}");
        }
    }

    public function testGetChatHistoryDeclaresFields(): void
    {
        $params = $this->operationParams('getChatHistory');
        foreach (['session_id', 'limit'] as $field) {
            self::assertArrayHasKey($field, $params, "getChatHistory missing param: {$field}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function operationParams(string $name): array
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
