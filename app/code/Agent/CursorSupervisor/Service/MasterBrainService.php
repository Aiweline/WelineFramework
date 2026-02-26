<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Agent\CursorBase\Service\CursorAiService;
use Agent\CursorBase\Service\TaskPoolService;
use Weline\Framework\Manager\ObjectManager;

/**
 * Master Brain 服务（大脑）
 * 
 * 职责：
 * 1. 理解用户原始需求
 * 2. 将需求拆分为原子级 Sub-Tasks
 * 3. 输出符合 tasks.json 格式的指令
 * 4. 协调 Agent 执行和错误处理
 */
class MasterBrainService
{
    private ?CursorAiService $cursorAi = null;
    private ?TaskPoolService $taskPool = null;
    
    private string $planFile;
    private int $lastPlanCheck = 0;
    private bool $verbose = false;
    
    public function __construct()
    {
        $this->planFile = BP . 'doc' . DS . 'plan.md';
    }
    
    /**
     * 设置详细输出
     */
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 获取 Cursor AI 服务
     */
    private function getCursorAi(): CursorAiService
    {
        if ($this->cursorAi === null) {
            $this->cursorAi = ObjectManager::getInstance(CursorAiService::class);
            $this->cursorAi->setVerbose($this->verbose);
        }
        return $this->cursorAi;
    }
    
    /**
     * 获取任务池服务
     */
    private function getTaskPool(): TaskPoolService
    {
        if ($this->taskPool === null) {
            $this->taskPool = ObjectManager::getInstance(TaskPoolService::class);
        }
        return $this->taskPool;
    }
    
    /**
     * 处理用户需求
     * 
     * @param string $requirement 用户需求描述
     * @return array 拆解后的任务列表
     */
    public function processRequirement(string $requirement): array
    {
        $this->log("🧠 Master Brain 开始处理需求...");
        $this->log("📝 需求: {$requirement}");
        
        $this->getTaskPool()->setMasterStatus('processing', $requirement);
        $this->getTaskPool()->save();
        
        // 构建 System Prompt
        $systemPrompt = $this->buildSystemPrompt();
        
        // 构建 User Prompt
        $userPrompt = $this->buildUserPrompt($requirement);
        
        try {
            // 调用 AI 模型进行任务拆解
            $response = $this->callAiForTaskDecomposition($systemPrompt, $userPrompt);
            
            // 解析 AI 响应
            $tasks = $this->parseAiResponse($response);
            
            if (empty($tasks)) {
                $this->log("⚠️ AI 未能正确拆解任务，使用默认结构");
                $tasks = $this->createDefaultTask($requirement);
            }
            
            // 将任务添加到任务池
            $this->getTaskPool()->addTasks($tasks);
            $this->getTaskPool()->setMasterStatus('idle');
            $this->getTaskPool()->save();
            
            $this->log("✅ 任务拆解完成，共 " . count($tasks) . " 个子任务");
            
            return $tasks;
            
        } catch (\Exception $e) {
            $this->log("❌ 任务拆解失败: " . $e->getMessage());
            $this->getTaskPool()->setMasterStatus('error');
            $this->getTaskPool()->save();
            
            // 返回一个默认任务
            return $this->createDefaultTask($requirement);
        }
    }
    
    /**
     * 构建 System Prompt
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的任务拆解专家，负责将用户的开发需求拆分为原子级的可执行任务。

## 输出格式

你必须输出一个 JSON 数组，每个任务包含以下字段：
```json
[
    {
        "agent_id": "Agent_模块名_序号",
        "file": "相对路径/文件名.php",
        "description": "任务的详细描述",
        "dep": null 或 "依赖的 agent_id",
        "priority": "critical/high/normal/low"
    }
]
```

## 拆解原则

1. **原子性**: 每个任务应该是单一职责，可以独立执行
2. **依赖关系**: 明确标注任务之间的依赖
3. **优先级**: 基础设施任务优先级高于业务逻辑
4. **文件定位**: 准确指定需要创建或修改的文件路径

## Agent ID 命名规范

- Agent_DB_001: 数据库/模型相关
- Agent_API_001: API/控制器相关
- Agent_Logic_001: 业务逻辑/服务相关
- Agent_UI_001: 前端/视图相关
- Agent_Test_001: 测试相关

## 示例

用户输入: "帮我开发一个用户登录功能"

输出:
```json
[
    {
        "agent_id": "Agent_DB_001",
        "file": "app/code/User/Auth/Model/User.php",
        "description": "创建 User 模型，包含用户名、密码哈希、最后登录时间等字段",
        "dep": null,
        "priority": "high"
    },
    {
        "agent_id": "Agent_Logic_001",
        "file": "app/code/User/Auth/Service/AuthService.php",
        "description": "实现登录验证逻辑，包含密码校验、登录限流、Session 管理",
        "dep": "Agent_DB_001",
        "priority": "high"
    },
    {
        "agent_id": "Agent_API_001",
        "file": "app/code/User/Auth/Controller/Backend/Login.php",
        "description": "创建登录控制器，处理登录请求和响应",
        "dep": "Agent_Logic_001",
        "priority": "normal"
    }
]
```

只输出 JSON 数组，不要有其他内容。
PROMPT;
    }
    
    /**
     * 构建 User Prompt
     */
    private function buildUserPrompt(string $requirement): string
    {
        // 读取项目上下文
        $context = $this->getProjectContext();
        
        return <<<PROMPT
## 当前项目上下文

{$context}

## 用户需求

{$requirement}

请将上述需求拆解为可执行的原子级任务，输出 JSON 数组格式。
PROMPT;
    }
    
    /**
     * 获取项目上下文
     */
    private function getProjectContext(): string
    {
        $context = "项目路径: " . BP . "\n";
        $context .= "框架: Weline Framework\n";
        
        // 读取现有模块
        $modulesPath = BP . 'app/code';
        if (is_dir($modulesPath)) {
            $vendors = scandir($modulesPath);
            $modules = [];
            foreach ($vendors as $vendor) {
                if ($vendor === '.' || $vendor === '..') continue;
                $vendorPath = $modulesPath . DS . $vendor;
                if (is_dir($vendorPath)) {
                    $vendorModules = scandir($vendorPath);
                    foreach ($vendorModules as $module) {
                        if ($module === '.' || $module === '..') continue;
                        if (is_dir($vendorPath . DS . $module)) {
                            $modules[] = "{$vendor}/{$module}";
                        }
                    }
                }
            }
            $context .= "现有模块: " . implode(', ', array_slice($modules, 0, 10)) . " 等\n";
        }
        
        return $context;
    }
    
    /**
     * 调用 AI 进行任务拆解
     */
    private function callAiForTaskDecomposition(string $systemPrompt, string $userPrompt): string
    {
        try {
            $cursorAi = $this->getCursorAi();
            
            if (!$cursorAi->isAvailable()) {
                $this->log("Cursor IDE 未运行，使用规则拆解");
                return $this->ruleBasedDecomposition($userPrompt);
            }
            
            $fullPrompt = "【系统指令】\n{$systemPrompt}\n\n【用户需求】\n{$userPrompt}";
            
            $result = $cursorAi->chat($fullPrompt);
            
            if ($result['success'] && !empty($result['response'])) {
                return $result['response'];
            }
            
            return $this->ruleBasedDecomposition($userPrompt);
            
        } catch (\Exception $e) {
            $this->log("AI 服务调用失败: " . $e->getMessage());
            return $this->ruleBasedDecomposition($userPrompt);
        }
    }
    
    /**
     * 基于规则的任务拆解（备用方案）
     */
    private function ruleBasedDecomposition(string $requirement): string
    {
        $tasks = [];
        
        // 关键词检测
        $keywords = [
            'model' => ['模型', '数据库', '表', 'Model', 'DB', '字段'],
            'service' => ['服务', '逻辑', '业务', 'Service', '处理'],
            'controller' => ['控制器', 'API', '接口', 'Controller', '请求'],
            'view' => ['视图', '界面', 'UI', '页面', 'View', '前端'],
            'test' => ['测试', 'Test', '验证'],
        ];
        
        $detected = [];
        foreach ($keywords as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($requirement, $word)) {
                    $detected[$type] = true;
                    break;
                }
            }
        }
        
        // 如果没有检测到特定类型，默认包含所有
        if (empty($detected)) {
            $detected = ['model' => true, 'service' => true, 'controller' => true];
        }
        
        $idx = 1;
        $prevAgent = null;
        
        if (isset($detected['model'])) {
            $tasks[] = [
                'agent_id' => 'Agent_DB_' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                'file' => 'app/code/Custom/Module/Model/Entity.php',
                'description' => "创建数据模型: {$requirement}",
                'dep' => null,
                'priority' => 'high',
            ];
            $prevAgent = $tasks[count($tasks) - 1]['agent_id'];
        }
        
        if (isset($detected['service'])) {
            $tasks[] = [
                'agent_id' => 'Agent_Logic_' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                'file' => 'app/code/Custom/Module/Service/BusinessService.php',
                'description' => "实现业务逻辑: {$requirement}",
                'dep' => $prevAgent,
                'priority' => 'high',
            ];
            $prevAgent = $tasks[count($tasks) - 1]['agent_id'];
        }
        
        if (isset($detected['controller'])) {
            $tasks[] = [
                'agent_id' => 'Agent_API_' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                'file' => 'app/code/Custom/Module/Controller/Backend/Action.php',
                'description' => "创建控制器/API: {$requirement}",
                'dep' => $prevAgent,
                'priority' => 'normal',
            ];
            $prevAgent = $tasks[count($tasks) - 1]['agent_id'];
        }
        
        if (isset($detected['view'])) {
            $tasks[] = [
                'agent_id' => 'Agent_UI_' . str_pad((string)$idx++, 3, '0', STR_PAD_LEFT),
                'file' => 'app/code/Custom/Module/view/templates/Backend/index.phtml',
                'description' => "创建视图/界面: {$requirement}",
                'dep' => $prevAgent,
                'priority' => 'normal',
            ];
        }
        
        return json_encode($tasks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 解析 AI 响应
     */
    private function parseAiResponse(string $response): array
    {
        // 尝试提取 JSON
        $response = trim($response);
        
        // 移除可能的 Markdown 代码块
        $response = preg_replace('/^```json?\s*/i', '', $response);
        $response = preg_replace('/\s*```$/i', '', $response);
        
        // 尝试解析
        $tasks = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON 解析失败: " . json_last_error_msg());
            return [];
        }
        
        if (!is_array($tasks)) {
            return [];
        }
        
        // 验证任务格式
        $validTasks = [];
        foreach ($tasks as $task) {
            if (isset($task['agent_id'], $task['file'], $task['description'])) {
                $validTasks[] = [
                    'agent_id' => $task['agent_id'],
                    'file' => $task['file'],
                    'description' => $task['description'],
                    'dep' => $task['dep'] ?? null,
                    'priority' => $task['priority'] ?? 'normal',
                ];
            }
        }
        
        return $validTasks;
    }
    
    /**
     * 创建默认任务
     */
    private function createDefaultTask(string $requirement): array
    {
        return [
            [
                'agent_id' => 'Agent_General_001',
                'file' => 'app/code/Custom/Module/Service/Service.php',
                'description' => $requirement,
                'dep' => null,
                'priority' => 'normal',
            ],
        ];
    }
    
    /**
     * 监控 plan.md 文件变化
     */
    public function watchPlanFile(): ?string
    {
        if (!file_exists($this->planFile)) {
            return null;
        }
        
        $mtime = filemtime($this->planFile);
        
        if ($mtime > $this->lastPlanCheck) {
            $this->lastPlanCheck = $mtime;
            
            // 读取并解析 plan.md
            $content = file_get_contents($this->planFile);
            $newRequirement = $this->extractNewRequirement($content);
            
            if ($newRequirement) {
                $this->log("📋 检测到 plan.md 更新，新需求: {$newRequirement}");
                return $newRequirement;
            }
        }
        
        return null;
    }
    
    /**
     * 从 plan.md 提取新需求
     */
    private function extractNewRequirement(string $content): ?string
    {
        // 查找未处理的需求（以 - [ ] 开头的行）
        if (preg_match('/^- \[ \] (.+)$/m', $content, $match)) {
            return trim($match[1]);
        }
        
        // 查找 ## 新需求 部分
        if (preg_match('/## 新需求\s*\n(.+?)(?=\n##|\z)/s', $content, $match)) {
            $requirement = trim($match[1]);
            if (!empty($requirement)) {
                return $requirement;
            }
        }
        
        return null;
    }
    
    /**
     * 处理任务失败
     */
    public function handleTaskFailure(string $agentId, string $error): void
    {
        $this->log("🔧 Master Brain 处理任务失败: {$agentId}");
        $this->log("   错误: {$error}");
        
        $task = $this->getTaskPool()->getTask($agentId);
        
        if ($task && $task['retries'] < 3) {
            // 尝试修复并重试
            $fixedDescription = $this->attemptToFix($task, $error);
            
            // 更新任务描述
            $this->getTaskPool()->updateStatus($agentId, 'retry');
            $this->getTaskPool()->save();
            
            $this->log("   已安排重试，修正后的指令: {$fixedDescription}");
        } else {
            // 标记为失败
            $this->getTaskPool()->updateStatus($agentId, 'failed', $error);
            $this->getTaskPool()->save();
            
            $this->log("   任务已达到最大重试次数，标记为失败");
        }
    }
    
    /**
     * 尝试修复任务
     */
    private function attemptToFix(array $task, string $error): string
    {
        // 简单的修复策略：在描述中添加错误信息
        return $task['description'] . "\n\n修复要求: 解决以下错误 - " . $error;
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[MasterBrain] {$message}\n";
        }
        
        $logFile = BP . 'var/log/master-brain.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    }
}
