<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

declare(strict_types=1);

namespace Weline\Framework\Git\Console\Git;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;

/**
 * git:rebase 命令。
 *
 * 提供三种工作模式：
 *  1. log <path...>    在仓库历史中按路径定位提交，避免改写历史前的全量盲扫；
 *  2. remove <path...> 用 git filter-repo 把指定路径从全部历史中移除（默认 dry-run，加 --force 才执行）；
 *  3. 缺省            将剩余参数原样透传给 git rebase，便于直接发起交互式 rebase 等操作。
 */
class Rebase extends CommandAbstract
{
    public const ALIASES = [
        'git:rb',
    ];

    private const SUBCOMMANDS = ['log', 'commits', 'remove', 'cleanup'];

    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = [])
    {
        $cwd = $this->resolveCwd($args);
        if ($cwd === null) {
            return;
        }

        $mode = $this->detectMode($args);
        switch ($mode) {
            case 'log':
            case 'commits':
                $this->runPathLog($args, $cwd);
                return;
            case 'remove':
                $this->runRemoveFromHistory($args, $cwd);
                return;
            case 'cleanup':
                $this->runCleanup($args, $cwd);
                return;
            default:
                $this->runPassthroughRebase($cwd);
                return;
        }
    }

    public function tip(): string
    {
        return __('Git 工具：按路径定位提交、从历史移除路径，或透传 git rebase。');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'git:rebase',
            $this->tip(),
            [
                '-h, --help'      => __('显示帮助信息'),
                '--cwd=<path>'    => __('Git 仓库工作目录，默认为项目根目录 BP'),
                '--all'           => __('log 子命令：在所有 ref 上扫描（git log --all）'),
                '--since=<date>'  => __('log 子命令：限定时间范围，例如 --since=1.week 或 --since=2026-01-01'),
                '--limit=<n>'     => __('log 子命令：限制最多输出多少条记录'),
                '--force'         => __('remove / cleanup 子命令：真正执行操作（默认仅 dry-run）'),
                '--all-history'   => __('remove 子命令：强制重写全部历史（关闭「按文件起点局部重写」优化）'),
                '--no-cleanup'    => __('remove 子命令：成功后不自动跑 cleanup（默认会自动收尾）'),
            ],
            [
                'log <path...>'    => __('列出涉及指定路径的提交（git log --oneline -- <path>）'),
                'remove <path...>' => __('用 git filter-repo --invert-paths 删除路径；默认从「文件第一次出现」的父 commit 起做局部重写，并在成功后自动 cleanup'),
                'cleanup'          => __('清理 refs/original/* 与 refs/replace/*、过期 reflog、git gc --prune=now --aggressive，让仓库收尾干净'),
                __('其余参数')      => __('未命中子命令时，所有参数透传给 git rebase（保留原顺序与引号）'),
            ],
            [
                __('查看路径相关提交')        => 'php bin/w git:rebase log app/etc/env.php',
                __('所有分支 + 时间范围')      => 'php bin/w git:rebase log app/etc/env.php --all --since=1.week',
                __('指定别的仓库目录')        => 'php bin/w git:rebase log app/etc/env.php --cwd=/path/to/repo',
                __('从历史移除（dry-run 局部）') => 'php bin/w git:rebase remove app/etc/env.php',
                __('从历史移除（真正执行 + 自动 cleanup）') => 'php bin/w git:rebase remove app/etc/env.php --force',
                __('从历史移除但不自动 cleanup') => 'php bin/w git:rebase remove app/etc/env.php --force --no-cleanup',
                __('强制全量重写历史')         => 'php bin/w git:rebase remove app/etc/env.php --all-history --force',
                __('单独清理（dry-run）')      => 'php bin/w git:rebase cleanup',
                __('单独清理（真正执行）')      => 'php bin/w git:rebase cleanup --force',
                __('透传 rebase（交互式）')    => 'php bin/w git:rebase -i HEAD~3',
                __('透传 rebase（指定上游）')  => 'php bin/w git:rebase main',
                __('短别名')                 => 'php bin/w git:rb log app/etc/env.php',
            ],
            'php bin/w git:rebase [log|remove|cleanup <path...>] [--cwd=<path>] [--all] [--since=<date>] [--limit=<n>] [--all-history] [--no-cleanup] [--force]'
        );
    }

    private function resolveCwd(array $args): ?string
    {
        $cwd = $args['cwd'] ?? BP;
        if (!is_string($cwd) || $cwd === '') {
            $cwd = BP;
        }
        $cwd = rtrim($cwd, "/\\");
        if (!is_dir($cwd)) {
            $this->printer->error(__('工作目录不存在：%{1}', [$cwd]));
            return null;
        }
        if (!is_dir($cwd . DIRECTORY_SEPARATOR . '.git') && !is_file($cwd . DIRECTORY_SEPARATOR . '.git')) {
            $this->printer->warning(__('目录 %{1} 下未发现 .git，请确认是否为 Git 仓库根。', [$cwd]));
        }
        return $cwd;
    }

    /**
     * 取第一个非 - 开头的位置参数判断是否为子命令；
     * 若不是 log/remove，则视为透传给 git rebase。
     */
    private function detectMode(array $args): string
    {
        for ($i = 1; isset($args[$i]); $i++) {
            $val = $args[$i];
            if (!is_string($val) || $val === '') {
                continue;
            }
            if (str_starts_with($val, '-')) {
                continue;
            }
            $lower = strtolower($val);
            if (in_array($lower, self::SUBCOMMANDS, true)) {
                return $lower;
            }
            return 'passthrough';
        }
        return 'passthrough';
    }

    /**
     * 收集子命令后面的位置参数（路径）。
     */
    private function collectSubcommandPaths(array $args): array
    {
        $paths = [];
        $foundSubcommand = false;
        for ($i = 1; isset($args[$i]); $i++) {
            $val = $args[$i];
            if (!is_string($val) || $val === '') {
                continue;
            }
            if (str_starts_with($val, '-')) {
                continue;
            }
            if (!$foundSubcommand) {
                if (in_array(strtolower($val), self::SUBCOMMANDS, true)) {
                    $foundSubcommand = true;
                }
                continue;
            }
            $paths[] = $val;
        }
        return $paths;
    }

    private function runPathLog(array $args, string $cwd): void
    {
        $paths = $this->collectSubcommandPaths($args);
        if ($paths === []) {
            $this->printer->error(__('请提供至少一个路径，例如：php bin/w git:rebase log app/etc/env.php'));
            return;
        }

        $cmd = ['git', 'log', '--oneline'];
        if (isset($args['all'])) {
            $cmd[] = '--all';
        }
        if (isset($args['since']) && is_string($args['since']) && $args['since'] !== '') {
            $cmd[] = '--since=' . $args['since'];
        }
        $limit = $args['limit'] ?? ($args['n'] ?? null);
        if (is_string($limit) && ctype_digit($limit) && (int)$limit > 0) {
            $cmd[] = '-n';
            $cmd[] = $limit;
        }
        $cmd[] = '--';
        foreach ($paths as $p) {
            $cmd[] = $p;
        }

        $this->printer->note(__('执行：%{1}', [$this->formatCmd($cmd)]));
        $result = $this->runProc($cmd, $cwd);

        if ($result['stdout'] !== '') {
            $this->printer->printing($result['stdout']);
        } else {
            $this->printer->note(__('未找到与指定路径相关的提交。'));
        }
        if ($result['stderr'] !== '') {
            $this->printer->warning($result['stderr']);
        }
        if ($result['exit'] !== 0) {
            $this->printer->error(__('git log 退出码：%{1}', [$result['exit']]));
        }
    }

    private function runRemoveFromHistory(array $args, string $cwd): void
    {
        $paths = $this->collectSubcommandPaths($args);
        if ($paths === []) {
            $this->printer->error(__('请提供至少一个路径，例如：php bin/w git:rebase remove app/etc/env.php'));
            return;
        }

        $forceAll = isset($args['all-history']);
        $scope = $this->resolveRewriteScope($paths, $cwd, $forceAll);
        $this->printScopeReport($scope, $paths, $cwd);

        if ($scope['touched'] === 0) {
            return;
        }

        $check = $this->runProc(['git', 'filter-repo', '--version'], $cwd);
        if ($check['exit'] !== 0) {
            $this->printer->error(__('未检测到 git-filter-repo，无法继续真正改写历史。'));
            $this->printer->note(__('安装方法（任选其一）：'));
            $this->printer->note('  pip install git-filter-repo');
            $this->printer->note('  brew install git-filter-repo');
            $this->printer->note(__('详情见 https://github.com/newren/git-filter-repo'));
            return;
        }

        $cmd = ['git', 'filter-repo', '--invert-paths'];
        foreach ($paths as $p) {
            $cmd[] = '--path';
            $cmd[] = $p;
        }
        if ($scope['range'] !== null) {
            // --refs <range> 限定改写范围，filter-repo 会自动 implies --partial。
            $cmd[] = '--refs';
            $cmd[] = $scope['range'];
        }
        // 已自行做 dry-run 与统计提示，跳过 filter-repo 的 fresh-clone 等保护。
        $cmd[] = '--force';

        $this->printer->note(__('待执行：%{1}', [$this->formatCmd($cmd)]));
        $this->printer->warning(__('破坏性操作：将重写仓库历史。如已推送到远程，需要协调 force-push 与团队成员。'));

        $isForce = isset($args['force']) || isset($args['f']);
        if (!$isForce) {
            $this->printer->note(__('当前为 dry-run，未执行。加 --force 才会真正改写历史。'));
            return;
        }

        $this->printer->note(__('开始执行 git filter-repo ...'));
        $result = $this->runProc($cmd, $cwd);
        if ($result['stdout'] !== '') {
            $this->printer->printing($result['stdout']);
        }
        if ($result['stderr'] !== '') {
            $this->printer->printing($result['stderr']);
        }
        if ($result['exit'] !== 0) {
            $this->printer->error(__('git filter-repo 退出码：%{1}', [$result['exit']]));
            return;
        }
        $this->printer->success(__('历史已重写。请检查后再 push（通常需要 git push --force）。'));

        if (isset($args['no-cleanup'])) {
            $this->printer->note(__('已跳过自动 cleanup（--no-cleanup）。如需收尾，请运行：php bin/w git:rebase cleanup --force'));
            return;
        }
        $this->printer->note(__('开始自动 cleanup（refs/original、refs/replace、reflog 过期、git gc）...'));
        $plan = $this->planCleanup($cwd);
        $this->printCleanupPlan($plan);
        $this->doCleanup($plan, $cwd);
    }

    /**
     * 计算改写范围：
     *  - 用 `git rev-list --reverse HEAD -- <paths>` 找出最早涉及任一路径的 commit；
     *  - 它的父 commit 即为 rewrite base，filter-repo 只重写 <base>..HEAD；
     *  - 若该 commit 是 root（无父）或 --all-history 强制全量，退化为整段历史。
     *
     * @param array<int, string> $paths
     * @return array{range: ?string, total: int, touched: int, earliest: ?string, base: ?string, mode: string}
     */
    private function resolveRewriteScope(array $paths, string $cwd, bool $forceAll): array
    {
        $totalRes = $this->runProc(['git', 'rev-list', '--count', 'HEAD'], $cwd);
        $total = $totalRes['exit'] === 0 ? (int)trim($totalRes['stdout']) : 0;

        $cmd = ['git', 'rev-list', '--reverse', 'HEAD', '--'];
        foreach ($paths as $p) {
            $cmd[] = $p;
        }
        $touchedRes = $this->runProc($cmd, $cwd);
        if ($touchedRes['exit'] !== 0 || trim($touchedRes['stdout']) === '') {
            return [
                'range'    => null,
                'total'    => $total,
                'touched'  => 0,
                'earliest' => null,
                'base'     => null,
                'mode'     => 'none',
            ];
        }
        $lines = preg_split('/\r?\n/', trim($touchedRes['stdout'])) ?: [];
        $touched = count($lines);
        $earliest = $lines[0] ?? null;

        if ($forceAll) {
            return [
                'range'    => null,
                'total'    => $total,
                'touched'  => $touched,
                'earliest' => $earliest,
                'base'     => null,
                'mode'     => 'forced-full',
            ];
        }

        $parentRes = $this->runProc(['git', 'rev-parse', '--verify', $earliest . '^'], $cwd);
        if ($parentRes['exit'] !== 0 || trim($parentRes['stdout']) === '') {
            return [
                'range'    => null,
                'total'    => $total,
                'touched'  => $touched,
                'earliest' => $earliest,
                'base'     => null,
                'mode'     => 'root-fallback',
            ];
        }
        $base = trim($parentRes['stdout']);

        return [
            'range'    => $base . '..HEAD',
            'total'    => $total,
            'touched'  => $touched,
            'earliest' => $earliest,
            'base'     => $base,
            'mode'     => 'partial',
        ];
    }

    /**
     * @param array{range: ?string, total: int, touched: int, earliest: ?string, base: ?string, mode: string} $scope
     * @param array<int, string> $paths
     */
    private function printScopeReport(array $scope, array $paths, string $cwd): void
    {
        $this->printer->note(__('待移除路径：%{1}', [implode(', ', $paths)]));
        if ($scope['touched'] === 0) {
            $this->printer->warning(__('未在 HEAD 历史中找到任何涉及该路径的提交，可能路径错误或仅在其它 ref 上。'));
            $this->printer->note(__('总提交数：%{1}', [$scope['total']]));
            return;
        }

        $this->printer->note(__('总提交数：%{1}', [$scope['total']]));
        $this->printer->note(__('涉及该路径的提交数：%{1}', [$scope['touched']]));
        if ($scope['earliest'] !== null) {
            $this->printer->note(__('最早涉及提交：%{1}', [substr($scope['earliest'], 0, 12)]));
        }

        switch ($scope['mode']) {
            case 'partial':
                $base = (string)$scope['base'];
                $afterCount = $this->countCommitsAfter($base, $cwd);
                $skipped = max(0, $scope['total'] - $afterCount);
                $this->printer->success(__('优化生效：仅重写 %{1}（共 %{2} 个 commit，前 %{3} 个 commit 不动）', [
                    $scope['range'], $afterCount, $skipped,
                ]));
                break;
            case 'root-fallback':
                $this->printer->warning(__('最早涉及提交是根提交，无法只改后段，将退化为重写全部历史。'));
                break;
            case 'forced-full':
                $this->printer->warning(__('已通过 --all-history 强制对全部历史进行重写。'));
                break;
        }
    }

    /**
     * 计算 base..HEAD 之间的提交数（仅用于展示「重写多少 / 跳过多少」）。
     */
    private function countCommitsAfter(string $base, string $cwd): int
    {
        $r = $this->runProc(['git', 'rev-list', '--count', $base . '..HEAD'], $cwd);
        if ($r['exit'] !== 0) {
            return 0;
        }
        return (int)trim($r['stdout']);
    }

    private function runPassthroughRebase(string $cwd): void
    {
        $passthrough = $this->extractPassthroughArgs();
        $cmd = array_merge(['git', 'rebase'], $passthrough);

        $this->printer->note(__('执行：%{1}', [$this->formatCmd($cmd)]));
        $exit = $this->runProcInteractive($cmd, $cwd);
        if ($exit !== 0) {
            $this->printer->error(__('git rebase 退出码：%{1}', [$exit]));
        }
    }

    /**
     * 从原始 $_SERVER['argv'] 中提取本命令名之后的全部参数，并剔除本命令自有开关，
     * 其它参数原样透传给 git rebase。
     */
    private function extractPassthroughArgs(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $markers = array_map('strtolower', array_merge(['git:rebase'], self::ALIASES));

        $start = -1;
        foreach ($argv as $i => $tok) {
            if (!is_string($tok)) {
                continue;
            }
            if (in_array(strtolower($tok), $markers, true)) {
                $start = $i + 1;
                break;
            }
        }
        if ($start < 0) {
            return [];
        }

        $rest = array_slice($argv, $start);
        $out = [];
        $skipNext = false;
        foreach ($rest as $tok) {
            if ($skipNext) {
                $skipNext = false;
                continue;
            }
            if (!is_string($tok)) {
                continue;
            }
            if ($tok === '--cwd') {
                $skipNext = true;
                continue;
            }
            if (str_starts_with($tok, '--cwd=')) {
                continue;
            }
            if ($tok === '-h' || $tok === '--help') {
                continue;
            }
            $out[] = $tok;
        }
        return $out;
    }

    /**
     * 启动子进程，捕获 stdout/stderr/退出码。参数以数组形式传递，避免 Windows 路径/空格的转义陷阱。
     *
     * @param array<int, string> $cmd
     * @return array{stdout:string, stderr:string, exit:int}
     */
    private function runProc(array $cmd, string $cwd): array
    {
        if (!function_exists('proc_open')) {
            return ['stdout' => '', 'stderr' => 'proc_open() 不可用', 'exit' => -1];
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['stdout' => '', 'stderr' => '无法启动子进程', 'exit' => -1];
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? (string)stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? (string)stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $exit = proc_close($proc);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit'   => is_int($exit) ? $exit : -1,
        ];
    }

    /**
     * 透传 stdin/stdout/stderr 到子进程，便于 git rebase -i 进入编辑器。
     *
     * @param array<int, string> $cmd
     */
    private function runProcInteractive(array $cmd, string $cwd): int
    {
        if (!function_exists('proc_open')) {
            return -1;
        }

        $descriptor = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!is_resource($proc)) {
            return -1;
        }
        $exit = proc_close($proc);
        return is_int($exit) ? $exit : -1;
    }

    private function formatCmd(array $cmd): string
    {
        $parts = [];
        foreach ($cmd as $p) {
            $s = (string)$p;
            if ($s === '' || preg_match('/[\s"\'\\\\]/', $s)) {
                $parts[] = '"' . str_replace('"', '\\"', $s) . '"';
            } else {
                $parts[] = $s;
            }
        }
        return implode(' ', $parts);
    }

    /**
     * cleanup 子命令：清理 filter-branch / filter-repo 留下的备份与可达孤立对象。
     * dry-run 默认；--force 才真正执行。
     */
    private function runCleanup(array $args, string $cwd): void
    {
        $plan = $this->planCleanup($cwd);
        $this->printCleanupPlan($plan);

        $isForce = isset($args['force']) || isset($args['f']);
        if (!$isForce) {
            $this->printer->note(__('当前为 dry-run，未执行。加 --force 才会真正清理与 GC。'));
            return;
        }
        $this->doCleanup($plan, $cwd);
    }

    /**
     * 探测仓库当前的「待清理项」与体积基线。
     *
     * @return array{
     *   original_refs: array<int, string>,
     *   replace_refs: array<int, string>,
     *   has_filter_repo_backup: bool,
     *   filter_repo_backup_path: ?string,
     *   pack_kib_before: ?int,
     *   loose_count_before: ?int
     * }
     */
    private function planCleanup(string $cwd): array
    {
        $backupPath = $cwd . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'filter-repo';
        $stats = $this->measureRepoStats($cwd);

        return [
            'original_refs'           => $this->listRefs($cwd, 'refs/original/'),
            'replace_refs'            => $this->listRefs($cwd, 'refs/replace/'),
            'has_filter_repo_backup'  => is_dir($backupPath),
            'filter_repo_backup_path' => is_dir($backupPath) ? $backupPath : null,
            'pack_kib_before'         => $stats['pack_kib'],
            'loose_count_before'      => $stats['loose_count'],
        ];
    }

    /**
     * 用 git for-each-ref 列出指定前缀下的所有引用。
     *
     * @return array<int, string>
     */
    private function listRefs(string $cwd, string $prefix): array
    {
        $r = $this->runProc(['git', 'for-each-ref', '--format=%(refname)', $prefix], $cwd);
        if ($r['exit'] !== 0 || trim($r['stdout']) === '') {
            return [];
        }
        $lines = preg_split('/\r?\n/', trim($r['stdout'])) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
    }

    /**
     * 解析 git count-objects -v 输出，便于做清理前后的体积对比。
     *
     * @return array{pack_kib: ?int, loose_count: ?int}
     */
    private function measureRepoStats(string $cwd): array
    {
        $r = $this->runProc(['git', 'count-objects', '-v'], $cwd);
        $packKib = null;
        $loose = null;
        if ($r['exit'] === 0 && $r['stdout'] !== '') {
            if (preg_match('/^size-pack:\s*(\d+)/m', $r['stdout'], $m)) {
                $packKib = (int)$m[1];
            }
            if (preg_match('/^count:\s*(\d+)/m', $r['stdout'], $m)) {
                $loose = (int)$m[1];
            }
        }
        return ['pack_kib' => $packKib, 'loose_count' => $loose];
    }

    /**
     * @param array{
     *   original_refs: array<int, string>,
     *   replace_refs: array<int, string>,
     *   has_filter_repo_backup: bool,
     *   filter_repo_backup_path: ?string,
     *   pack_kib_before: ?int,
     *   loose_count_before: ?int
     * } $plan
     */
    private function printCleanupPlan(array $plan): void
    {
        $this->printer->note(__('清理范围：'));
        $this->printer->note(__('  refs/original/* 引用：%{1} 个', [count($plan['original_refs'])]));
        if ($plan['original_refs'] !== []) {
            foreach ($plan['original_refs'] as $ref) {
                $this->printer->note('    - ' . $ref);
            }
        }
        $this->printer->note(__('  refs/replace/*  引用：%{1} 个', [count($plan['replace_refs'])]));
        if ($plan['replace_refs'] !== []) {
            foreach ($plan['replace_refs'] as $ref) {
                $this->printer->note('    - ' . $ref);
            }
        }
        $this->printer->note(__('  .git/filter-repo/ 备份目录：%{1}', [
            $plan['has_filter_repo_backup'] ? ($plan['filter_repo_backup_path'] ?? '存在') : __('无'),
        ]));
        if ($plan['pack_kib_before'] !== null) {
            $this->printer->note(__('  当前 pack 大小：%{1} KiB', [$plan['pack_kib_before']]));
        }
        if ($plan['loose_count_before'] !== null) {
            $this->printer->note(__('  当前 loose 对象数：%{1}', [$plan['loose_count_before']]));
        }
    }

    /**
     * 真正执行清理。流程：
     *   1) update-ref -d 删除 refs/original/* 与 refs/replace/*；
     *   2) git reflog expire --expire=now --all 让所有 reflog 立刻失效；
     *   3) git gc --prune=now --aggressive 彻底清掉旧对象；
     *   4) 报告 pack/loose 对象数变化；filter-repo 备份目录提示用户手动决定是否删除。
     *
     * @param array{
     *   original_refs: array<int, string>,
     *   replace_refs: array<int, string>,
     *   has_filter_repo_backup: bool,
     *   filter_repo_backup_path: ?string,
     *   pack_kib_before: ?int,
     *   loose_count_before: ?int
     * } $plan
     */
    private function doCleanup(array $plan, string $cwd): void
    {
        $deletedOriginal = 0;
        foreach ($plan['original_refs'] as $ref) {
            $r = $this->runProc(['git', 'update-ref', '-d', $ref], $cwd);
            if ($r['exit'] === 0) {
                $deletedOriginal++;
            } else {
                $this->printer->warning(__('删除引用失败：%{1}（%{2}）', [$ref, trim($r['stderr'])]));
            }
        }
        $deletedReplace = 0;
        foreach ($plan['replace_refs'] as $ref) {
            $r = $this->runProc(['git', 'update-ref', '-d', $ref], $cwd);
            if ($r['exit'] === 0) {
                $deletedReplace++;
            } else {
                $this->printer->warning(__('删除引用失败：%{1}（%{2}）', [$ref, trim($r['stderr'])]));
            }
        }
        if ($deletedOriginal > 0 || $deletedReplace > 0) {
            $this->printer->success(__('已删除 refs/original %{1} 个、refs/replace %{2} 个备份引用。', [
                $deletedOriginal, $deletedReplace,
            ]));
        } else {
            $this->printer->note(__('没有需要删除的备份引用。'));
        }

        $reflog = $this->runProc(['git', 'reflog', 'expire', '--expire=now', '--all'], $cwd);
        if ($reflog['exit'] === 0) {
            $this->printer->success(__('reflog 已全部过期。'));
        } else {
            $this->printer->warning(__('reflog expire 失败：%{1}', [trim($reflog['stderr'])]));
        }

        $this->printer->note(__('开始 git gc --prune=now --aggressive ...'));
        $gc = $this->runProc(['git', 'gc', '--prune=now', '--aggressive'], $cwd);
        if ($gc['stdout'] !== '') {
            $this->printer->printing($gc['stdout']);
        }
        if ($gc['stderr'] !== '') {
            $this->printer->printing($gc['stderr']);
        }
        if ($gc['exit'] !== 0) {
            $this->printer->error(__('git gc 退出码：%{1}', [$gc['exit']]));
            return;
        }

        $after = $this->measureRepoStats($cwd);
        if ($plan['pack_kib_before'] !== null && $after['pack_kib'] !== null) {
            $delta = $plan['pack_kib_before'] - $after['pack_kib'];
            $this->printer->success(__('pack 大小：%{1} → %{2} KiB（释放 %{3} KiB）', [
                $plan['pack_kib_before'], $after['pack_kib'], $delta,
            ]));
        }
        if ($plan['loose_count_before'] !== null && $after['loose_count'] !== null) {
            $this->printer->success(__('loose 对象数：%{1} → %{2}', [
                $plan['loose_count_before'], $after['loose_count'],
            ]));
        }

        if ($plan['has_filter_repo_backup'] && $plan['filter_repo_backup_path'] !== null) {
            $this->printer->warning(__('保留了 filter-repo 自动备份目录：%{1}', [$plan['filter_repo_backup_path']]));
            $this->printer->note(__('如确认无需回退，可手动删除该目录以彻底回收磁盘空间。'));
        }

        $this->printer->success(__('cleanup 完成，仓库已收尾。'));
    }
}
