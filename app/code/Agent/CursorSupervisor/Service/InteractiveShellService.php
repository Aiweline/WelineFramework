<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Agent\CursorBase\Service\TaskPoolService;
use Agent\CursorBase\Api\TaskPoolInterface;
use Agent\CursorBase\Service\CursorAiService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Process\Processer;

/**
 * 交互式 Shell 服务
 * 
 * 职责：
 * 1. 处理用户输入的命令和聊天
 * 2. 路由到对应的处理器
 * 3. 支持 /command 格式的快捷命令
 * 4. 普通文本作为聊天处理
 */
class InteractiveShellService
{
    private ?CursorAiService $cursorAi = null;
    private ?PlanExecutorService $planExecutor = null;
    private ?TaskPoolService $taskPool = null;
    private ?GitCommitService $gitService = null;
    private ?WatchdogService $watchdog = null;
    private ?BrowserTestService $browserTest = null;
    
    private bool $running = false;
    private bool $verbose = false;
    private array $chatHistory = [];
    private string $currentPlan = '';
    
    /** @var callable|null 每次输入处理后的回调 */
    private $afterInputCallback = null;
    
    /** @var array 监控配置（由 Start 命令传入） */
    private array $supervisorConfig = [];
    
    /**
     * 命令映射
     */
    private array $commands = [
        '/help' => 'cmdHelp',
        '/h' => 'cmdHelp',
        '/plan' => 'cmdPlan',
        '/p' => 'cmdPlan',
        '/progress' => 'cmdProgress',
        '/pro' => 'cmdProgress',
        '/status' => 'cmdStatus',
        '/s' => 'cmdStatus',
        '/commit' => 'cmdCommit',
        '/c' => 'cmdCommit',
        '/git' => 'cmdGit',
        '/g' => 'cmdGit',
        '/list' => 'cmdList',
        '/l' => 'cmdList',
        '/start' => 'cmdStart',
        '/pause' => 'cmdPause',
        '/done' => 'cmdDone',
        '/clear' => 'cmdClear',
        '/test' => 'cmdTest',
        '/t' => 'cmdTest',
        '/browser' => 'cmdBrowser',
        '/b' => 'cmdBrowser',
        '/watchdog' => 'cmdWatchdog',
        '/w' => 'cmdWatchdog',
        '/monitor' => 'cmdMonitor',
        '/m' => 'cmdMonitor',
        '/ai' => 'cmdAi',
        '/exit' => 'cmdExit',
        '/quit' => 'cmdExit',
        '/q' => 'cmdExit',
    ];
    
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    
    public function setCurrentPlan(string $plan): self
    {
        $this->currentPlan = $plan;
        return $this;
    }
    
    public function setAfterInputCallback(?callable $callback): self
    {
        $this->afterInputCallback = $callback;
        return $this;
    }
    
    public function setSupervisorConfig(array $config): self
    {
        $this->supervisorConfig = $config;
        return $this;
    }
    
    private function getCursorAi(): CursorAiService
    {
        if ($this->cursorAi === null) {
            $this->cursorAi = ObjectManager::getInstance(CursorAiService::class);
            $this->cursorAi->setVerbose($this->verbose);
        }
        return $this->cursorAi;
    }
    
    private function getPlanExecutor(): PlanExecutorService
    {
        if ($this->planExecutor === null) {
            $this->planExecutor = ObjectManager::getInstance(PlanExecutorService::class);
            $this->planExecutor->setVerbose($this->verbose);
        }
        return $this->planExecutor;
    }
    
    private function getTaskPool(): TaskPoolInterface
    {
        if ($this->taskPool === null) {
            $this->taskPool = ObjectManager::getInstance(TaskPoolService::class);
        }
        return $this->taskPool;
    }
    
    private function getGitService(): GitCommitService
    {
        if ($this->gitService === null) {
            $this->gitService = ObjectManager::getInstance(GitCommitService::class);
            $this->gitService->setVerbose($this->verbose);
        }
        return $this->gitService;
    }
    
    private function getWatchdog(): WatchdogService
    {
        if ($this->watchdog === null) {
            $this->watchdog = ObjectManager::getInstance(WatchdogService::class);
            $this->watchdog->setVerbose($this->verbose);
        }
        return $this->watchdog;
    }
    
    private function getBrowserTest(): BrowserTestService
    {
        if ($this->browserTest === null) {
            $this->browserTest = ObjectManager::getInstance(BrowserTestService::class);
            $this->browserTest->setVerbose($this->verbose);
        }
        return $this->browserTest;
    }
    
    /**
     * 启动交互式 Shell
     * 
     * 使用 Symfony Console 风格的输入处理（Windows 兼容）
     */
    public function start(): void
    {
        $this->running = true;
        $this->showWelcome();
        
        // 打开 stdin 流
        $inputStream = fopen('php://stdin', 'r');
        if (!$inputStream) {
            $this->output("❌ 无法打开标准输入");
            return;
        }
        
        // 确保流是阻塞模式
        stream_set_blocking($inputStream, true);
        
        // Windows 代码页处理
        $cp = 0;
        if (function_exists('sapi_windows_cp_set')) {
            $cp = sapi_windows_cp_get();
            sapi_windows_cp_set(sapi_windows_cp_get('oem'));
        }
        
        while ($this->running) {
            // 显示提示符
            echo "\033[36m> \033[0m";
            
            // 读取一行输入
            $input = fgets($inputStream, 4096);
            
            // 处理输入
            if ($input === false) {
                // Ctrl+D 或管道结束
                $this->output("\n👋 再见！");
                break;
            }
            
            // Windows 代码页转换
            if ($cp !== 0 && $input !== '' && function_exists('sapi_windows_cp_conv')) {
                $input = sapi_windows_cp_conv(sapi_windows_cp_get('oem'), $cp, $input);
            }
            
            $input = trim((string)$input);
            
            if (!empty($input)) {
                $this->processInput($input);
            }
            
            // 每次输入后执行回调（如监控检查）
            if ($this->afterInputCallback && $this->running) {
                call_user_func($this->afterInputCallback);
            }
        }
        
        // 恢复 Windows 代码页
        if ($cp !== 0 && function_exists('sapi_windows_cp_set')) {
            sapi_windows_cp_set($cp);
        }
        
        fclose($inputStream);
    }
    
    /**
     * 处理单次输入（非阻塞模式）
     */
    public function processOnce(): bool
    {
        $input = $this->readInputNonBlocking();
        
        if ($input === null) {
            return false;
        }
        
        $input = trim($input);
        
        if (empty($input)) {
            // 用户只按了回车，显示新提示符
            echo "\033[36m> \033[0m";
            return false;
        }
        
        $this->processInput($input);
        
        // 处理完后显示新提示符
        if ($this->running) {
            echo "\n\033[36m> \033[0m";
        }
        
        return true;
    }
    
    /**
     * 显示欢迎信息
     */
    private function showWelcome(): void
    {
        echo "\n";
        echo "┌────────────────────────────────────────────────────────────┐\n";
        echo "│  🤖 Cursor Supervisor 交互模式                            │\n";
        echo "├────────────────────────────────────────────────────────────┤\n";
        echo "│  命令:                                                     │\n";
        echo "│    /plan [name]  - 查看/切换计划      /pro - 查看进度      │\n";
        echo "│    /commit       - 智能提交           /git - Git 状态      │\n";
        echo "│    /monitor      - 文件监控           /ai  - AI 设置       │\n";
        echo "│    /help         - 帮助               /exit - 退出         │\n";
        echo "├────────────────────────────────────────────────────────────┤\n";
        echo "│  💬 直接输入文字即可与 AI 对话                             │\n";
        echo "│  💡 AI 由 Cursor CLI 驱动，首次响应可能需要 30 秒          │\n";
        echo "└────────────────────────────────────────────────────────────┘\n";
        echo "\n";
    }
    
    
    /**
     * 处理输入
     */
    public function processInput(string $input): void
    {
        // 检查是否是命令
        if (str_starts_with($input, '/')) {
            $this->processCommand($input);
        } else {
            $this->processChat($input);
        }
    }
    
    /**
     * 处理命令
     */
    private function processCommand(string $input): void
    {
        $parts = explode(' ', $input, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';
        
        if (isset($this->commands[$command])) {
            $method = $this->commands[$command];
            $this->$method($args);
        } else {
            $this->output("❓ 未知命令: {$command}");
            $this->output("   输入 /help 查看可用命令");
        }
    }
    
    /** @var array 待确认的任务 */
    private ?array $pendingTask = null;
    
    /** @var int Cursor AI 超时时间（秒） */
    private int $cursorAiTimeout = 120;
    
    /**
     * 处理聊天 — 流式调用 Cursor AI
     */
    private function processChat(string $input): void
    {
        if ($this->pendingTask !== null) {
            $this->handleTaskConfirmation($input);
            return;
        }

        // 检测是否是任务请求
        if ($this->isTaskRequest($input)) {
            $this->handleTaskRequest($input);
            return;
        }
        
        // 记录到历史
        $this->chatHistory[] = ['role' => 'user', 'content' => $input];
        
        // 检查 CLI 是否可用
        if (!$this->getCursorAi()->isAvailable()) {
            $this->output("❌ Cursor AI CLI 未安装");
            $this->output("");
            $this->showInstallGuide($this->getCursorAi()->getInstallStatus());
            return;
        }
        
        echo "🤖 ";
        
        try {
            $fullResponse = '';

            $result = $this->getCursorAi()->chatStream(
                $input,
                $this->buildChatContext(),
                $this->chatHistory,
                function (string $chunk) use (&$fullResponse) {
                    $filtered = str_replace('[RESPONSE_COMPLETE]', '', $chunk);
                    if ($filtered !== '') {
                        echo $filtered;
                        $fullResponse .= $filtered;
                    }
                },
                $this->cursorAiTimeout
            );
            
            echo "\n";
            
            if ($result['success']) {
                $response = !empty($fullResponse) ? $fullResponse : $result['response'];
                $this->chatHistory[] = ['role' => 'assistant', 'content' => $response];
                
                // 非流式时才需要输出（流式已经实时打印了）
                if (empty($fullResponse) && !empty($result['response'])) {
                    $this->output($result['response']);
                }
            } else {
                $error = $result['error'] ?? '未知错误';
                $this->output("⚠️ AI 响应失败: {$error}");
            }
        } catch (\Exception $e) {
            echo "\n";
            $this->output("⚠️ AI 调用异常: " . $e->getMessage());
        }
    }
    
    /**
     * 检测是否是任务请求
     */
    private function isTaskRequest(string $input): bool
    {
        $taskKeywords = [
            '帮我', '创建', '修改', '修复', '优化', '写一个', '实现', '添加',
            '删除', '重构', '生成', '开发', '新增', '更新', '升级',
            'create', 'fix', 'add', 'implement', 'refactor', 'generate',
            'build', 'make', 'write', 'update', 'develop',
        ];
        
        $inputLower = mb_strtolower($input);
        foreach ($taskKeywords as $keyword) {
            if (str_contains($inputLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 处理任务请求
     */
    private function handleTaskRequest(string $input): void
    {
        $this->output("📋 检测到任务请求:");
        $this->output("   \"{$input}\"");
        $this->output("");
        
        // 设置待确认任务
        $this->pendingTask = [
            'name' => mb_substr($input, 0, 50),
            'description' => $input,
            'priority' => 'medium',
        ];
        
        $this->output("确认分发此任务到后台执行? (y/n)");
    }
    
    /**
     * 处理 AI 回复中的任务标记
     */
    private function processTaskMarkers(string $content): string
    {
        // 匹配任务标记: [TASK:任务名|任务描述|优先级]
        if (preg_match('/\[TASK:([^|]+)\|([^|]+)\|([^\]]+)\]/', $content, $matches)) {
            $taskName = trim($matches[1]);
            $taskDesc = trim($matches[2]);
            $priority = trim($matches[3]);
            
            // 移除标记，显示干净的内容
            $displayContent = preg_replace('/\[TASK:[^\]]+\]/', '', $content);
            $displayContent = trim($displayContent);
            
            // 设置待确认任务
            $this->pendingTask = [
                'name' => $taskName,
                'description' => $taskDesc,
                'priority' => $priority,
            ];
            
            // 显示确认提示
            $this->output("");
            $this->output("┌─────────────────────────────────────────────┐");
            $this->output("│  📋 检测到任务                               │");
            $this->output("├─────────────────────────────────────────────┤");
            $this->output("│  名称: {$taskName}");
            $this->output("│  描述: {$taskDesc}");
            $this->output("│  优先级: {$priority}");
            $this->output("├─────────────────────────────────────────────┤");
            $this->output("│  确认分发? (y/n/修改后的描述)               │");
            $this->output("└─────────────────────────────────────────────┘");
            
            return $displayContent;
        }
        
        return $content;
    }
    
    /**
     * 处理任务确认
     */
    private function handleTaskConfirmation(string $input): void
    {
        $input = trim(strtolower($input));
        
        if ($input === 'n' || $input === 'no' || $input === '否' || $input === '取消') {
            $this->output("❌ 任务已取消");
            $this->pendingTask = null;
            return;
        }
        
        if ($input === 'y' || $input === 'yes' || $input === '是' || $input === '确认' || $input === '') {
            // 分发任务到任务池
            $this->dispatchTask($this->pendingTask);
            $this->pendingTask = null;
            return;
        }
        
        // 用户输入了修改后的描述
        $this->pendingTask['description'] = $input;
        $this->output("📝 已更新任务描述: {$input}");
        $this->output("   确认分发? (y/n)");
    }
    
    /**
     * 分发任务到后台任务池
     */
    private function dispatchTask(array $task): void
    {
        $this->output("🚀 正在分发任务...");
        
        try {
            $taskPool = $this->getTaskPool();
            
            // 生成任务 ID
            $taskId = 'Task_Chat_' . date('Ymd_His');
            
            // 添加到任务池（使用正确的方法签名）
            $taskPool->addTask(
                $taskId,                              // agentId
                '',                                   // targetFile（聊天任务无特定文件）
                $task['description'],                 // description
                null,                                 // dependency
                $task['priority'] ?? 'normal'         // priority
            );
            $taskPool->save();
            
            $this->output("✅ 任务已分发到后台");
            $this->output("   ID: {$taskId}");
            $this->output("   描述: {$task['description']}");
            $this->output("   状态: todo (等待执行)");
            $this->output("");
            $this->output("💡 使用 /progress 查看任务进度");
        } catch (\Exception $e) {
            $this->output("❌ 分发失败: " . $e->getMessage());
        }
    }
    
    /**
     * 构建系统上下文 — 保持简短，让用户消息成为焦点
     */
    private function buildChatContext(): string
    {
        $context = '你是 Weline Framework 的开发助手，用中文简洁回复。';

        if ($this->currentPlan) {
            $plan = $this->getPlanExecutor()->getPlan($this->currentPlan);
            if ($plan) {
                $context .= " 当前计划: {$plan['title']} (状态: {$plan['status']})";
            }
        }

        return $context;
    }
    
    // ========== 命令处理器 ==========
    
    /**
     * /help - 显示帮助
     */
    private function cmdHelp(string $args): void
    {
        $this->output("
📚 可用命令:

  📋 计划管理:
  /plan [name]     查看当前计划或切换到指定计划
  /progress        查看任务进度 (/pro)
  /list            列出所有计划 (/l)
  /start [name]    启动计划
  /pause           暂停当前计划
  /done            完成当前计划

  🔧 Git 操作:
  /commit          智能 Git 提交 (/c)
  /git             查看 Git 状态 (/g)

  📡 监控 (独立运行):
  /monitor         文件监控状态 (/m)
  /monitor start   启动文件监控
  /monitor stop    停止文件监控
  /monitor log     查看监控日志

  🌐 浏览器测试:
  /test <url>      前端测试 (/t)
  /browser [cmd]   浏览器操作 (/b)

  🤖 AI:
  /ai              查看 AI 状态
  /ai timeout 60   设置超时时间
  /ai install      安装指南

  📊 其他:
  /status          查看系统状态 (/s)
  /watchdog        Watchdog 进程状态 (/w)
  /clear           清空聊天历史
  /exit            退出交互模式 (/q)

  💬 直接输入文字即可与 AI 聊天。
");
    }
    
    /**
     * /plan - 查看/切换计划
     */
    private function cmdPlan(string $args): void
    {
        if (empty($args)) {
            // 显示当前计划
            if ($this->currentPlan) {
                $plan = $this->getPlanExecutor()->getPlan($this->currentPlan);
                if ($plan) {
                    $this->showPlanDetail($plan);
                } else {
                    $this->output("❌ 当前计划不存在");
                }
            } else {
                $this->output("📋 未选择计划，使用 /plan {name} 切换");
                $this->cmdList('');
            }
        } else {
            // 切换计划
            $plan = $this->getPlanExecutor()->getPlan($args);
            if ($plan) {
                $this->currentPlan = $args;
                $this->output("✅ 已切换到计划: {$plan['title']}");
                $this->showPlanDetail($plan);
            } else {
                $this->output("❌ 计划 '{$args}' 不存在");
            }
        }
    }
    
    /**
     * 显示计划详情
     */
    private function showPlanDetail(array $plan): void
    {
        $status = $plan['status'];
        $icon = match ($status) {
            'running' => '🔵',
            'ready' => '🟢',
            'pending' => '🟡',
            'done' => '✅',
            default => '❓',
        };
        
        $tasks = $plan['tasks'] ?? [];
        $taskList = is_array($tasks) ? $tasks : [];
        $total = count($taskList);
        
        $this->output("
{$icon} {$plan['title']}
   状态: {$status}
   优先级: {$plan['priority']}
   任务数: {$total}
");
    }
    
    /**
     * /progress - 查看进度
     */
    private function cmdProgress(string $args): void
    {
        $this->getTaskPool()->load();
        $stats = $this->getTaskPool()->getStats();
        
        $this->output("
📊 任务进度:
   总任务: {$stats['total']}
   待执行: {$stats['todo']}
   进行中: {$stats['running']}
   已完成: {$stats['done']}
   失败: {$stats['failed']}
");
        
        // 显示进行中的任务
        $runningTasks = $this->getTaskPool()->getTasksByStatus('running');
        if (!empty($runningTasks)) {
            $this->output("🔄 进行中:");
            foreach ($runningTasks as $id => $task) {
                $this->output("   - {$id}: {$task['description']}");
            }
        }
    }
    
    /**
     * /status - 查看状态
     */
    private function cmdStatus(string $args): void
    {
        $summary = $this->getPlanExecutor()->getStatusSummary();
        
        $this->output("
📊 系统状态:
   计划总数: {$summary['total']}
   进行中: {$summary['running']}
   待启动: {$summary['pending']}
   已完成: {$summary['done']}
");
        
        // 显示当前计划
        if ($this->currentPlan) {
            $this->output("   当前计划: {$this->currentPlan}");
        }
    }
    
    /**
     * /commit - 智能提交
     * 参数: --dry/-d 预览模式, --no-ai/-n 不使用AI生成
     */
    private function cmdCommit(string $args): void
    {
        $dryRun = str_contains($args, '--dry') || str_contains($args, '-d');
        $useAi = !str_contains($args, '--no-ai') && !str_contains($args, '-n');
        
        // 设置进度回调，实时输出
        $this->getGitService()->setProgressCallback(function (string $message, string $type) {
            $this->output($message);
        });
        
        $result = $this->getGitService()->smartCommit($dryRun, $useAi);
        
        if (isset($result['error'])) {
            $this->output("❌ " . $result['error']);
            return;
        }
        
        if (($result['message'] ?? '') === 'Nothing to commit') {
            $this->output("✅ 没有待提交的更改");
            return;
        }
        
        if ($dryRun) {
            $this->output("\n预览模式，输入 /commit 确认提交");
        }
    }
    
    /**
     * /git - Git 状态
     */
    private function cmdGit(string $args): void
    {
        $status = $this->getGitService()->getStatus();
        
        if (isset($status['error'])) {
            $this->output("❌ " . $status['error']);
            return;
        }
        
        $total = count($status['staged']) + count($status['modified']) + 
                 count($status['untracked']) + count($status['deleted']);
        
        if ($total === 0) {
            $this->output("✅ 工作区干净");
            return;
        }
        
        $this->output("📊 Git 状态: {$total} 个文件");
        
        if (!empty($status['staged'])) {
            $this->output("   暂存: " . count($status['staged']));
        }
        if (!empty($status['modified'])) {
            $this->output("   修改: " . count($status['modified']));
        }
        if (!empty($status['untracked'])) {
            $this->output("   新增: " . count($status['untracked']));
        }
    }
    
    /**
     * /list - 列出计划
     */
    private function cmdList(string $args): void
    {
        $plans = $this->getPlanExecutor()->listPlans();
        
        if (empty($plans)) {
            $this->output("📋 暂无计划");
            return;
        }
        
        $this->output("📋 计划列表:");
        foreach ($plans as $name => $plan) {
            $icon = match ($plan['status']) {
                'running' => '🔵',
                'ready' => '🟢',
                'pending' => '🟡',
                'done' => '✅',
                default => '❓',
            };
            $current = $name === $this->currentPlan ? ' ◄' : '';
            $this->output("   {$icon} {$name}: {$plan['title']}{$current}");
        }
    }
    
    /**
     * /start - 启动计划
     */
    private function cmdStart(string $args): void
    {
        $name = $args ?: $this->currentPlan;
        
        if (empty($name)) {
            $this->output("❓ 请指定计划名称: /start {name}");
            return;
        }
        
        $result = $this->getPlanExecutor()->startPlan($name);
        
        if ($result['success']) {
            $this->output("✅ " . $result['message']);
            $this->currentPlan = $name;
        } else {
            $this->output("❌ " . $result['error']);
        }
    }
    
    /**
     * /pause - 暂停计划
     */
    private function cmdPause(string $args): void
    {
        $name = $args ?: $this->currentPlan;
        
        if (empty($name)) {
            $this->output("❓ 请指定计划名称");
            return;
        }
        
        $result = $this->getPlanExecutor()->pausePlan($name);
        
        if ($result['success']) {
            $this->output("⏸️ " . $result['message']);
        } else {
            $this->output("❌ " . $result['error']);
        }
    }
    
    /**
     * /done - 完成计划
     */
    private function cmdDone(string $args): void
    {
        $name = $args ?: $this->currentPlan;
        
        if (empty($name)) {
            $this->output("❓ 请指定计划名称");
            return;
        }
        
        $result = $this->getPlanExecutor()->completePlan($name);
        
        if ($result['success']) {
            $this->output("✅ " . $result['message']);
        } else {
            $this->output("❌ " . $result['error']);
        }
    }
    
    /**
     * /clear - 清空聊天
     */
    private function cmdClear(string $args): void
    {
        $this->chatHistory = [];
        $this->output("🧹 聊天历史已清空");
    }
    
    /**
     * /test - 前端测试
     */
    private function cmdTest(string $args): void
    {
        if (empty($args)) {
            $this->output("❓ 用法: /test <url>");
            $this->output("   示例: /test http://localhost:8080/admin/dashboard");
            $this->output("   示例: /test /admin/user/listing");
            return;
        }
        
        // 补全 URL
        $url = $args;
        if (str_starts_with($url, '/')) {
            $url = $this->getBrowserTest()->getBaseUrl() . $url;
        }
        
        $this->output("🧪 开始前端测试: {$url}");
        $this->output("📋 生成测试命令...");
        
        $commands = $this->getBrowserTest()->generateQuickTest($url);
        
        $this->output("\n🔧 MCP 命令序列:");
        foreach ($commands as $i => $cmd) {
            $num = $i + 1;
            $this->output("   {$num}. {$cmd['tool']}: {$cmd['description']}");
        }
        
        $this->output("\n💡 提示: 这些命令需要通过 MCP Browser 执行");
        $this->output("   在 Cursor 中使用 @browser 或直接调用 CallMcpTool");
        
        // 输出 JSON 格式便于复制
        $this->output("\n📦 命令 JSON (可复制):");
        $this->output(json_encode($commands[0], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    
    /**
     * /browser - 浏览器状态/操作
     */
    private function cmdBrowser(string $args): void
    {
        $subCmd = trim($args);
        
        if (empty($subCmd) || $subCmd === 'help') {
            $this->output("
🌐 浏览器测试命令:

  /test <url>        快速测试页面（导航+快照+控制台检查）
  /browser tabs      查看打开的标签页
  /browser snap      获取当前页面快照
  /browser console   查看控制台消息
  /browser screen    截图

示例:
  /test http://localhost:8080/admin
  /test /admin/user/listing
");
            return;
        }
        
        $this->output("🔧 Browser 命令: {$subCmd}");
        
        $mcpCmd = match ($subCmd) {
            'tabs', 'tab' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_tabs',
                'args' => ['action' => 'list'],
            ],
            'snap', 'snapshot' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_snapshot',
                'args' => ['interactive' => true, 'compact' => true],
            ],
            'console', 'log', 'logs' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_console_messages',
                'args' => [],
            ],
            'screen', 'screenshot', 'ss' => [
                'server' => 'cursor-ide-browser',
                'tool' => 'browser_take_screenshot',
                'args' => [],
            ],
            default => null,
        };
        
        if ($mcpCmd) {
            $this->output("📦 MCP 调用:");
            $this->output(json_encode($mcpCmd, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->output("❓ 未知子命令: {$subCmd}");
            $this->output("   输入 /browser help 查看帮助");
        }
    }
    
    /**
     * /watchdog - 查看 Watchdog 子进程状态
     */
    private function cmdWatchdog(string $args): void
    {
        $processName = 'cursor-supervisor-watchdog';
        $pid = (int) Processer::getData('--name=' . $processName, 'pid');
        
        if ($pid <= 0) {
            $this->output("❌ Watchdog 子进程未启动");
            $this->output("   PID 文件不存在或为空");
            return;
        }
        
        $isRunning = Processer::isRunningByPid($pid);
        
        if ($isRunning) {
            $this->output("🐕 Watchdog 子进程状态:");
            $this->output("   PID: {$pid}");
            $this->output("   状态: ✅ 运行中");
            
            // 获取更多信息
            $info = Processer::getProcessInfo($pid);
            if (!empty($info['memory'])) {
                $this->output("   内存: {$info['memory']}");
            }
            if (!empty($info['start_time'])) {
                $this->output("   启动: {$info['start_time']}");
            }
        } else {
            $this->output("❌ Watchdog 子进程状态:");
            $this->output("   PID: {$pid} (已停止)");
            $this->output("   状态: ❌ 未运行");
            $this->output("");
            $this->output("💡 提示: 退出后重新启动 cursor:supervisor:start 将自动创建新的 Watchdog");
        }
        
        // 子命令
        $subCmd = trim($args);
        if ($subCmd === 'log' || $subCmd === 'logs') {
            $logFile = BP . 'var/log/cursor-supervisor.log';
            if (is_file($logFile)) {
                $this->output("\n📋 最近日志 (最后 20 行):");
                $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                $recent = array_slice($lines, -20);
                foreach ($recent as $line) {
                    $this->output("   " . $line);
                }
            } else {
                $this->output("\n📋 日志文件不存在");
            }
        } elseif ($subCmd === 'restart') {
            $this->output("\n🔄 重启 Watchdog...");
            Processer::destroy('--name=' . $processName);
            usleep(500000); // 等待 500ms
            $this->output("   请退出后重新启动 cursor:supervisor:start");
        } elseif ($subCmd === 'stop') {
            $this->output("\n🛑 停止 Watchdog...");
            Processer::destroy('--name=' . $processName);
            $this->output("   ✅ 已停止");
        }
    }
    
    /**
     * /monitor - 启动/停止/查看独立监控进程
     */
    private function cmdMonitor(string $args): void
    {
        $subCmd = trim($args);
        $processName = 'cursor-file-monitor';
        $pidFile = BP . 'var' . DS . 'run' . DS . $processName . '.pid';
        
        // 从 PID 文件读取
        $pid = 0;
        if (file_exists($pidFile)) {
            $pid = (int) @file_get_contents($pidFile);
        }
        
        // 检查进程是否真正在运行
        $isRunning = false;
        if ($pid > 0) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
                $isRunning = count($output) > 1 && strpos(implode('', $output), (string)$pid) !== false;
            } else {
                $isRunning = @posix_kill($pid, 0);
            }
        }
        
        if (empty($subCmd) || $subCmd === 'status') {
            // 显示状态
            if ($isRunning) {
                $this->output("📡 文件监控状态:");
                $this->output("   PID: {$pid}");
                $this->output("   状态: ✅ 运行中");
                $this->output("");
                $this->output("💡 命令:");
                $this->output("   /monitor stop   - 停止监控");
                $this->output("   /monitor log    - 查看日志");
            } else {
                $this->output("📡 文件监控状态: ❌ 未启动");
                $this->output("");
                $this->output("💡 命令:");
                $this->output("   /monitor start  - 启动监控");
            }
            return;
        }
        
        if ($subCmd === 'start') {
            if ($isRunning) {
                $this->output("⚠️ 监控已在运行中 (PID: {$pid})");
                return;
            }
            
            // 确保目录存在
            $runDir = BP . 'var' . DS . 'run';
            if (!is_dir($runDir)) {
                @mkdir($runDir, 0755, true);
            }
            
            // 构建监控命令
            $config = $this->supervisorConfig;
            $paths = !empty($config['watchPaths']) ? implode(',', $config['watchPaths']) : BP . 'app/code';
            $interval = $config['interval'] ?? 500;
            
            $phpBin = PHP_BINARY;
            $script = BP . 'bin' . DS . 'w';
            $cmdArgs = "cursor:supervisor:watchdog --path=\"{$paths}\" --interval={$interval}";
            if (!empty($config['docSync'])) {
                $cmdArgs .= ' --doc-sync';
            }
            if (!empty($config['autoTrigger'])) {
                $cmdArgs .= ' --auto-trigger';
            }
            // 传递 PID 文件路径
            $cmdArgs .= ' --pid-file="' . $pidFile . '"';
            
            $this->output("🚀 启动文件监控...");
            
            $foundPid = 0;
            
            if (PHP_OS_FAMILY === 'Windows') {
                // Windows: 使用 PowerShell Start-Process 启动后台进程
                // 使用正斜杠避免转义问题
                $phpBinPs = str_replace('\\', '/', $phpBin);
                $scriptPs = str_replace('\\', '/', $script);
                $pathsPs = str_replace('\\', '/', $paths);
                $pidFilePs = str_replace('\\', '/', $pidFile);
                
                // 构建 PowerShell 命令
                // 注意：使用单引号包裹路径，内部单引号用两个单引号转义
                // 使用字符串拼接避免 $p 被 PHP 解释为变量
                $psCmd = 'powershell -NoProfile -Command "' .
                    '$p = Start-Process -FilePath \'' . str_replace("'", "''", $phpBinPs) . '\' ' .
                    '-ArgumentList \'' . str_replace("'", "''", $scriptPs) . ' cursor:supervisor:watchdog --path=' . str_replace("'", "''", $pathsPs) . ' --interval=' . $interval . '\' ' .
                    '-WindowStyle Hidden -PassThru; ' .
                    'if ($p) { Set-Content -Path \'' . str_replace("'", "''", $pidFilePs) . '\' -Value $p.Id -NoNewline }"';
                
                exec($psCmd . ' 2>&1');
                
                // 等待 PID 文件写入
                usleep(500000);
                
                if (file_exists($pidFile)) {
                    $foundPid = (int) trim(@file_get_contents($pidFile));
                }
            } else {
                // Linux/macOS: nohup + &
                $fullCmd = "nohup \"{$phpBin}\" \"{$script}\" {$cmdArgs} > /dev/null 2>&1 & echo \$!";
                $foundPid = (int) exec($fullCmd);
                if ($foundPid > 0) {
                    file_put_contents($pidFile, (string) $foundPid);
                }
            }
            
            // 等待一下让进程启动
            usleep(200000);
            
            // 检查是否成功
            if (file_exists($pidFile)) {
                $newPid = (int) @file_get_contents($pidFile);
                if ($newPid > 0) {
                    $this->output("   ✅ 已启动 (PID: {$newPid})");
                    $this->output("   使用 /monitor status 查看状态");
                } else {
                    $this->output("   ❌ 启动失败");
                }
            } else {
                $this->output("   ⚠️ 进程可能已启动，但无法获取 PID");
            }
            return;
        }
        
        if ($subCmd === 'stop') {
            if (!$isRunning) {
                $this->output("⚠️ 监控未在运行");
                // 清理残留的 PID 文件
                if (file_exists($pidFile)) {
                    @unlink($pidFile);
                }
                return;
            }
            
            $this->output("🛑 停止文件监控...");
            
            // 终止进程
            if (PHP_OS_FAMILY === 'Windows') {
                exec("taskkill /PID {$pid} /F 2>NUL");
            } else {
                @posix_kill($pid, SIGTERM);
            }
            
            // 删除 PID 文件
            if (file_exists($pidFile)) {
                @unlink($pidFile);
            }
            
            $this->output("   ✅ 已停止");
            return;
        }
        
        if ($subCmd === 'log' || $subCmd === 'logs') {
            $logFile = BP . 'var/log/cursor-supervisor.log';
            if (is_file($logFile)) {
                $this->output("\n📋 监控日志 (最后 20 行):");
                $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                $recent = array_slice($lines, -20);
                foreach ($recent as $line) {
                    $this->output("   " . $line);
                }
            } else {
                $this->output("📋 日志文件不存在");
            }
            return;
        }
        
        $this->output("❓ 未知子命令: {$subCmd}");
        $this->output("   可用: start, stop, status, log");
    }

    /**
     * /ai - AI 状态与设置
     */
    private function cmdAi(string $args): void
    {
        $subCmd = strtolower(trim($args));
        $installStatus = $this->getCursorAi()->getInstallStatus();

        if ($subCmd === 'install') {
            $this->showInstallGuide($installStatus);
            return;
        }

        if ($subCmd === 'login') {
            if (!$installStatus['installed']) {
                $this->showInstallGuide($installStatus);
                return;
            }
            $this->output("🔐 请在新终端窗口运行: agent login");
            $this->output("   然后在浏览器中完成授权");
            return;
        }

        if (str_starts_with($subCmd, 'timeout ')) {
            $timeout = (int) substr($subCmd, 8);
            if ($timeout > 0) {
                $this->cursorAiTimeout = $timeout;
                $this->output("✅ 超时设置为 {$timeout} 秒");
            }
            return;
        }

        // 显示状态
        $this->output("🤖 Cursor AI 状态:");
        $this->output("");

        if (!$installStatus['installed']) {
            $this->output("   安装: ❌ 未安装");
            $this->output("");
            $this->showInstallGuide($installStatus);
        } else {
            $this->output("   安装: ✅ 已安装");
            $this->output("   路径: {$installStatus['path']}");
            $this->output("   超时: {$this->cursorAiTimeout} 秒");
            $this->output("");
            $this->output("💡 命令:");
            $this->output("   /ai timeout 60 - 设置超时时间");
            $this->output("   /ai login      - 登录指南");
            $this->output("   /ai install    - 安装指南");
        }
    }
    
    /**
     * 显示安装指南
     */
    private function showInstallGuide(array $installStatus): void
    {
        $instructions = $installStatus['install_instructions'] ?? $this->getCursorAi()->getInstallInstructions();
        
        $this->output("📦 Cursor CLI 安装指南 ({$instructions['platform']}):");
        $this->output("");
        
        foreach ($instructions['steps'] as $step) {
            $this->output("   {$step}");
        }
        
        $this->output("");
        $this->output("📋 快速命令 (复制到终端执行):");
        $this->output("   {$instructions['command']}");
        $this->output("");
        $this->output("💡 安装后运行 /ai 检查状态");
    }
    
    /**
     * /exit - 退出
     */
    private function cmdExit(string $args): void
    {
        $this->running = false;
        $this->output("👋 再见！");
    }
    
    /**
     * 输出信息
     */
    private function output(string $message): void
    {
        echo $message . "\n";
        flush();
    }
    
    /**
     * 是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
    
    /**
     * 启动运行状态（用于外部循环调用 processOnce 的场景）
     */
    public function startRunning(): self
    {
        $this->running = true;
        return $this;
    }
    
    /**
     * 停止运行
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
