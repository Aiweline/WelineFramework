<?php
declare(strict_types=1);

namespace Weline\Bot\Extends\Module\Weline_Ai\Agent;

use Weline\Ai\Interface\AgentInterface;
use Weline\Ai\Interface\ToolInterface;
use Weline\Ai\Agent\AgentResult;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;
use Weline\Bot\Service\CodingAgent\Tool\ReadFileTool;
use Weline\Bot\Service\CodingAgent\Tool\SearchReplaceTool;
use Weline\Bot\Service\CodingAgent\Tool\GrepTool;
use Weline\Bot\Service\CodingAgent\Tool\CodebaseSearchTool;
use Weline\Bot\Service\CodingAgent\Tool\ListDirTool;
use Weline\Bot\Service\CodingAgent\Tool\GlobFileSearchTool;
use Weline\Bot\Service\CodingAgent\Tool\RunTerminalTool;

/**
 * 编码智能体
 *
 * 专为代码编写、编辑、分析设计的智能体，参考 Cursor 工具能力。
 * 继承 Weline_Ai Agent 扩展点，提供 read_file、search_replace、grep 等编码工具。
 */
class CodingAgent implements AgentInterface
{
    private const MAX_ITERATIONS = 7;

    /** @var ToolInterface[]|null */
    private ?array $tools = null;

    public function getCode(): string
    {
        return 'coding_agent';
    }

    public function getName(): string
    {
        return __('编码智能体');
    }

    public function getDescription(): string
    {
        return __('专为代码编写、编辑、分析设计的智能体。支持读取文件、搜索替换、grep 搜索、目录浏览、glob 查找、执行终端命令等工具。');
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getScenarios(): array
    {
        return ['coding', 'code_generation', 'code_edit'];
    }

    public function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = [
                ObjectManager::getInstance(ReadFileTool::class),
                ObjectManager::getInstance(SearchReplaceTool::class),
                ObjectManager::getInstance(GrepTool::class),
                ObjectManager::getInstance(CodebaseSearchTool::class),
                ObjectManager::getInstance(ListDirTool::class),
                ObjectManager::getInstance(GlobFileSearchTool::class),
                ObjectManager::getInstance(RunTerminalTool::class),
            ];
        }
        return $this->tools;
    }

    public function getSystemPrompt(array $context = []): string
    {
        return <<<'PROMPT'
You are an expert coding assistant. You can read files, search and replace text, grep for patterns, list directories, find files by glob, and run terminal commands.

【可用工具】
- read_file: Read file contents (path, optional offset/limit)
- search_replace: Replace exact string in file (path, old_string, new_string)
- grep: Search for exact pattern in files (pattern, path, glob, max_results)
- codebase_search: Semantic/vector search over codebase (query, type, module, limit). Use for "find where X is implemented", "class that handles Y". Falls back to grep if vector index not available.
- list_dir: List directory contents
- glob_file_search: Find files matching glob (e.g. **/*.php)
- run_terminal_cmd: Execute safe commands (git, npm, php, grep, etc)

【规则】
1. Paths are relative to project root unless absolute
2. search_replace: old_string must match exactly (including whitespace)
3. Use grep to explore codebase before editing
4. Use read_file to inspect before search_replace
5. Keep edits minimal and precise
6. Avoid destructive operations; ask user for confirmation if unsure
PROMPT;
    }

    public function execute(
        string $prompt,
        AiModel $model,
        array $params = [],
        ?callable $streamCallback = null
    ): AgentResult {
        $tools = $this->getTools();
        $enabledTools = array_filter($tools, fn(ToolInterface $t) => $t->isEnabled());

        $toolDefs = array_map(fn(ToolInterface $t) => [
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'parameters' => $t->getParameters(),
        ], array_values($enabledTools));

        $toolMap = [];
        foreach ($enabledTools as $t) {
            $toolMap[$t->getName()] = $t;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->getSystemPrompt($params)],
            ['role' => 'user', 'content' => $prompt],
        ];

        /** @var \Weline\Ai\Service\Provider\ProviderFactory $providerFactory */
        $providerFactory = $params['provider_factory'] ?? ObjectManager::getInstance(\Weline\Ai\Service\Provider\ProviderFactory::class);
        $provider = $providerFactory->getProvider($model);

        $iteration = 0;
        $allToolCalls = [];
        $finalContent = '';
        $aiContent = '';
        $useStreamFull = method_exists($provider, 'generateStreamFull');

        while ($iteration < self::MAX_ITERATIONS) {
            $currentIteration = $iteration + 1;
            if ($streamCallback) {
                $streamCallback('iteration', ['iteration' => $currentIteration, 'max' => self::MAX_ITERATIONS]);
            }

            try {
                if ($streamCallback) {
                    $streamCallback('agent_status', ['status' => 'calling_ai', 'message' => __('正在调用 AI...')]);
                }

                if ($useStreamFull) {
                    $response = $provider->generateStreamFull($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float) ($params['temperature'] ?? 0.3),
                        'max_tokens' => (int) ($params['max_tokens'] ?? 16000),
                        'timeout' => (int) ($params['timeout'] ?? 180),
                    ]);
                } else {
                    $response = $provider->generate($model, '', [
                        'messages' => $messages,
                        'tools' => $toolDefs,
                        'temperature' => (float) ($params['temperature'] ?? 0.3),
                        'max_tokens' => (int) ($params['max_tokens'] ?? 16000),
                    ]);
                }

                $aiContent = $response['content'] ?? '';

                if (empty($response['tool_calls'])) {
                    $finalContent = $aiContent;
                    break;
                }

                $assistantMsg = $response['assistant_message'] ?? [
                    'role' => 'assistant',
                    'content' => $aiContent,
                    'tool_calls' => $response['tool_calls'],
                ];
                $messages[] = $assistantMsg;

                foreach ($response['tool_calls'] as $tc) {
                    $name = $tc['name'] ?? $tc['function']['name'] ?? '';
                    $args = $tc['arguments'] ?? $tc['function']['arguments'] ?? [];
                    $toolCallId = $tc['id'] ?? uniqid('tc_');

                    if (is_string($args)) {
                        $args = json_decode($args, true) ?? [];
                    }

                    $tool = $toolMap[$name] ?? null;
                    $result = ['error' => __('Tool not found: %{1}', [$name])];
                    if ($tool) {
                        try {
                            $result = $tool->execute($args);
                        } catch (\Throwable $e) {
                            $result = ['error' => $e->getMessage()];
                        }
                    }

                    $resultStr = is_string($result) ? $result : json_encode($result, JSON_UNESCAPED_UNICODE);
                    $allToolCalls[] = ['name' => $name, 'arguments' => $args];

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => $resultStr,
                    ];
                }

                $iteration++;
            } catch (\Throwable $e) {
                $finalContent = __('执行失败: %{1}', [$e->getMessage()]);
                break;
            }
        }

        if ($finalContent === '' && $aiContent !== '') {
            $finalContent = $aiContent;
        }

        $agentResult = new AgentResult();
        $agentResult->success = true;
        $agentResult->content = $finalContent;
        $agentResult->toolCalls = $allToolCalls;
        return $agentResult;
    }

    public function supportsModel(string $modelCode): bool
    {
        return true;
    }

    public function getMaxIterations(): int
    {
        return self::MAX_ITERATIONS;
    }
}
