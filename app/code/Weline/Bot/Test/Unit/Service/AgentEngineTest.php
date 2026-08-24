<?php

declare(strict_types=1);

namespace Weline\Bot\Test\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Service\AiService;
use Weline\Bot\Interface\SkillInterface;
use Weline\Bot\Model\BotChatMessage;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Model\BotSkill;
use Weline\Bot\Model\BotToolCall;
use Weline\Bot\Service\AgentEngine;
use Weline\Bot\Service\ChatSessionManager;
use Weline\Bot\Service\ContextBuilder;
use Weline\Bot\Service\MemoryService;
use Weline\Bot\Service\PermissionValidator;
use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillPackageManager;
use Weline\Bot\Service\SkillResult;

final class AgentEngineTestSkill implements SkillInterface
{
    public function getCode(): string
    {
        return 'demo.skill';
    }

    public function getName(): string
    {
        return 'Demo Skill';
    }

    public function getDescription(): string
    {
        return 'Test helper skill.';
    }

    public function getCategory(): string
    {
        return 'api';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'integer'],
            ],
        ];
    }

    public function getPermissionRequired(): array
    {
        return [];
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        return SkillResult::success([
            'handled' => true,
            'value' => $params['value'] ?? null,
            'session_id' => $context->session->getId(),
        ]);
    }

    public function isDangerous(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

final class AgentEngineTest extends TestCase
{
    /** @var AiService&MockObject */
    private AiService $aiService;

    /** @var ChatSessionManager&MockObject */
    private ChatSessionManager $sessionManager;

    /** @var MemoryService&MockObject */
    private MemoryService $memoryService;

    /** @var SkillPackageManager&MockObject */
    private SkillPackageManager $skillManager;

    /** @var PermissionValidator&MockObject */
    private PermissionValidator $permissionValidator;

    /** @var ContextBuilder&MockObject */
    private ContextBuilder $contextBuilder;

    /** @var BotToolCall&MockObject */
    private BotToolCall $toolCallModel;

    private AgentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aiService = $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateStructured'])
            ->getMock();

        $this->sessionManager = $this->getMockBuilder(ChatSessionManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createSession', 'addMessage'])
            ->getMock();

        $this->memoryService = $this->getMockBuilder(MemoryService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRelevantMemories', 'extractAndSave'])
            ->getMock();

        $this->skillManager = $this->getMockBuilder(SkillPackageManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getToolsForRole', 'getSkill'])
            ->getMock();

        $this->permissionValidator = $this->getMockBuilder(PermissionValidator::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['validate'])
            ->getMock();

        $this->contextBuilder = $this->getMockBuilder(ContextBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['build'])
            ->getMock();

        $this->toolCallModel = $this->getMockBuilder(BotToolCall::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'setArguments', 'save', 'markRunning', 'markSuccess', 'markFailed'])
            ->getMock();

        $this->toolCallModel->method('setData')->willReturnSelf();
        $this->toolCallModel->method('setArguments')->willReturnSelf();
        $this->toolCallModel->method('save')->willReturn(true);
        $this->toolCallModel->method('markRunning')->willReturnSelf();
        $this->toolCallModel->method('markSuccess')->willReturnSelf();
        $this->toolCallModel->method('markFailed')->willReturnSelf();

        $this->engine = new AgentEngine(
            aiService: $this->aiService,
            sessionManager: $this->sessionManager,
            memoryService: $this->memoryService,
            skillManager: $this->skillManager,
            permissionValidator: $this->permissionValidator,
            contextBuilder: $this->contextBuilder,
            toolCallModel: $this->toolCallModel,
        );
    }

    public function testExecuteReturnsAssistantContentWithoutToolCalls(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);
        $messages = [
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user', 'content' => 'old'],
        ];
        $tools = [
            [
                'name' => 'demo.read',
                'description' => 'Read data',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ];

        $capturedWrites = [];

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->with('ctx-1', 5)
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->once())
            ->method('build')
            ->with($session, $role)
            ->willReturn($messages);

        $this->skillManager
            ->expects($this->once())
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn($tools);

        $this->sessionManager
            ->expects($this->exactly(2))
            ->method('addMessage')
            ->willReturnCallback(function (
                BotChatSession $passedSession,
                string $messageRole,
                string $content,
                array $toolCalls = [],
                string $toolName = '',
                string $toolCallId = ''
            ) use (&$capturedWrites, $messageRecord) {
                $capturedWrites[] = [
                    'role' => $messageRole,
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                ];

                return $messageRecord;
            });

        $this->aiService
            ->expects($this->once())
            ->method('generateStructured')
            ->with(
                '',
                null,
                'bot_agent',
                $this->callback(function (array $params) use ($tools): bool {
                    $this->assertSame($tools, $params['tools']);
                    $this->assertSame(0.25, $params['temperature']);
                    $this->assertSame(2048, $params['max_tokens']);
                    $this->assertTrue($params['is_backend']);
                    $this->assertCount(2, $params['messages']);
                    $this->assertSame('system', $params['messages'][0]['role']);
                    $this->assertSame('user', $params['messages'][1]['role']);
                    $this->assertStringContainsString('Original prompt', $params['messages'][1]['content']);
                    return true;
                })
            )
            ->willReturn([
                'content' => 'Final reply',
                'tool_calls' => [],
                'model' => 'mock-model',
            ]);

        $this->memoryService
            ->expects($this->once())
            ->method('extractAndSave')
            ->with($this->isInstanceOf(AgentResult::class), $session);

        $result = $this->engine->execute('Original prompt', $role, $session);

        $this->assertTrue($result->success, (string)$result->error);
        $this->assertSame('Final reply', $result->content);
        $this->assertSame('role-code', $result->agentCode);
        $this->assertSame('mock-model', $result->modelCode);
        $this->assertCount(2, $capturedWrites);
        $this->assertSame(BotChatMessage::ROLE_USER, $capturedWrites[0]['role']);
        $this->assertSame('Original prompt', $capturedWrites[0]['content']);
        $this->assertSame(BotChatMessage::ROLE_ASSISTANT, $capturedWrites[1]['role']);
        $this->assertSame('Final reply', $capturedWrites[1]['content']);
    }

    public function testExecuteProcessesToolLoopAndPersistsAssistantToolRequest(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);
        $skillModel = $this->createMock(BotSkill::class);
        $tools = [
            [
                'name' => 'demo.skill',
                'description' => 'Demo skill',
                'parameters' => ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']]],
            ],
        ];
        $assistantToolCalls = [[
            'id' => 'tool-call-1',
            'type' => 'function',
            'function' => [
                'name' => 'demo.skill',
                'arguments' => '{"value":42}',
            ],
        ]];
        $capturedWrites = [];
        $buildInvocation = 0;
        $aiInvocation = 0;

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->with('ctx-1', 5)
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->exactly(2))
            ->method('build')
            ->willReturnCallback(function () use (&$buildInvocation, $assistantToolCalls): array {
                $buildInvocation++;

                if ($buildInvocation === 1) {
                    return [
                        ['role' => 'system', 'content' => 'sys'],
                        ['role' => 'user', 'content' => 'old'],
                    ];
                }

                return [
                    ['role' => 'system', 'content' => 'sys'],
                    ['role' => 'user', 'content' => 'Adapted'],
                    ['role' => 'assistant', 'content' => '', 'tool_calls' => $assistantToolCalls],
                    ['role' => 'tool', 'content' => '{"handled":true,"value":42}', 'tool_call_id' => 'tool-call-1'],
                ];
            });

        $this->skillManager
            ->expects($this->exactly(2))
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn($tools);

        $skillModel
            ->method('getData')
            ->willReturnCallback(static function (string $field): mixed {
                if ($field === BotSkill::schema_fields_CLASS_NAME) {
                    return AgentEngineTestSkill::class;
                }

                return null;
            });

        $this->skillManager
            ->expects($this->once())
            ->method('getSkill')
            ->with('demo.skill')
            ->willReturn($skillModel);

        $this->permissionValidator
            ->expects($this->once())
            ->method('validate')
            ->with('demo.skill', ['value' => 42], $role)
            ->willReturn(true);

        $this->sessionManager
            ->expects($this->exactly(4))
            ->method('addMessage')
            ->willReturnCallback(function (
                BotChatSession $passedSession,
                string $messageRole,
                string $content,
                array $toolCalls = [],
                string $toolName = '',
                string $toolCallId = ''
            ) use (&$capturedWrites, $messageRecord) {
                $capturedWrites[] = [
                    'role' => $messageRole,
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                ];

                return $messageRecord;
            });

        $this->aiService
            ->expects($this->exactly(2))
            ->method('generateStructured')
            ->willReturnCallback(function (
                string $prompt,
                ?string $modelCode,
                ?string $scenarioCode,
                array $params
            ) use (&$aiInvocation, $tools, $assistantToolCalls): array {
                $aiInvocation++;
                $this->assertSame('', $prompt);
                $this->assertNull($modelCode);
                $this->assertSame('bot_agent', $scenarioCode);
                $this->assertSame($tools, $params['tools']);

                if ($aiInvocation === 1) {
                    $this->assertCount(2, $params['messages']);
                    return [
                        'content' => '',
                        'tool_calls' => [[
                            'id' => 'tool-call-1',
                            'name' => 'demo.skill',
                            'arguments' => ['value' => 42],
                        ]],
                        'assistant_message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => $assistantToolCalls,
                        ],
                    ];
                }

                $this->assertCount(4, $params['messages']);
                $this->assertSame('tool', $params['messages'][3]['role']);
                return [
                    'content' => 'Tool loop final reply',
                    'tool_calls' => [],
                    'assistant_message' => [
                        'role' => 'assistant',
                        'content' => 'Tool loop final reply',
                    ],
                ];
            });

        $this->memoryService
            ->expects($this->once())
            ->method('extractAndSave')
            ->with($this->callback(static function (AgentResult $result): bool {
                return $result->content === 'Tool loop final reply' && $result->iterations === 1;
            }), $session);

        $result = $this->engine->execute('Need a tool', $role, $session);

        $this->assertTrue($result->success, (string)$result->error);
        $this->assertSame('Tool loop final reply', $result->content);
        $this->assertSame(1, $result->iterations);
        $this->assertCount(4, $capturedWrites);
        $this->assertSame(BotChatMessage::ROLE_USER, $capturedWrites[0]['role']);
        $this->assertSame(BotChatMessage::ROLE_ASSISTANT, $capturedWrites[1]['role']);
        $this->assertSame($assistantToolCalls, $capturedWrites[1]['tool_calls']);
        $this->assertSame(BotChatMessage::ROLE_TOOL, $capturedWrites[2]['role']);
        $this->assertSame('demo.skill', $capturedWrites[2]['tool_name']);
        $this->assertSame('tool-call-1', $capturedWrites[2]['tool_call_id']);
        $this->assertStringContainsString('"handled":true', $capturedWrites[2]['content']);
        $this->assertSame(BotChatMessage::ROLE_ASSISTANT, $capturedWrites[3]['role']);
        $this->assertSame('Tool loop final reply', $capturedWrites[3]['content']);
    }

    public function testExecuteHumanizesInternalContractErrors(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->once())
            ->method('build')
            ->willReturn([
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'old'],
            ]);

        $this->skillManager
            ->expects($this->once())
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn([]);

        $this->sessionManager
            ->expects($this->once())
            ->method('addMessage')
            ->with($session, BotChatMessage::ROLE_USER, 'Prompt', [], '', '')
            ->willReturn($messageRecord);

        $this->aiService
            ->expects($this->once())
            ->method('generateStructured')
            ->willThrowException(new RuntimeException('QueryProviderInterface mismatch in legacy executeAgent() path'));

        $this->memoryService
            ->expects($this->never())
            ->method('extractAndSave');

        $result = $this->engine->execute('Prompt', $role, $session);

        $this->assertFalse($result->success);
        $this->assertSame(
            'The AI assistant is not fully configured yet. Please configure an AI model and provider account first.',
            $result->error
        );
    }

    public function testExecuteDegradesWhenToolCallCannotBeCompleted(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);
        $tools = [[
            'name' => 'demo.skill',
            'description' => 'Demo skill',
            'parameters' => ['type' => 'object', 'properties' => []],
        ]];
        $assistantToolCalls = [[
            'id' => 'tool-call-fail',
            'type' => 'function',
            'function' => [
                'name' => 'demo.skill',
                'arguments' => '{"value":7}',
            ],
        ]];
        $capturedWrites = [];

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->once())
            ->method('build')
            ->willReturn([
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'old'],
            ]);

        $this->skillManager
            ->expects($this->once())
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn($tools);

        $this->skillManager
            ->expects($this->once())
            ->method('getSkill')
            ->with('demo.skill')
            ->willReturn(null);

        $this->permissionValidator
            ->expects($this->never())
            ->method('validate');

        $this->sessionManager
            ->expects($this->exactly(2))
            ->method('addMessage')
            ->willReturnCallback(function (
                BotChatSession $passedSession,
                string $messageRole,
                string $content,
                array $toolCalls = [],
                string $toolName = '',
                string $toolCallId = ''
            ) use (&$capturedWrites, $messageRecord) {
                $capturedWrites[] = [
                    'role' => $messageRole,
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                ];

                return $messageRecord;
            });

        $this->aiService
            ->expects($this->once())
            ->method('generateStructured')
            ->willReturn([
                'content' => '',
                'tool_calls' => [[
                    'id' => 'tool-call-fail',
                    'name' => 'demo.skill',
                    'arguments' => ['value' => 7],
                ]],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $assistantToolCalls,
                ],
            ]);

        $this->memoryService
            ->expects($this->never())
            ->method('extractAndSave');

        $result = $this->engine->execute('Need unavailable tool', $role, $session);

        $this->assertFalse($result->success);
        $this->assertSame(
            'The required tool call could not be completed with the current role skills or permissions.',
            $result->error
        );
        $this->assertCount(2, $capturedWrites);
        $this->assertSame(BotChatMessage::ROLE_USER, $capturedWrites[0]['role']);
        $this->assertSame(BotChatMessage::ROLE_ASSISTANT, $capturedWrites[1]['role']);
        $this->assertSame($assistantToolCalls, $capturedWrites[1]['tool_calls']);
    }

    public function testExecuteDegradesWhenPermissionValidatorRejectsToolCall(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);
        $skillModel = $this->createMock(BotSkill::class);
        $tools = [[
            'name' => 'demo.skill',
            'description' => 'Demo skill',
            'parameters' => ['type' => 'object', 'properties' => []],
        ]];
        $assistantToolCalls = [[
            'id' => 'tool-call-denied',
            'type' => 'function',
            'function' => [
                'name' => 'demo.skill',
                'arguments' => '{"value":99}',
            ],
        ]];
        $capturedWrites = [];

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->once())
            ->method('build')
            ->willReturn([
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'old'],
            ]);

        $this->skillManager
            ->expects($this->once())
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn($tools);

        $this->skillManager
            ->expects($this->once())
            ->method('getSkill')
            ->with('demo.skill')
            ->willReturn($skillModel);

        $this->permissionValidator
            ->expects($this->once())
            ->method('validate')
            ->with('demo.skill', ['value' => 99], $role)
            ->willReturn(false);

        $this->sessionManager
            ->expects($this->exactly(2))
            ->method('addMessage')
            ->willReturnCallback(function (
                BotChatSession $passedSession,
                string $messageRole,
                string $content,
                array $toolCalls = [],
                string $toolName = '',
                string $toolCallId = ''
            ) use (&$capturedWrites, $messageRecord) {
                $capturedWrites[] = [
                    'role' => $messageRole,
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'tool_name' => $toolName,
                    'tool_call_id' => $toolCallId,
                ];

                return $messageRecord;
            });

        $this->aiService
            ->expects($this->once())
            ->method('generateStructured')
            ->willReturn([
                'content' => '',
                'tool_calls' => [[
                    'id' => 'tool-call-denied',
                    'name' => 'demo.skill',
                    'arguments' => ['value' => 99],
                ]],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $assistantToolCalls,
                ],
            ]);

        $this->memoryService
            ->expects($this->never())
            ->method('extractAndSave');

        $result = $this->engine->execute('Need denied tool', $role, $session);

        $this->assertFalse($result->success);
        $this->assertSame(
            'The required tool call could not be completed with the current role skills or permissions.',
            $result->error
        );
        $this->assertCount(2, $capturedWrites);
        $this->assertSame(BotChatMessage::ROLE_USER, $capturedWrites[0]['role']);
        $this->assertSame(BotChatMessage::ROLE_ASSISTANT, $capturedWrites[1]['role']);
        $this->assertSame($assistantToolCalls, $capturedWrites[1]['tool_calls']);
    }

    public function testExecuteFailsWhenToolLoopExceedsMaximumIterations(): void
    {
        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $messageRecord = $this->createStub(BotChatMessage::class);
        $skillModel = $this->createMock(BotSkill::class);
        $toolCalls = [[
            'id' => 'loop-tool-call',
            'type' => 'function',
            'function' => [
                'name' => 'demo.skill',
                'arguments' => '{"value":1}',
            ],
        ]];

        $this->memoryService
            ->expects($this->once())
            ->method('getRelevantMemories')
            ->willReturn([]);

        $this->contextBuilder
            ->expects($this->exactly(6))
            ->method('build')
            ->willReturn([
                ['role' => 'system', 'content' => 'sys'],
                ['role' => 'user', 'content' => 'loop'],
            ]);

        $this->skillManager
            ->expects($this->exactly(6))
            ->method('getToolsForRole')
            ->with($role)
            ->willReturn([[
                'name' => 'demo.skill',
                'description' => 'Demo skill',
                'parameters' => ['type' => 'object', 'properties' => ['value' => ['type' => 'integer']]],
            ]]);

        $skillModel
            ->method('getData')
            ->willReturnCallback(static function (string $field): mixed {
                if ($field === BotSkill::schema_fields_CLASS_NAME) {
                    return AgentEngineTestSkill::class;
                }

                return null;
            });

        $this->skillManager
            ->expects($this->exactly(5))
            ->method('getSkill')
            ->with('demo.skill')
            ->willReturn($skillModel);

        $this->permissionValidator
            ->expects($this->exactly(5))
            ->method('validate')
            ->with('demo.skill', ['value' => 1], $role)
            ->willReturn(true);

        $this->sessionManager
            ->expects($this->exactly(11))
            ->method('addMessage')
            ->willReturn($messageRecord);

        $this->aiService
            ->expects($this->exactly(6))
            ->method('generateStructured')
            ->willReturn([
                'content' => '',
                'tool_calls' => [[
                    'id' => 'loop-tool-call',
                    'name' => 'demo.skill',
                    'arguments' => ['value' => 1],
                ]],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => $toolCalls,
                ],
            ]);

        $this->memoryService
            ->expects($this->never())
            ->method('extractAndSave');

        $result = $this->engine->execute('Loop forever', $role, $session);

        $this->assertFalse($result->success);
        $this->assertSame(
            'The AI tool loop exceeded the maximum iteration count. Please simplify the role skills or prompt.',
            $result->error
        );
    }

    public function testExecuteStreamYieldsSingleFinalChunk(): void
    {
        $engine = $this->getMockBuilder(AgentEngine::class)
            ->setConstructorArgs([
                $this->aiService,
                $this->sessionManager,
                $this->memoryService,
                $this->skillManager,
                $this->permissionValidator,
                $this->contextBuilder,
                $this->toolCallModel,
            ])
            ->onlyMethods(['execute'])
            ->getMock();

        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $streamed = [];

        $engine
            ->expects($this->once())
            ->method('execute')
            ->with('Stream prompt', $role, $session)
            ->willReturn(new AgentResult(
                content: 'streamed-once',
                success: true
            ));

        $chunks = iterator_to_array($engine->executeStream('Stream prompt', $role, $session, function (string $chunk) use (&$streamed): void {
            $streamed[] = $chunk;
        }));

        $this->assertSame(['streamed-once'], $chunks);
        $this->assertSame(['streamed-once'], $streamed);
    }

    public function testExecuteStreamYieldsErrorChunkForFailedExecution(): void
    {
        $engine = $this->getMockBuilder(AgentEngine::class)
            ->setConstructorArgs([
                $this->aiService,
                $this->sessionManager,
                $this->memoryService,
                $this->skillManager,
                $this->permissionValidator,
                $this->contextBuilder,
                $this->toolCallModel,
            ])
            ->onlyMethods(['execute'])
            ->getMock();

        $role = $this->createRoleDouble();
        $session = $this->createSessionDouble();
        $streamed = [];

        $engine
            ->expects($this->once())
            ->method('execute')
            ->with('Stream prompt', $role, $session)
            ->willReturn(new AgentResult(
                content: '',
                success: false,
                error: 'Permission denied'
            ));

        $chunks = iterator_to_array($engine->executeStream('Stream prompt', $role, $session, function (string $chunk) use (&$streamed): void {
            $streamed[] = $chunk;
        }));

        $this->assertSame(['[ERROR] Permission denied'], $chunks);
        $this->assertSame(['[ERROR] Permission denied'], $streamed);
    }

    private function createRoleDouble(): BotRole
    {
        $role = $this->createMock(BotRole::class);
        $role->method('getId')->willReturn(7);
        $role->method('getSkills')->willReturn(['demo.skill']);
        $role->method('getPermissions')->willReturn(['*']);
        $role->method('getModelConfig')->willReturn([
            'temperature' => 0.25,
            'max_tokens' => 2048,
        ]);
        $role->method('getData')->willReturnCallback(static function (string $field): mixed {
            return match ($field) {
                BotRole::schema_fields_CODE => 'role-code',
                BotRole::schema_fields_NAME => 'Role Name',
                BotRole::schema_fields_SCENARIO_ADAPTER_CODE => '',
                BotRole::schema_fields_MODEL_ID => 0,
                default => null,
            };
        });

        return $role;
    }

    private function createSessionDouble(): BotChatSession
    {
        $session = $this->createMock(BotChatSession::class);
        $session->method('getId')->willReturn(11);
        $session->method('getData')->willReturnCallback(static function (string $field): mixed {
            return match ($field) {
                BotChatSession::schema_fields_CONTEXT_ID => 'ctx-1',
                BotChatSession::schema_fields_CHANNEL => BotChatSession::CHANNEL_WEB,
                default => null,
            };
        });

        return $session;
    }
}
