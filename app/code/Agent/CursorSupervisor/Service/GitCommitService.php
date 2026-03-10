<?php

declare(strict_types=1);

namespace Agent\CursorSupervisor\Service;

use Agent\CursorBase\Service\CursorAiService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 智能 Git Commit 服务
 * 
 * 职责：
 * 1. 分析未提交的文件变更
 * 2. 判断是单个功能还是多组功能
 * 3. 使用 AI 生成清晰的提交信息
 * 4. 支持分组提交
 */
class GitCommitService
{
    private ?CursorAiService $cursorAi = null;
    private bool $verbose = false;
    
    /** @var callable|null 进度回调 */
    private $progressCallback = null;
    
    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }
    
    /**
     * 设置进度回调
     * @param callable $callback function(string $message, string $type = 'info')
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }
    
    /**
     * 输出进度信息
     */
    private function progress(string $message, string $type = 'info'): void
    {
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, $message, $type);
        }
    }
    
    private function getCursorAi(): CursorAiService
    {
        if ($this->cursorAi === null) {
            $this->cursorAi = ObjectManager::getInstance(CursorAiService::class);
            $this->cursorAi->setVerbose($this->verbose);
        }
        return $this->cursorAi;
    }
    
    /**
     * 获取 Git 状态
     */
    public function getStatus(): array
    {
        $output = [];
        exec('git status --porcelain 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return ['error' => implode("\n", $output)];
        }
        
        $files = [
            'staged' => [],
            'modified' => [],
            'untracked' => [],
            'deleted' => [],
            'renamed' => [],
        ];
        
        foreach ($output as $line) {
            if (empty(trim($line))) continue;
            
            $status = substr($line, 0, 2);
            $file = trim(substr($line, 3));
            
            // 处理重命名
            if (str_contains($file, ' -> ')) {
                $parts = explode(' -> ', $file);
                $file = $parts[1];
            }
            
            $index = $status[0];
            $worktree = $status[1];
            
            if ($index === 'A' || $index === 'M' || $index === 'D' || $index === 'R') {
                $files['staged'][] = ['file' => $file, 'status' => $index];
            }
            
            if ($worktree === 'M') {
                $files['modified'][] = $file;
            } elseif ($worktree === 'D') {
                $files['deleted'][] = $file;
            } elseif ($status === '??') {
                $files['untracked'][] = $file;
            } elseif ($index === 'R') {
                $files['renamed'][] = $file;
            }
        }
        
        return $files;
    }
    
    /**
     * 获取文件差异
     */
    public function getDiff(array $files = [], bool $staged = false): string
    {
        $cmd = $staged ? 'git diff --cached' : 'git diff';
        
        if (!empty($files)) {
            $cmd .= ' -- ' . implode(' ', array_map('escapeshellarg', $files));
        }
        
        $output = [];
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        return implode("\n", $output);
    }
    
    /**
     * 分析变更并分组
     */
    public function analyzeChanges(): array
    {
        $status = $this->getStatus();
        
        if (isset($status['error'])) {
            return $status;
        }
        
        // 收集所有变更文件
        $allFiles = [];
        foreach ($status['staged'] as $item) {
            $allFiles[] = ['file' => $item['file'], 'type' => 'staged', 'status' => $item['status']];
        }
        foreach ($status['modified'] as $file) {
            $allFiles[] = ['file' => $file, 'type' => 'modified', 'status' => 'M'];
        }
        foreach ($status['untracked'] as $file) {
            $allFiles[] = ['file' => $file, 'type' => 'untracked', 'status' => 'A'];
        }
        foreach ($status['deleted'] as $file) {
            $allFiles[] = ['file' => $file, 'type' => 'deleted', 'status' => 'D'];
        }
        
        if (empty($allFiles)) {
            return ['groups' => [], 'message' => 'Nothing to commit'];
        }
        
        // 按模块分组
        $groups = $this->groupByModule($allFiles);
        
        // 分析每组的功能
        foreach ($groups as $module => &$group) {
            $group['analysis'] = $this->analyzeGroup($group['files']);
        }
        
        return [
            'total_files' => count($allFiles),
            'groups' => $groups,
            'status' => $status,
        ];
    }
    
    /**
     * 按模块分组
     */
    private function groupByModule(array $files): array
    {
        $groups = [];
        
        foreach ($files as $fileInfo) {
            $file = $fileInfo['file'];
            $module = $this->detectModule($file);
            
            if (!isset($groups[$module])) {
                $groups[$module] = [
                    'module' => $module,
                    'files' => [],
                    'types' => [],
                ];
            }
            
            $groups[$module]['files'][] = $fileInfo;
            $fileType = $this->detectFileType($file);
            if (!in_array($fileType, $groups[$module]['types'])) {
                $groups[$module]['types'][] = $fileType;
            }
        }
        
        return $groups;
    }
    
    /**
     * 检测模块
     */
    private function detectModule(string $file): string
    {
        // app/code/Vendor/Module/...
        if (preg_match('#app/code/([^/]+/[^/]+)/#', $file, $match)) {
            return $match[1];
        }
        
        // dev/ai/...
        if (str_starts_with($file, 'dev/ai/')) {
            return 'dev/ai';
        }
        
        // .cursor/...
        if (str_starts_with($file, '.cursor/')) {
            return '.cursor';
        }
        
        // 根目录文件
        return 'root';
    }
    
    /**
     * 检测文件类型
     */
    private function detectFileType(string $file): string
    {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        
        $typeMap = [
            'php' => 'PHP',
            'phtml' => 'Template',
            'css' => 'Style',
            'js' => 'JavaScript',
            'json' => 'Config',
            'xml' => 'Config',
            'md' => 'Doc',
            'csv' => 'i18n',
        ];
        
        if (isset($typeMap[$ext])) {
            return $typeMap[$ext];
        }
        
        // 按路径判断
        if (str_contains($file, '/Controller/')) return 'Controller';
        if (str_contains($file, '/Model/')) return 'Model';
        if (str_contains($file, '/Service/')) return 'Service';
        if (str_contains($file, '/Console/')) return 'Command';
        if (str_contains($file, '/view/')) return 'View';
        if (str_contains($file, '/i18n/')) return 'i18n';
        if (str_contains($file, '/Test/')) return 'Test';
        
        return 'Other';
    }
    
    /**
     * 分析分组内容
     */
    private function analyzeGroup(array $files): array
    {
        $analysis = [
            'action' => 'update',
            'components' => [],
            'summary' => '',
        ];
        
        $hasNew = false;
        $hasModified = false;
        $hasDeleted = false;
        
        foreach ($files as $file) {
            $status = $file['status'];
            if ($status === 'A') $hasNew = true;
            if ($status === 'M') $hasModified = true;
            if ($status === 'D') $hasDeleted = true;
            
            $type = $this->detectFileType($file['file']);
            if (!in_array($type, $analysis['components'])) {
                $analysis['components'][] = $type;
            }
        }
        
        // 判断主要动作
        if ($hasNew && !$hasModified && !$hasDeleted) {
            $analysis['action'] = 'add';
        } elseif ($hasDeleted && !$hasNew) {
            $analysis['action'] = 'remove';
        } elseif ($hasModified) {
            $analysis['action'] = 'update';
        }
        
        return $analysis;
    }
    
    /**
     * 使用 AI 生成提交信息
     */
    public function generateCommitMessage(array $group, bool $useAi = true): string
    {
        $module = $group['module'];
        $files = $group['files'];
        $analysis = $group['analysis'] ?? $this->analyzeGroup($files);
        
        // 简单规则生成（中文）
        $action = match ($analysis['action']) {
            'add' => 'feat',
            'remove' => 'chore',
            default => 'update',
        };
        
        $actionDesc = match ($analysis['action']) {
            'add' => '添加',
            'remove' => '移除',
            default => '更新',
        };
        
        $components = implode(', ', $analysis['components']);
        $fileCount = count($files);
        
        $simpleMessage = "{$action}({$module}): {$actionDesc} {$components} ({$fileCount} 个文件)";
        
        if (!$useAi) {
            return $simpleMessage;
        }
        
        try {
            $cursorAi = $this->getCursorAi();
            
            if (!$cursorAi->isAvailable()) {
                $this->progress("⚠️ Cursor CLI 不可用，使用简单规则", 'note');
                return $simpleMessage;
            }

            $diff = $this->getGroupDiff($files);
            $prompt = $this->buildCommitPrompt($module, $files, $analysis, $diff);
            
            // 120 秒超时，超时后自动用规则生成
            $result = $cursorAi->chat($prompt, '', [], 120);
            
            if ($result['success'] && !empty($result['response'])) {
                return trim($result['response']);
            }
            
            // AI 调用失败，输出原因
            $error = $result['error'] ?? '未知错误';
            $this->progress("⚠️ AI 生成失败: {$error}，使用简单规则", 'note');
        } catch (\Exception $e) {
            $this->progress("⚠️ AI 异常: " . $e->getMessage() . "，使用简单规则", 'note');
            $this->log("AI 生成失败: " . $e->getMessage());
        }
        
        return $simpleMessage;
    }
    
    /**
     * 获取分组的 diff
     */
    private function getGroupDiff(array $files): string
    {
        $fileList = array_map(fn($f) => $f['file'], $files);
        $diff = $this->getDiff($fileList);
        
        // 限制长度
        if (strlen($diff) > 3000) {
            $diff = substr($diff, 0, 3000) . "\n... (truncated)";
        }
        
        return $diff;
    }
    
    /**
     * 构建提交信息 prompt
     */
    private function buildCommitPrompt(string $module, array $files, array $analysis, string $diff): string
    {
        $fileList = implode("\n", array_map(fn($f) => "- {$f['status']} {$f['file']}", $files));
        $action = $analysis['action'];
        $components = implode(', ', $analysis['components']);
        
        return <<<PROMPT
你是一个专业的代码提交助手。请根据以下变更生成一个清晰的 Git 提交信息。

## 变更概况
- 模块: {$module}
- 动作: {$action}
- 组件: {$components}

## 变更文件
{$fileList}

## 代码差异
```
{$diff}
```

## 要求
1. 使用中文，遵循 Conventional Commits 格式
2. 第一行不超过 72 字符
3. 如有必要，空一行后添加详细说明
4. 格式: <type>(<scope>): <中文描述>
5. type: feat/fix/docs/style/refactor/test/chore
6. scope: 模块名或功能名（英文）
7. 描述部分使用中文，简洁明了

示例：
- feat(Server): 添加批量强制重启 Worker 功能
- fix(Console): 修复空命令时的参数检查
- docs(Backend): 更新通知系统文档
- refactor(Ai): 重构适配器扫描逻辑

只输出提交信息，不要其他内容。
PROMPT;
    }
    
    /**
     * 执行提交（遇权限不足时提示 sudo 修复并重试一次）
     */
    public function commit(array $files, string $message): array
    {
        $result = $this->doCommit($files, $message);
        if ($result['success']) {
            return $result;
        }
        $output = $result['output'];
        if ($this->isPermissionDeniedOutput($output)) {
            $dirs = $this->extractPermissionDeniedDirs($output);
            if ($dirs !== []) {
                $this->progress('⚠️ 检测到目录权限不足，需要 sudo 修复。请在下方输入当前用户密码后回车。', 'note');
                if ($this->fixPermissionWithSudo($dirs)) {
                    $this->progress('🔄 权限已修复，正在重试提交...', 'info');
                    return $this->doCommit($files, $message);
                }
            }
        }
        return $result;
    }

    /**
     * 实际执行 git add + git commit
     */
    private function doCommit(array $files, string $message): array
    {
        $output = [];
        foreach ($files as $file) {
            $filePath = $file['file'];
            $status = $file['status'] ?? 'M';
            if ($status === 'D') {
                exec('git rm ' . escapeshellarg($filePath) . ' 2>&1', $output);
            } else {
                exec('git add ' . escapeshellarg($filePath) . ' 2>&1', $output);
            }
        }
        $cmd = 'git commit -m ' . escapeshellarg($message) . ' 2>&1';
        $output = [];
        exec($cmd, $output, $returnCode);
        return [
            'success' => $returnCode === 0,
            'message' => $message,
            'output' => implode("\n", $output),
        ];
    }

    private function isPermissionDeniedOutput(string $output): bool
    {
        return str_contains($output, '权限不够') || str_contains($output, '权限不足')
            || str_contains($output, 'Permission denied') || str_contains($output, '无法打开目录');
    }

    /**
     * 从 git 警告中解析出无法访问的目录路径（相对或绝对）
     * 例如：无法打开目录 'app/code/Weline/Component/view/tpl/': 权限不够
     */
    private function extractPermissionDeniedDirs(string $output): array
    {
        if (preg_match_all("/无法打开目录\s*'([^']+)'/u", $output, $m)) {
            return array_values(array_unique($m[1]));
        }
        if (preg_match_all("/Permission denied[^\n]*['\"]?([\/\w.-]+)['\"]?/", $output, $m)) {
            return array_values(array_unique($m[1]));
        }
        return [];
    }

    /**
     * 使用 sudo chown 修复目录归属，会提示用户输入密码（仅 Linux/macOS）
     */
    private function fixPermissionWithSudo(array $dirs): bool
    {
        $os = PHP_OS_FAMILY ?? '';
        if ($os !== 'Linux' && $os !== 'Darwin') {
            $this->progress('当前系统不支持自动 sudo 修复，请手动执行 chown 后重试。', 'note');
            return false;
        }
        $root = getcwd() ?: '';
        if ($root === '') {
            return false;
        }
        $user = trim((string) (shell_exec('whoami') ?? ''));
        if ($user === '') {
            return false;
        }
        $absDirs = [];
        foreach ($dirs as $d) {
            $path = str_starts_with($d, '/') ? $d : $root . '/' . $d;
            if (is_dir($path)) {
                $absDirs[] = $path;
            }
        }
        if ($absDirs === []) {
            return false;
        }
        $list = implode(' ', array_map('escapeshellarg', $absDirs));
        $cmd = "sudo chown -R " . escapeshellarg($user) . " " . $list . " 2>&1";
        $this->progress("执行: sudo chown -R {$user} <目录>", 'note');
        passthru($cmd, $exitCode);
        return $exitCode === 0;
    }
    
    /**
     * 智能提交（自动分组）
     */
    public function smartCommit(bool $dryRun = false, bool $useAi = true): array
    {
        $this->progress('🔍 分析变更...', 'info');
        $analysis = $this->analyzeChanges();

        if (isset($analysis['error'])) {
            return $analysis;
        }

        if (empty($analysis['groups'])) {
            return ['success' => true, 'message' => 'Nothing to commit'];
        }

        $totalGroups = count($analysis['groups']);
        $this->progress("📊 共 {$totalGroups} 个分组，{$analysis['total_files']} 个文件", 'info');

        $results = [];
        $current = 0;

        foreach ($analysis['groups'] as $module => $group) {
            $current++;
            $fileCount = count($group['files']);
            
            $this->progress("", 'info');
            $this->progress("━━━ [{$current}/{$totalGroups}] {$module} ({$fileCount} 文件) ━━━", 'info');
            
            if ($useAi) {
                $this->progress("🤖 AI 生成提交信息...（最多 120 秒，超时将用规则生成）", 'info');
            }
            
            $message = $this->generateCommitMessage($group, $useAi);

            $this->log("📝 {$module}: {$message}");

            if ($dryRun) {
                $this->progress("📝 预览: {$message}", 'note');
                $results[] = [
                    'module' => $module,
                    'message' => $message,
                    'files' => $fileCount,
                    'dry_run' => true,
                ];
            } else {
                $this->progress("⏳ 提交中...", 'info');
                $result = $this->commit($group['files'], $message);
                $result['module'] = $module;
                $results[] = $result;

                if ($result['success']) {
                    $this->progress("✅ 提交成功: {$message}", 'success');
                    $this->log("✅ 提交成功");
                } else {
                    $this->progress("❌ 提交失败: " . $result['output'], 'error');
                    $this->log("❌ 提交失败: " . $result['output']);
                }
            }
        }

        $this->progress("", 'info');
        $this->progress("🎉 完成！共处理 {$totalGroups} 个分组", 'success');

        return [
            'success' => true,
            'total_groups' => $totalGroups,
            'total_files' => $analysis['total_files'],
            'commits' => $results,
        ];
    }
    
    /**
     * 获取最近提交
     */
    public function getRecentCommits(int $count = 10): array
    {
        $output = [];
        exec("git log --oneline -n {$count} 2>&1", $output);
        
        $commits = [];
        foreach ($output as $line) {
            if (preg_match('/^(\w+)\s+(.+)$/', $line, $match)) {
                $commits[] = [
                    'hash' => $match[1],
                    'message' => $match[2],
                ];
            }
        }
        
        return $commits;
    }
    
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo "[Git] {$message}\n";
        }
    }
}
