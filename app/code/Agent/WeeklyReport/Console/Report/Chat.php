<?php

declare(strict_types=1);

namespace Agent\WeeklyReport\Console\Report;

use Agent\WeeklyReport\Model\WeeklyReport;
use Agent\WeeklyReport\Model\WeeklyTask;
use Agent\WeeklyReport\Service\WeeklyReportService;
use Agent\WeeklyReport\Service\ExcelExporter;
use Agent\WeeklyReport\Service\HolidayService;
use Agent\CursorBase\Service\CursorAiService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * 周报交互式命令
 * 
 * 命令：report:chat
 * 
 * 支持的斜杠命令：
 * - /status - 查看本周周报状态
 * - /export - 导出本周周报
 * - /export --all - 导出全部周报
 * - /list - 列出所有周报
 * - /week <N> - 切换到第 N 周
 * - /last <N> - 修改上 N 周的周报
 * - /add - 添加新任务
 * - /edit <id> - 编辑任务
 * - /delete <id> - 删除任务
 * - /help - 帮助
 * - /exit - 退出
 */
class Chat extends CommandAbstract
{
    private ?WeeklyReportService $reportService = null;
    private ?ExcelExporter $exporter = null;
    private ?HolidayService $holidayService = null;
    private ?CursorAiService $cursorAi = null;

    private bool $running = false;
    private int $currentWeekNumber = 0;
    private int $currentYear = 2026;
    private ?WeeklyReport $currentReport = null;

    private array $commands = [
        '/help' => 'cmdHelp',
        '/h' => 'cmdHelp',
        '/status' => 'cmdStatus',
        '/s' => 'cmdStatus',
        '/export' => 'cmdExport',
        '/e' => 'cmdExport',
        '/list' => 'cmdList',
        '/l' => 'cmdList',
        '/week' => 'cmdWeek',
        '/w' => 'cmdWeek',
        '/last' => 'cmdLast',
        '/add' => 'cmdAdd',
        '/a' => 'cmdAdd',
        '/edit' => 'cmdEdit',
        '/delete' => 'cmdDelete',
        '/del' => 'cmdDelete',
        '/exit' => 'cmdExit',
        '/quit' => 'cmdExit',
        '/q' => 'cmdExit',
    ];

    private function getReportService(): WeeklyReportService
    {
        if ($this->reportService === null) {
            $this->reportService = ObjectManager::getInstance(WeeklyReportService::class);
        }
        return $this->reportService;
    }

    private function getExporter(): ExcelExporter
    {
        if ($this->exporter === null) {
            $this->exporter = ObjectManager::getInstance(ExcelExporter::class);
        }
        return $this->exporter;
    }

    private function getHolidayService(): HolidayService
    {
        if ($this->holidayService === null) {
            $this->holidayService = ObjectManager::getInstance(HolidayService::class);
        }
        return $this->holidayService;
    }

    private function getCursorAi(): CursorAiService
    {
        if ($this->cursorAi === null) {
            $this->cursorAi = ObjectManager::getInstance(CursorAiService::class);
        }
        return $this->cursorAi;
    }

    public function execute(array $args = [], array $data = [])
    {
        $this->currentYear = (int) date('Y');
        $this->currentWeekNumber = $this->getReportService()->getCurrentWeekNumber();
        $this->currentReport = $this->getReportService()->getCurrentWeekReport();

        $this->showWelcome();
        $this->cmdStatus('');

        $this->running = true;

        while ($this->running) {
            $input = $this->readInput();

            if ($input === false || $input === null) {
                usleep(100000);
                continue;
            }

            $input = trim($input);

            if (empty($input)) {
                continue;
            }

            $this->processInput($input);
        }
    }

    private function showWelcome(): void
    {
        $weekStr = str_pad((string) $this->currentWeekNumber, 2, ' ', STR_PAD_LEFT);
        $this->output("\n");
        $this->output("┌────────────────────────────────────────────────────────────┐");
        $this->output("│  📋 周报管理器 - Weekly Report Manager                     │");
        $this->output("├────────────────────────────────────────────────────────────┤");
        $this->output("│  当前: 第 {$weekStr} 周 ({$this->currentYear}年)                              │");
        $this->output("│  AI 模式: Cursor IDE (流式响应)                            │");
        $this->output("├────────────────────────────────────────────────────────────┤");
        $this->output("│  命令:                                                     │");
        $this->output("│    /status   - 查看本周状态      /export - 导出周报        │");
        $this->output("│    /add      - 添加任务          /list   - 周报列表        │");
        $this->output("│    /week <N> - 切换到第N周       /last   - 上周周报        │");
        $this->output("│    /help     - 帮助              /exit   - 退出            │");
        $this->output("│                                                            │");
        $this->output("│  💡 请确保 Cursor IDE 已打开！                              │");
        $this->output("│  直接输入工作内容，Cursor AI 会帮你解析为任务。             │");
        $this->output("└────────────────────────────────────────────────────────────┘");
        $this->output("");
    }

    private function readInput(): ?string
    {
        echo "\n\033[36m周报> \033[0m";
        $input = fgets(STDIN);

        if ($input === false) {
            return null;
        }

        return $this->cleanInput($input);
    }

    /**
     * 清理输入（移除 BOM 和多余空白）
     */
    private function cleanInput(string $input): string
    {
        $input = preg_replace('/^\xEF\xBB\xBF/', '', $input);
        return trim($input);
    }

    /**
     * 安全地读取 STDIN（避免 fgets 返回 false 导致 trim 报错）
     */
    private function safeReadLine(): string
    {
        $input = fgets(STDIN);
        return $input !== false ? trim($input) : '';
    }

    private function processInput(string $input): void
    {
        $input = $this->cleanInput($input);
        if (str_starts_with($input, '/')) {
            $this->processCommand($input);
        } else {
            $this->processChat($input);
        }
    }

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

    private array $chatHistory = [];

    /**
     * 处理聊天消息（使用 Cursor AI 流式响应）
     */
    private function processChat(string $input): void
    {
        $cursorAi = $this->getCursorAi();

        if (!$cursorAi->isAvailable()) {
            $this->output("\n❌ Cursor IDE 未运行");
            $this->output("   请先启动 Cursor IDE，或使用 /add 命令手动添加任务");
            return;
        }

        $this->output("\n🤖 正在与 Cursor AI 对话...");
        $this->output("   (Cursor 将自动处理请求，流式输出响应)\n");

        $currentTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());
        $systemPrompt = $this->buildSystemPrompt($currentTasks);

        $this->chatHistory[] = ['role' => 'user', 'content' => $input];

        $result = $cursorAi->chatStream(
            $input,
            $systemPrompt,
            array_slice($this->chatHistory, -10),
            function (string $chunk) {
                $filtered = str_replace('[RESPONSE_COMPLETE]', '', $chunk);
                if ($filtered !== '') {
                    echo $filtered;
                    flush();
                }
            },
            120
        );

        echo "\n";

        if ($result['success']) {
            $response = str_replace('[RESPONSE_COMPLETE]', '', $result['response']);

            $this->chatHistory[] = ['role' => 'assistant', 'content' => $response];

            if (count($this->chatHistory) > 20) {
                $this->chatHistory = array_slice($this->chatHistory, -16);
            }

            $this->processAiResponse($response);
        } else {
            $error = $result['error'] ?? '未知错误';
            $this->output("\n❌ AI 响应失败: {$error}");
            $this->output("   可以使用 /add 命令手动添加任务");
        }
    }

    /**
     * 构建系统提示
     */
    private function buildSystemPrompt(array $tasks): string
    {
        $taskList = $this->formatTaskListForPrompt($tasks);
        $dateRange = $this->getReportService()->getWeekDateRange($this->currentWeekNumber, $this->currentYear);
        $todayDate = date('Y-m-d');
        $weekInfo = "今天是 {$todayDate}，当前是第 {$this->currentWeekNumber} 周（{$this->currentYear}年，{$dateRange['start']} ~ {$dateRange['end']}）";

        if ($this->currentReport && $this->currentReport->isHolidayWeek()) {
            $holidayName = $this->currentReport->getData(WeeklyReport::fields_HOLIDAY_NAME);
            $weekInfo .= "，本周是【{$holidayName}】假期周";
        }

        return <<<PROMPT
你是一个周报管理助手。{$weekInfo}。

当前周报任务列表：
{$taskList}

你的职责：
1. 与用户自然对话，理解他们的需求
2. 回答关于周报、任务状态、工作进度的问题
3. 当用户明确表示要添加任务时，帮助他们整理任务信息
4. 当用户想更新任务状态时，告诉他们使用 /edit <id> 命令

重要规则：
- 不要主动提议添加任务，除非用户明确表达了添加任务的意图
- 如果用户想添加任务，在回复末尾添加标记：[ADD_TASK:任务名称|状态|类别]
  - 状态可选：待开始、进行中、测试中、完成
  - 类别可选：开发、测试、运维、文档、会议、其他
- 如果用户只是闲聊或询问，正常回复即可，不要添加任何标记
- 回复要简洁友好

示例：
- 用户说"今天做了什么" -> 正常聊天，不添加标记
- 用户说"帮我记录一下，今天完成了用户模块开发" -> 回复确认，并添加 [ADD_TASK:用户模块开发|完成|开发]
- 用户说"saas系统进度怎么样" -> 根据任务列表回答进度，不添加标记

完成回复后，请在末尾添加 [RESPONSE_COMPLETE] 标记。
PROMPT;
    }

    /**
     * 格式化任务列表用于提示
     */
    private function formatTaskListForPrompt(array $tasks): string
    {
        if (empty($tasks)) {
            return "（暂无任务）";
        }

        $lines = [];
        foreach ($tasks as $task) {
            $id = $task->getId();
            $name = $task->getData(WeeklyTask::fields_TASK_NAME);
            $status = $task->getData(WeeklyTask::fields_STATUS);
            $category = $task->getData(WeeklyTask::fields_CATEGORY) ?: '未分类';
            $progress = $task->getData(WeeklyTask::fields_PROGRESS) ?: '';
            $lines[] = "- [{$id}] {$name} ({$status}) [{$category}]" . ($progress ? " - {$progress}" : '');
        }
        return implode("\n", $lines);
    }

    /**
     * 处理 AI 响应中的任务添加标记
     */
    private function processAiResponse(string $response): void
    {
        if (preg_match('/\[ADD_TASK:([^|]+)\|([^|]+)\|([^\]]+)\]/', $response, $matches)) {
            $taskName = trim($matches[1]);
            $status = trim($matches[2]);
            $category = trim($matches[3]);

            $this->output("\n📝 检测到任务添加请求:");
            $this->output("   任务名: {$taskName}");
            $this->output("   状态: {$status}");
            $this->output("   类别: {$category}");
            $this->output("\n确认添加此任务? (y/n): ");

            $confirm = $this->safeReadLine();

            if (strtolower($confirm) === 'y' || $confirm === '是') {
                $taskData = [
                    WeeklyTask::fields_TASK_NAME => $taskName,
                    WeeklyTask::fields_STATUS => $status,
                    WeeklyTask::fields_CATEGORY => $category,
                    WeeklyTask::fields_START_DATE => date('Y-m-d'),
                ];

                $task = $this->getReportService()->addTask(
                    (int) $this->currentReport->getId(),
                    $taskData
                );

                $this->output("✅ 任务已添加 (ID: {$task->getId()})");
            } else {
                $this->output("❌ 已取消添加");
            }
        }
    }

    private function cmdHelp(string $args): void
    {
        $this->output("
📚 周报管理器命令:

  /status          查看当前周报状态
  /s               同上
  /export          导出当前周报为 Excel
  /export --all    导出全部周报
  /e               同上
  /list            列出所有周报
  /l               同上
  /week <N>        切换到第 N 周编辑
  /w <N>           同上
  /last [N]        修改上 N 周的周报（默认 1）
  /add             添加新任务（引导式）
  /a               同上
  /edit <id>       编辑指定任务
  /delete <id>     删除任务
  /del <id>        同上
  /exit            退出
  /q               同上

  直接输入工作描述，AI 会自动解析为任务。
");
    }

    private function cmdStatus(string $args): void
    {
        $report = $this->currentReport;
        $weekNumber = $this->currentWeekNumber;
        $dateRange = $this->getReportService()->getWeekDateRange($weekNumber);

        $holidayName = $report->getData(WeeklyReport::fields_HOLIDAY_NAME);
        $weekTitle = $holidayName ? "第 {$weekNumber} 周【{$holidayName}】" : "第 {$weekNumber} 周";

        $this->output("\n📊 {$weekTitle} 周报状态");
        $this->output("   日期: {$dateRange['start']} ~ {$dateRange['end']}");

        $summary = $this->getReportService()->getWeekReportSummary((int) $report->getId());

        $this->output("   任务总数: {$summary['total']}");
        $this->output("   已完成: {$summary['completed']}");
        $this->output("   进行中: {$summary['in_progress']}");
        $this->output("   待开始: {$summary['todo']}");
        $this->output("   测试中: {$summary['testing']}");

        $tasks = $this->getReportService()->getWeekTasks((int) $report->getId());

        if (!empty($tasks)) {
            $this->output("\n📋 任务列表:");
            foreach ($tasks as $task) {
                $id = $task->getId();
                $name = $task->getData(WeeklyTask::fields_TASK_NAME);
                $subTask = $task->getData(WeeklyTask::fields_SUB_TASK);
                $status = $task->getData(WeeklyTask::fields_STATUS);

                $statusIcon = match ($status) {
                    WeeklyTask::STATUS_COMPLETED, '完成', '已完成' => '✅',
                    WeeklyTask::STATUS_IN_PROGRESS => '🔄',
                    WeeklyTask::STATUS_TESTING, '测试中' => '🧪',
                    WeeklyTask::STATUS_TODO, '待开始' => '⏳',
                    default => '📌',
                };

                $displayName = $subTask ?: $name;
                $this->output("   {$statusIcon} [{$id}] {$displayName} ({$status})");
            }
        } else {
            if ($holidayName) {
                $this->output("\n   🎉 {$holidayName} - 节假日休息");
            } else {
                $this->output("\n   暂无任务，使用 /add 或直接输入工作内容添加");
            }
        }
    }

    private function cmdExport(string $args): void
    {
        try {
            $all = str_contains($args, '--all') || str_contains($args, '-all') || str_contains($args, '-a');

            if ($all) {
                $this->output("📤 正在导出全部周报...");
                $filePath = $this->getExporter()->exportAllReports($this->currentYear);
            } else {
                $this->output("📤 正在导出第 {$this->currentWeekNumber} 周周报...");
                $filePath = $this->getExporter()->exportWeekReport($this->currentWeekNumber, $this->currentYear);
            }

            $this->output("✅ 导出成功!");
            $this->output("   文件: {$filePath}");
        } catch (\Exception $e) {
            $this->output("❌ 导出失败: " . $e->getMessage());
        }
    }

    private function cmdList(string $args): void
    {
        $reports = $this->getReportService()->getAllReports($this->currentYear);

        if (empty($reports)) {
            $this->output("📋 暂无周报");
            return;
        }

        $this->output("\n📋 {$this->currentYear} 年周报列表:");

        foreach ($reports as $report) {
            $weekNumber = $report->getData(WeeklyReport::fields_WEEK_NUMBER);
            $startDate = $report->getData(WeeklyReport::fields_WEEK_START_DATE);
            $endDate = $report->getData(WeeklyReport::fields_WEEK_END_DATE);
            $holidayName = $report->getData(WeeklyReport::fields_HOLIDAY_NAME);

            $current = ($weekNumber == $this->currentWeekNumber) ? ' ◄ 当前' : '';
            $holiday = $holidayName ? " 【{$holidayName}】" : '';

            $summary = $this->getReportService()->getWeekReportSummary((int) $report->getId());

            $this->output("   第 {$weekNumber} 周{$holiday} ({$startDate} ~ {$endDate}) - {$summary['total']} 个任务{$current}");
        }
    }

    private function cmdWeek(string $args): void
    {
        $weekNumber = (int) trim($args);

        if ($weekNumber < 1 || $weekNumber > 52) {
            $this->output("❌ 无效的周次，请输入 1-52");
            return;
        }

        $this->currentWeekNumber = $weekNumber;
        $this->currentReport = $this->getReportService()->getOrCreateWeekReport($weekNumber, $this->currentYear);

        $this->output("✅ 已切换到第 {$weekNumber} 周");
        $this->cmdStatus('');
    }

    private function cmdLast(string $args): void
    {
        $n = (int) trim($args) ?: 1;
        $targetWeek = $this->getReportService()->getCurrentWeekNumber() - $n;

        if ($targetWeek < 1) {
            $this->output("❌ 无效的周次");
            return;
        }

        $this->cmdWeek((string) $targetWeek);
    }

    private function cmdAdd(string $args): void
    {
        $this->output("\n📝 添加新任务");

        $this->output("任务名称: ");
        $taskName = $this->safeReadLine();

        if (empty($taskName)) {
            $this->output("❌ 任务名称不能为空");
            return;
        }

        $this->output("子任务描述 (可选): ");
        $subTask = $this->safeReadLine();

        $this->output("类别 (如: Demo系统/建站任务/Saas, 可选): ");
        $category = $this->safeReadLine();

        $this->output("状态 (待开始/进行中/测试中/已完成, 默认进行中): ");
        $status = $this->safeReadLine() ?: WeeklyTask::STATUS_IN_PROGRESS;

        $this->output("本周进展: ");
        $progress = $this->safeReadLine();

        $this->output("风险与解决方案 (可选): ");
        $risks = $this->safeReadLine();

        $this->output("下周计划 (可选): ");
        $nextWeekPlan = $this->safeReadLine();

        $taskData = [
            WeeklyTask::fields_TASK_NAME => $taskName,
            WeeklyTask::fields_SUB_TASK => $subTask,
            WeeklyTask::fields_CATEGORY => $category,
            WeeklyTask::fields_STATUS => $status,
            WeeklyTask::fields_PROGRESS => $progress,
            WeeklyTask::fields_RISKS => $risks,
            WeeklyTask::fields_NEXT_WEEK_PLAN => $nextWeekPlan,
            WeeklyTask::fields_START_DATE => date('Y-m-d'),
        ];

        $task = $this->getReportService()->addTask(
            (int) $this->currentReport->getId(),
            $taskData
        );

        $this->output("✅ 任务已添加 (ID: {$task->getId()})");
    }

    private function cmdEdit(string $args): void
    {
        $taskId = (int) trim($args);

        if ($taskId <= 0) {
            $this->output("❌ 请指定任务 ID: /edit <id>");
            return;
        }

        $taskModel = ObjectManager::getInstance(WeeklyTask::class);
        $taskModel->load($taskId);

        if (!$taskModel->getId()) {
            $this->output("❌ 任务不存在: {$taskId}");
            return;
        }

        $this->output("\n📝 编辑任务 #{$taskId}");
        $this->output("当前: {$taskModel->getData(WeeklyTask::fields_TASK_NAME)}");

        $this->output("\n选择要修改的字段:");
        $this->output("  1. 状态");
        $this->output("  2. 进展");
        $this->output("  3. 风险");
        $this->output("  4. 下周计划");
        $this->output("  5. 全部");
        $this->output("  0. 取消");

        $this->output("\n选择 (0-5): ");
        $choice = $this->safeReadLine();

        switch ($choice) {
            case '1':
                $this->output("新状态 (待开始/进行中/测试中/已完成): ");
                $status = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::fields_STATUS => $status]);
                $this->output("✅ 状态已更新");
                break;

            case '2':
                $this->output("新进展: ");
                $progress = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::fields_PROGRESS => $progress]);
                $this->output("✅ 进展已更新");
                break;

            case '3':
                $this->output("风险与解决方案: ");
                $risks = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::fields_RISKS => $risks]);
                $this->output("✅ 风险已更新");
                break;

            case '4':
                $this->output("下周计划: ");
                $plan = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::fields_NEXT_WEEK_PLAN => $plan]);
                $this->output("✅ 下周计划已更新");
                break;

            case '5':
                $this->output("新状态: ");
                $status = $this->safeReadLine();
                $this->output("新进展: ");
                $progress = $this->safeReadLine();
                $this->output("风险: ");
                $risks = $this->safeReadLine();
                $this->output("下周计划: ");
                $plan = $this->safeReadLine();

                $this->getReportService()->updateTask($taskId, [
                    WeeklyTask::fields_STATUS => $status,
                    WeeklyTask::fields_PROGRESS => $progress,
                    WeeklyTask::fields_RISKS => $risks,
                    WeeklyTask::fields_NEXT_WEEK_PLAN => $plan,
                ]);
                $this->output("✅ 任务已更新");
                break;

            default:
                $this->output("❌ 已取消");
        }
    }

    private function cmdDelete(string $args): void
    {
        $taskId = (int) trim($args);

        if ($taskId <= 0) {
            $this->output("❌ 请指定任务 ID: /delete <id>");
            return;
        }

        $this->output("确认删除任务 #{$taskId}? (y/n): ");
        $confirm = $this->safeReadLine();

        if (strtolower($confirm) === 'y') {
            if ($this->getReportService()->deleteTask($taskId)) {
                $this->output("✅ 任务已删除");
            } else {
                $this->output("❌ 删除失败，任务不存在");
            }
        } else {
            $this->output("❌ 已取消");
        }
    }

    private function cmdExit(string $args): void
    {
        $this->running = false;
        $this->output("👋 再见！");
    }

    private function output(string $message): void
    {
        echo $message . "\n";
    }

    public function tip(): string
    {
        return '交互式周报管理器';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'report:chat',
            '交互式周报管理器 - 管理每周工作内容，支持中国节假日，可导出 Excel',
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            [
                '启动周报管理器' => 'php bin/w report:chat',
            ]
        );
    }
}
