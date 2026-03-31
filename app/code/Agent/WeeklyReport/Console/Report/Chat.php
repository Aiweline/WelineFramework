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
use Weline\Framework\Runtime\SchedulerSystem;
/**
 * 周报交互式命令
 * 
 * 命令：report:chat
 * 
 * 支持的斜杠命令：
 * - /status [N] - 查看第N周周报状态（默认本周）
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
        '/organize' => 'cmdOrganize',
        '/org' => 'cmdOrganize',
        '/整理' => 'cmdOrganize',
        '/summary' => 'cmdSummary',
        '/sum' => 'cmdSummary',
        '/报告' => 'cmdSummary',
        '/important' => 'cmdImportant',
        '/imp' => 'cmdImportant',
        '/star' => 'cmdImportant',
        '/priority' => 'cmdPriority',
        '/pri' => 'cmdPriority',
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
                SchedulerSyste::yieldDelay(100);
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
        $this->output("│    /status N - 查看第N周状态     /export - 导出周报        │");
        $this->output("│    /add      - 添加任务          /list   - 周报列表        │");
        $this->output("│    /organize - 整理任务(AI)      /summary - 生成周报总结   │");
        $this->output("│    /week <N> - 切换到第N周       /help   - 帮助            │");
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
        if ($this->isOrganizeRequest($input)) {
            $this->cmdOrganize('');
            return;
        }

        if ($this->isSummaryRequest($input)) {
            $this->cmdSummary('');
            return;
        }

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
            $holidayName = $this->currentReport->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);
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
            $name = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
            $status = $task->getData(WeeklyTask::schema_fields_STATUS);
            $category = $task->getData(WeeklyTask::schema_fields_CATEGORY) ?: '未分类';
            $progress = $task->getData(WeeklyTask::schema_fields_PROGRESS) ?: '';
            $lines[] = "- [{$id}] {$name} ({$status}) [{$category}]" . ($progress ? " - {$progress}" : '');
        }
        return implode("\n", $lines);
    }

    /**
     * 处理 AI 响应中的任务添加标记
     */
    private function processAiResponse(string $response): void
    {
        if (preg_match_all('/\[ADD_TASK:([^|]+)\|([^|]+)\|([^\]]+)\]/', $response, $allMatches, PREG_SET_ORDER)) {
            $existingTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());
            $existingNames = $this->extractExistingTaskNames($existingTasks);
            
            $newMatches = [];
            $duplicates = [];
            
            foreach ($allMatches as $matches) {
                $taskName = trim($matches[1]);
                if ($this->isTaskDuplicate($taskName, $existingNames)) {
                    $duplicates[] = $taskName;
                } else {
                    $newMatches[] = $matches;
                }
            }
            
            if (!empty($duplicates)) {
                $this->output("\n⚠️  跳过重复任务: " . implode(', ', $duplicates));
            }
            
            if (empty($newMatches)) {
                $this->output("📋 所有任务都已存在，无需添加");
                return;
            }
            
            $taskCount = count($newMatches);
            
            if ($taskCount === 1) {
                $this->processSingleTask($newMatches[0]);
            } else {
                $this->output("\n📝 检测到 {$taskCount} 个新任务添加请求:");
                
                foreach ($newMatches as $i => $matches) {
                    $num = $i + 1;
                    $taskName = trim($matches[1]);
                    $status = trim($matches[2]);
                    $category = trim($matches[3]);
                    $this->output("   {$num}. {$taskName} ({$status}, {$category})");
                }
                
                $this->output("\n全部添加? (y=全部/n=取消/s=逐个确认): ");
                $confirm = strtolower($this->safeReadLine());
                
                if ($confirm === 'y' || $confirm === '是') {
                    $addedCount = 0;
                    foreach ($newMatches as $matches) {
                        $task = $this->addTaskFromMatch($matches);
                        if ($task) {
                            $addedCount++;
                        }
                    }
                    $this->output("✅ 已添加 {$addedCount} 个任务");
                } elseif ($confirm === 's' || $confirm === '逐个') {
                    foreach ($newMatches as $matches) {
                        $this->processSingleTask($matches, true);
                    }
                } else {
                    $this->output("❌ 已取消全部添加");
                }
            }
        }
    }

    private function extractExistingTaskNames(array $tasks): array
    {
        $names = [];
        foreach ($tasks as $task) {
            $name = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
            $subTask = $task->getData(WeeklyTask::schema_fields_SUB_TASK);
            if ($name) {
                $names[] = mb_strtolower(trim($name));
            }
            if ($subTask) {
                $names[] = mb_strtolower(trim($subTask));
            }
        }
        return $names;
    }

    private function isTaskDuplicate(string $newTaskName, array $existingNames): bool
    {
        $newNameLower = mb_strtolower(trim($newTaskName));
        
        foreach ($existingNames as $existingName) {
            if ($newNameLower === $existingName) {
                return true;
            }
            if (mb_strlen($newNameLower) > 5 && mb_strlen($existingName) > 5) {
                if (mb_strpos($existingName, $newNameLower) !== false || 
                    mb_strpos($newNameLower, $existingName) !== false) {
                    return true;
                }
            }
            $similarity = 0;
            similar_text($newNameLower, $existingName, $similarity);
            if ($similarity > 80) {
                return true;
            }
        }
        
        return false;
    }

    private function processSingleTask(array $matches, bool $skipDuplicateCheck = false): void
    {
        $taskName = trim($matches[1]);
        $status = trim($matches[2]);
        $category = trim($matches[3]);

        if (!$skipDuplicateCheck) {
            $existingTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());
            $existingNames = $this->extractExistingTaskNames($existingTasks);
            
            if ($this->isTaskDuplicate($taskName, $existingNames)) {
                $this->output("\n⚠️  任务「{$taskName}」已存在或与现有任务相似，跳过");
                return;
            }
        }

        $this->output("\n📝 检测到任务添加请求:");
        $this->output("   任务名: {$taskName}");
        $this->output("   状态: {$status}");
        $this->output("   类别: {$category}");
        $this->output("\n确认添加此任务? (y/n): ");

        $confirm = $this->safeReadLine();

        if (strtolower($confirm) === 'y' || $confirm === '是') {
            $task = $this->addTaskFromMatch($matches);
            if ($task) {
                $this->output("✅ 任务已添加 (ID: {$task->getId()})");
            }
        } else {
            $this->output("❌ 已取消添加");
        }
    }

    private function addTaskFromMatch(array $matches): ?WeeklyTask
    {
        $taskName = trim($matches[1]);
        $status = trim($matches[2]);
        $category = trim($matches[3]);

        $taskData = [
            WeeklyTask::schema_fields_TASK_NAME => $taskName,
            WeeklyTask::schema_fields_STATUS => $status,
            WeeklyTask::schema_fields_CATEGORY => $category,
            WeeklyTask::schema_fields_START_DATE => date('Y-m-d'),
        ];

        return $this->getReportService()->addTask(
            (int) $this->currentReport->getId(),
            $taskData
        );
    }

    private function cmdHelp(string $args): void
    {
        $this->output("
📚 周报管理器命令:

  /status [N]      查看第 N 周状态（默认本周）
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
  /organize        整理本周任务（AI 合并/拆分/清理）
  /org             同上
  /summary         生成周报总结（上周概述+本周详情+下周计划）
  /sum             同上
  /important <id>  切换任务的重点标记 ⭐
  /star <id>       同上
  /priority <id> <1-4>  设置优先级（1低/2普通/3高/4紧急）
  /pri <id> <1-4>  同上
  /exit            退出
  /q               同上

  直接输入工作描述，AI 会自动解析为任务。
  输入「整理」或「整理周报」也会触发任务整理。
");
    }

    private function cmdStatus(string $args): void
    {
        $args = trim($args);
        
        if (!empty($args) && is_numeric($args)) {
            $weekNumber = (int) $args;
            if ($weekNumber < 1 || $weekNumber > 53) {
                $this->output("❌ 无效的周次: {$weekNumber}（有效范围 1-53）");
                return;
            }
            $report = $this->getReportService()->getWeekReport($weekNumber, $this->currentYear);
            if (!$report) {
                $this->output("❌ 第 {$weekNumber} 周暂无周报数据");
                return;
            }
        } else {
            $report = $this->currentReport;
            $weekNumber = $this->currentWeekNumber;
        }
        
        $dateRange = $this->getReportService()->getWeekDateRange($weekNumber);

        $holidayName = $report->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);
        $weekTitle = $holidayName ? "第 {$weekNumber} 周【{$holidayName}】" : "第 {$weekNumber} 周";
        
        $isCurrent = ($weekNumber == $this->currentWeekNumber) ? ' ◄ 当前' : '';

        $this->output("\n📊 {$weekTitle} 周报状态{$isCurrent}");
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
                $name = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
                $subTask = $task->getData(WeeklyTask::schema_fields_SUB_TASK);
                $status = $task->getData(WeeklyTask::schema_fields_STATUS);
                $priority = (int) ($task->getData(WeeklyTask::schema_fields_PRIORITY) ?: WeeklyTask::PRIORITY_NORMAL);
                $isImportant = (bool) $task->getData(WeeklyTask::schema_fields_IS_IMPORTANT);
                $endDate = $task->getData(WeeklyTask::schema_fields_END_DATE);

                $startDate = $task->getData(WeeklyTask::schema_fields_START_DATE);

                $statusIcon = match ($status) {
                    WeeklyTask::STATUS_COMPLETED, '完成', '已完成' => '✅',
                    WeeklyTask::STATUS_IN_PROGRESS => '🔄',
                    WeeklyTask::STATUS_TESTING, '测试中' => '🧪',
                    WeeklyTask::STATUS_TODO, '待开始' => '⏳',
                    default => '📌',
                };

                $importantMark = $isImportant ? '⭐' : '';
                $priorityColor = $task->getPriorityColor();
                $displayName = $subTask ?: $name;

                $dateInfo = '';
                if ($startDate || $endDate) {
                    $start = $startDate ? date('m/d', strtotime($startDate)) : '?';
                    $end = $endDate ? date('m/d', strtotime($endDate)) : '?';
                    $dateInfo = " [{$start}~{$end}]";
                }

                $deadlineWarn = '';
                if ($endDate && $status !== WeeklyTask::STATUS_COMPLETED) {
                    $daysLeft = (strtotime($endDate) - strtotime('today')) / 86400;
                    if ($daysLeft < 0) {
                        $deadlineWarn = " \033[31m⚠已逾期" . abs((int)$daysLeft) . "天\033[0m";
                    } elseif ($daysLeft == 0) {
                        $deadlineWarn = " \033[31m⚠今日截止\033[0m";
                    } elseif ($daysLeft <= 2) {
                        $deadlineWarn = " \033[33m⚠{$daysLeft}天后截止\033[0m";
                    }
                }

                $line = "   {$statusIcon}{$importantMark} [{$id}] \033[{$priorityColor}m{$displayName}\033[0m{$dateInfo} ({$status}){$deadlineWarn}";
                $this->output($line);
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
            $weekNumber = $report->getData(WeeklyReport::schema_fields_WEEK_NUMBER);
            $startDate = $report->getData(WeeklyReport::schema_fields_WEEK_START_DATE);
            $endDate = $report->getData(WeeklyReport::schema_fields_WEEK_END_DATE);
            $holidayName = $report->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);

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
            WeeklyTask::schema_fields_TASK_NAME => $taskName,
            WeeklyTask::schema_fields_SUB_TASK => $subTask,
            WeeklyTask::schema_fields_CATEGORY => $category,
            WeeklyTask::schema_fields_STATUS => $status,
            WeeklyTask::schema_fields_PROGRESS => $progress,
            WeeklyTask::schema_fields_RISKS => $risks,
            WeeklyTask::schema_fields_NEXT_WEEK_PLAN => $nextWeekPlan,
            WeeklyTask::schema_fields_START_DATE => date('Y-m-d'),
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
        $this->output("当前: {$taskModel->getData(WeeklyTask::schema_fields_TASK_NAME)}");

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
                $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_STATUS => $status]);
                $this->output("✅ 状态已更新");
                break;

            case '2':
                $this->output("新进展: ");
                $progress = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_PROGRESS => $progress]);
                $this->output("✅ 进展已更新");
                break;

            case '3':
                $this->output("风险与解决方案: ");
                $risks = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_RISKS => $risks]);
                $this->output("✅ 风险已更新");
                break;

            case '4':
                $this->output("下周计划: ");
                $plan = $this->safeReadLine();
                $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_NEXT_WEEK_PLAN => $plan]);
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
                    WeeklyTask::schema_fields_STATUS => $status,
                    WeeklyTask::schema_fields_PROGRESS => $progress,
                    WeeklyTask::schema_fields_RISKS => $risks,
                    WeeklyTask::schema_fields_NEXT_WEEK_PLAN => $plan,
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

    private function cmdImportant(string $args): void
    {
        $taskId = (int) trim($args);

        if ($taskId <= 0) {
            $this->output("❌ 请指定任务 ID: /important <id>");
            return;
        }

        $task = $this->getReportService()->getTask($taskId);
        if (!$task) {
            $this->output("❌ 任务不存在");
            return;
        }

        $isImportant = (bool) $task->getData(WeeklyTask::schema_fields_IS_IMPORTANT);
        $newValue = $isImportant ? 0 : 1;
        
        $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_IS_IMPORTANT => $newValue]);
        
        if ($newValue) {
            $this->output("⭐ 任务 #{$taskId} 已标记为重点");
        } else {
            $this->output("✅ 任务 #{$taskId} 已取消重点标记");
        }
    }

    private function cmdPriority(string $args): void
    {
        $parts = preg_split('/\s+/', trim($args));
        $taskId = (int) ($parts[0] ?? 0);
        $priority = (int) ($parts[1] ?? 0);

        if ($taskId <= 0) {
            $this->output("❌ 用法: /priority <任务ID> <优先级1-4>");
            $this->output("   1=低  2=普通  3=高  4=紧急");
            return;
        }

        if ($priority < 1 || $priority > 4) {
            $this->output("请选择优先级 (1=低 2=普通 3=高 4=紧急): ");
            $priority = (int) $this->safeReadLine();
            if ($priority < 1 || $priority > 4) {
                $this->output("❌ 无效的优先级");
                return;
            }
        }

        $task = $this->getReportService()->getTask($taskId);
        if (!$task) {
            $this->output("❌ 任务不存在");
            return;
        }

        $this->getReportService()->updateTask($taskId, [WeeklyTask::schema_fields_PRIORITY => $priority]);
        
        $priorityNames = ['', '低', '普通', '高', '紧急'];
        $this->output("✅ 任务 #{$taskId} 优先级已设为「{$priorityNames[$priority]}」");
    }

    private function cmdExit(string $args): void
    {
        $this->running = false;
        $this->output("👋 再见！");
    }

    private function isOrganizeRequest(string $input): bool
    {
        $keywords = ['整理', '整理任务', '整理周报', '整理一下', '帮我整理', '清理任务', '合并任务'];
        $inputLower = mb_strtolower(trim($input));
        
        foreach ($keywords as $keyword) {
            if ($inputLower === $keyword || mb_strpos($inputLower, $keyword) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isSummaryRequest(string $input): bool
    {
        $keywords = ['生成周报', '周报总结', '生成报告', '汇报', '写周报'];
        $inputLower = mb_strtolower(trim($input));
        
        foreach ($keywords as $keyword) {
            if ($inputLower === $keyword || mb_strpos($inputLower, $keyword) === 0) {
                return true;
            }
        }
        return false;
    }

    private function cmdOrganize(string $args): void
    {
        $cursorAi = $this->getCursorAi();

        if (!$cursorAi->isAvailable()) {
            $this->output("\n❌ Cursor IDE 未运行，无法使用 AI 整理功能");
            return;
        }

        $currentTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());

        if (empty($currentTasks)) {
            $this->output("\n📋 本周暂无任务，无需整理");
            return;
        }

        $this->output("\n🔄 正在分析并整理本周任务...");
        $this->output("   当前有 " . count($currentTasks) . " 个任务\n");

        $taskListDetail = $this->formatTaskListForOrganize($currentTasks);
        $dateRange = $this->getReportService()->getWeekDateRange($this->currentWeekNumber, $this->currentYear);
        $todayDate = date('Y-m-d');

        $organizePrompt = <<<PROMPT
你是一个周报整理专家。今天是 {$todayDate}，当前是第 {$this->currentWeekNumber} 周（{$dateRange['start']} ~ {$dateRange['end']}）。

当前周报任务列表：
{$taskListDetail}

请分析这些任务，按以下原则整理：

1. **合并相似任务**：如果多个任务属于同一个项目或功能，建议合并
2. **拆分粗粒度任务**：如果某个任务过于笼统，建议拆分为具体子任务
3. **标记需删除的任务**：重复的、已过时的、不再相关的任务
4. **调整任务状态**：根据描述和时间判断状态是否准确
5. **补充缺失信息**：如果任务缺少进展或计划，给出建议

请按以下格式输出整理建议：

## 建议操作

### 删除任务（不再需要）
[DELETE:任务ID|原因]

### 合并任务
[MERGE:保留ID|删除ID1,删除ID2|合并后的任务名]

### 拆分任务
[SPLIT:原任务ID|子任务1名称|子任务2名称|...]

### 更新任务
[UPDATE:任务ID|字段:新值|字段2:新值2]
（字段可选：status/progress/risks/next_week_plan）

### 新增任务（发现遗漏）
[ADD_TASK:任务名|状态|类别]

## 整理总结
简要说明本次整理的要点。

完成后添加 [RESPONSE_COMPLETE]
PROMPT;

        $result = $cursorAi->chatStream(
            $organizePrompt,
            '',
            [],
            function (string $chunk) {
                $filtered = str_replace('[RESPONSE_COMPLETE]', '', $chunk);
                if ($filtered !== '') {
                    echo $filtered;
                    flush();
                }
            },
            180
        );

        echo "\n";

        if ($result['success']) {
            $response = str_replace('[RESPONSE_COMPLETE]', '', $result['response']);
            $this->processOrganizeResponse($response);
        } else {
            $error = $result['error'] ?? '未知错误';
            $this->output("\n❌ AI 整理失败: {$error}");
        }
    }

    private function formatTaskListForOrganize(array $tasks): string
    {
        $lines = [];
        foreach ($tasks as $task) {
            $id = $task->getId();
            $name = $task->getData(WeeklyTask::schema_fields_TASK_NAME);
            $subTask = $task->getData(WeeklyTask::schema_fields_SUB_TASK);
            $status = $task->getData(WeeklyTask::schema_fields_STATUS);
            $category = $task->getData(WeeklyTask::schema_fields_CATEGORY);
            $progress = $task->getData(WeeklyTask::schema_fields_PROGRESS);
            $risks = $task->getData(WeeklyTask::schema_fields_RISKS);
            $startDate = $task->getData(WeeklyTask::schema_fields_START_DATE);
            $endDate = $task->getData(WeeklyTask::schema_fields_END_DATE);
            $nextPlan = $task->getData(WeeklyTask::schema_fields_NEXT_WEEK_PLAN);

            $line = "- ID:{$id} | 任务:{$name}";
            if ($subTask) {
                $line .= " | 子任务:{$subTask}";
            }
            $line .= " | 状态:{$status}";
            if ($category) {
                $line .= " | 类别:{$category}";
            }
            if ($startDate) {
                $line .= " | 开始:{$startDate}";
            }
            if ($endDate) {
                $line .= " | 截止:{$endDate}";
            }
            if ($progress) {
                $line .= " | 进展:{$progress}";
            }
            if ($risks) {
                $line .= " | 风险:{$risks}";
            }
            if ($nextPlan) {
                $line .= " | 下周:{$nextPlan}";
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private function processOrganizeResponse(string $response): void
    {
        $hasActions = false;
        $deleteCount = 0;
        $mergeCount = 0;
        $updateCount = 0;
        $addCount = 0;

        if (preg_match_all('/\[DELETE:(\d+)\|([^\]]+)\]/', $response, $deleteMatches, PREG_SET_ORDER)) {
            foreach ($deleteMatches as $match) {
                $taskId = (int) $match[1];
                $reason = trim($match[2]);
                
                $this->output("\n🗑️  建议删除任务 #{$taskId}: {$reason}");
                $this->output("   确认删除? (y/n): ");
                $confirm = $this->safeReadLine();
                
                if (strtolower($confirm) === 'y') {
                    $this->getReportService()->deleteTask($taskId);
                    $this->output("   ✅ 已删除");
                    $deleteCount++;
                } else {
                    $this->output("   ⏭️  跳过");
                }
                $hasActions = true;
            }
        }

        if (preg_match_all('/\[UPDATE:(\d+)\|([^\]]+)\]/', $response, $updateMatches, PREG_SET_ORDER)) {
            foreach ($updateMatches as $match) {
                $taskId = (int) $match[1];
                $updates = trim($match[2]);
                
                $this->output("\n📝 建议更新任务 #{$taskId}: {$updates}");
                $this->output("   确认更新? (y/n): ");
                $confirm = $this->safeReadLine();
                
                if (strtolower($confirm) === 'y') {
                    $updateData = $this->parseUpdateString($updates);
                    if (!empty($updateData)) {
                        $this->getReportService()->updateTask($taskId, $updateData);
                        $this->output("   ✅ 已更新");
                        $updateCount++;
                    }
                } else {
                    $this->output("   ⏭️  跳过");
                }
                $hasActions = true;
            }
        }

        if (preg_match_all('/\[ADD_TASK:([^|]+)\|([^|]+)\|([^\]]+)\]/', $response, $addMatches, PREG_SET_ORDER)) {
            $existingTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());
            $existingNames = $this->extractExistingTaskNames($existingTasks);

            foreach ($addMatches as $match) {
                $taskName = trim($match[1]);

                if ($this->isTaskDuplicate($taskName, $existingNames)) {
                    $this->output("\n⚠️  跳过重复任务: {$taskName}");
                    continue;
                }

                $this->output("\n➕ 建议新增任务: {$taskName}");
                $this->output("   确认添加? (y/n): ");
                $confirm = $this->safeReadLine();

                if (strtolower($confirm) === 'y') {
                    $this->addTaskFromMatch($match);
                    $this->output("   ✅ 已添加");
                    $addCount++;
                    $existingNames[] = mb_strtolower($taskName);
                } else {
                    $this->output("   ⏭️  跳过");
                }
                $hasActions = true;
            }
        }

        if ($hasActions) {
            $this->output("\n📊 整理完成:");
            if ($deleteCount > 0) $this->output("   删除: {$deleteCount} 个");
            if ($updateCount > 0) $this->output("   更新: {$updateCount} 个");
            if ($addCount > 0) $this->output("   新增: {$addCount} 个");
        }
    }

    private function parseUpdateString(string $updates): array
    {
        $data = [];
        $parts = explode('|', $updates);
        
        foreach ($parts as $part) {
            if (strpos($part, ':') !== false) {
                [$field, $value] = explode(':', $part, 2);
                $field = trim($field);
                $value = trim($value);
                
                $fieldMap = [
                    'status' => WeeklyTask::schema_fields_STATUS,
                    '状态' => WeeklyTask::schema_fields_STATUS,
                    'progress' => WeeklyTask::schema_fields_PROGRESS,
                    '进展' => WeeklyTask::schema_fields_PROGRESS,
                    'risks' => WeeklyTask::schema_fields_RISKS,
                    '风险' => WeeklyTask::schema_fields_RISKS,
                    'next_week_plan' => WeeklyTask::schema_fields_NEXT_WEEK_PLAN,
                    '下周计划' => WeeklyTask::schema_fields_NEXT_WEEK_PLAN,
                    'end_date' => WeeklyTask::schema_fields_END_DATE,
                    '截止' => WeeklyTask::schema_fields_END_DATE,
                ];
                
                if (isset($fieldMap[$field])) {
                    $data[$fieldMap[$field]] = $value;
                }
            }
        }
        
        return $data;
    }

    private function cmdSummary(string $args): void
    {
        $cursorAi = $this->getCursorAi();

        if (!$cursorAi->isAvailable()) {
            $this->output("\n❌ Cursor IDE 未运行，无法生成周报总结");
            return;
        }

        $this->output("\n📝 正在生成周报总结...\n");

        $lastWeekNumber = $this->currentWeekNumber - 1;
        $lastWeekReport = null;
        $lastWeekTasks = [];
        
        if ($lastWeekNumber > 0) {
            $lastWeekReport = $this->getReportService()->getWeekReport($lastWeekNumber, $this->currentYear);
            if ($lastWeekReport) {
                $lastWeekTasks = $this->getReportService()->getWeekTasks((int) $lastWeekReport->getId());
            }
        }

        $currentTasks = $this->getReportService()->getWeekTasks((int) $this->currentReport->getId());
        $currentDateRange = $this->getReportService()->getWeekDateRange($this->currentWeekNumber, $this->currentYear);
        $lastWeekDateRange = $lastWeekNumber > 0 ? $this->getReportService()->getWeekDateRange($lastWeekNumber, $this->currentYear) : null;

        $lastWeekTaskList = !empty($lastWeekTasks) ? $this->formatTaskListForOrganize($lastWeekTasks) : '（无数据）';
        $currentTaskList = !empty($currentTasks) ? $this->formatTaskListForOrganize($currentTasks) : '（无任务）';

        $todayDate = date('Y-m-d');
        $holidayInfo = '';
        if ($this->currentReport && $this->currentReport->getData(WeeklyReport::schema_fields_IS_HOLIDAY_WEEK)) {
            $holidayName = $this->currentReport->getData(WeeklyReport::schema_fields_HOLIDAY_NAME);
            $holidayInfo = "（本周是 {$holidayName} 假期周）";
        }

        $summaryPrompt = <<<PROMPT
你是一个专业的周报撰写助手。今天是 {$todayDate}。{$holidayInfo}

## 上周（第 {$lastWeekNumber} 周）任务：
{$lastWeekTaskList}

## 本周（第 {$this->currentWeekNumber} 周，{$currentDateRange['start']} ~ {$currentDateRange['end']}）任务：
{$currentTaskList}

请按以下格式生成周报总结：

---

## 一、上周工作回顾

（简要总结上周主要完成的工作，突出成果和亮点，2-3 句话）

## 二、本周工作详情

### 已完成
- 任务1：完成情况
- 任务2：完成情况

### 进行中
- 任务1：当前进度
- 任务2：当前进度

### 测试中
- 任务1：测试情况

### 待开始
- 任务1：计划安排

## 三、风险与问题

（列出当前遇到的困难或风险，以及解决方案或需要的支持）

## 四、下周计划

- 计划1
- 计划2
- 计划3

## 五、需要的支持

（如需要其他部门/人员配合，资源申请等，如无则写"暂无"）

---

请基于任务列表内容生成专业、简洁的周报。完成后添加 [RESPONSE_COMPLETE]
PROMPT;

        $result = $cursorAi->chatStream(
            $summaryPrompt,
            '',
            [],
            function (string $chunk) {
                $filtered = str_replace('[RESPONSE_COMPLETE]', '', $chunk);
                if ($filtered !== '') {
                    echo $filtered;
                    flush();
                }
            },
            180
        );

        echo "\n";

        if (!$result['success']) {
            $error = $result['error'] ?? '未知错误';
            $this->output("\n❌ 生成失败: {$error}");
        }
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
