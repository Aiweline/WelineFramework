<?php
declare(strict_types=1);

/**
 * 手动测试 Cron：类上 #[CronTestHelp] 提供说明；可选 public function test(array $options): string。
 *
 * php bin/w cron:test --list
 * php bin/w cron:test --task=websites_domain_resolve_pipeline --domain=example.com -v
 */

namespace Weline\Cron\Console\Cron;

use Weline\Cron\Service\CronTestDiscovery;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Test extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $opts = $this->buildOptions($args);

        if (\in_array('--list', $args, true) || \in_array('-l', $args, true)) {
            $rows = CronTestDiscovery::discover();
            if ($rows === []) {
                self::outLine(__('未发现带 CronTestHelp 或可测 test() 的 Cron 类。'));

                return 'list';
            }
            $this->printCronTestListTable($rows);
            self::outLine('');
            self::outLine(__('运行：php bin/w cron:test --task=<id> [--domain=…] [-v] …'));
            self::outLine(__('说明/示例：php bin/w cron:test --task=<id> -h'));

            return 'list';
        }

        $task = $opts['task'] ?? $opts['t'] ?? null;
        if ($task === null || $task === '') {
            $printing->error(__('请指定 --task=<执行名或测试 id>，或 --list 查看列表'));

            return __('缺少 --task');
        }

        $row = CronTestDiscovery::findById((string) $task);
        if ($row === null) {
            $printing->error(__('未找到任务：%{1}', [(string) $task]));

            return __('任务不存在');
        }

        if (!$row['has_test']) {
            $printing->error(__('该类未实现 test()：%{1}', [$row['class']]));
            $printing->note(__('请执行：php bin/w cron:task:run %{1} -f', [$row['execute_name'] ?? $row['id']]));

            return __('无 test()');
        }

        if (\in_array('--help', $args, true) || \in_array('-h', $args, true)) {
            $printing->note('── ' . $row['id'] . ' ──');
            if ($row['description'] !== '') {
                $printing->printing($row['description'], 'note');
            }
            if ($row['tip'] !== '' && $row['tip'] !== $row['description']) {
                $printing->printing(__('调度：') . $row['tip'], 'note');
            }
            foreach ($row['examples'] as $ex) {
                $printing->printing('  • ' . $ex, 'info');
            }
            if (!$row['has_test']) {
                $printing->warning(__('未实现 test()，请：php bin/w cron:task:run %{1} -f', [$row['execute_name'] ?? $row['id']]));
            }

            return 'help';
        }

        try {
            $instance = ObjectManager::getInstance($row['class']);
            $result = $instance->test($opts);
            $out = (string) $result;
            if (\trim($out) !== '') {
                $printing->success($out);
            } else {
                $printing->note(__('（无输出）'));
            }

            return $out;
        } catch (\Throwable $e) {
            $printing->error($e->getMessage());

            return $e->getMessage();
        }
    }

    /**
     * @param list<mixed> $args
     * @return array<string, mixed>
     */
    private function buildOptions(array $args): array
    {
        $o = ['args' => []];
        $list = [];
        foreach ($args as $a) {
            if (\is_string($a)) {
                $list[] = $a;
            }
        }
        $o['args'] = $list;

        $i = 0;
        $n = \count($list);
        while ($i < $n) {
            $a = $list[$i];
            if ($a === '-v' || $a === '--verbose') {
                $o['verbose'] = true;
                $i++;
                continue;
            }
            if ($a === '--hourly') {
                $o['hourly'] = true;
                $i++;
                continue;
            }
            if (\preg_match('/^--cert_full=(.+)$/', $a, $cm)) {
                $o['cert_full'] = $cm[1] === '1' || $cm[1] === 'true';
                $i++;
                continue;
            }
            if ($a === '--cert_full') {
                $o['cert_full'] = true;
                $i++;
                continue;
            }
            if ($a === '--help' || $a === '-h' || $a === '--list' || $a === '-l') {
                $i++;
                continue;
            }
            if (\preg_match('/^--([^=]+)=(.*)$/', $a, $m)) {
                $o[$m[1]] = \trim($m[2], " \t\"'");
                $i++;
                continue;
            }
            if (($a === '--task' || $a === '-t') && isset($list[$i + 1]) && !\str_starts_with($list[$i + 1], '-')) {
                $o['task'] = $list[$i + 1];
                $i += 2;
                continue;
            }
            if (($a === '--domain' || $a === '-d') && isset($list[$i + 1]) && !\str_starts_with($list[$i + 1], '-')) {
                $o['domain'] = $list[$i + 1];
                $i += 2;
                continue;
            }
            if (\preg_match('/^-([a-z])$/i', $a, $sm) && isset($list[$i + 1]) && !\str_starts_with($list[$i + 1], '-')) {
                $o[$sm[1]] = $list[$i + 1];
                $i += 2;
                continue;
            }
            $i++;
        }

        return $o;
    }

    private static function outLine(string $s): void
    {
        \fwrite(\STDOUT, $s . \PHP_EOL);
    }

    /**
     * @param list<array{id: string, module: string, description: string, tip: string, examples: list<string>, has_test: bool}> $rows
     */
    private function printCronTestListTable(array $rows): void
    {
        $c1 = 4;
        foreach ($rows as $r) {
            $c1 = \max($c1, $this->strWidth((string) $r['id']));
        }
        $c1 = \min(\max($c1, 18), 40);
        $c2 = 14;
        $c3 = 52;

        $h1 = __('任务 id');
        $h2 = __('模组');
        $h3 = __('说明');

        $lines = [];
        $lines[] = '┌' . $this->hline($c1) . '┬' . $this->hline($c2) . '┬' . $this->hline($c3) . '┐';
        $lines[] = '│ ' . $this->pad($h1, $c1) . ' │ ' . $this->pad($h2, $c2) . ' │ ' . $this->pad($h3, $c3) . ' │';
        $lines[] = '├' . $this->hline($c1) . '┼' . $this->hline($c2) . '┼' . $this->hline($c3) . '┤';

        foreach ($rows as $r) {
            $mod = $this->shortModule((string) $r['module']);
            $desc = (string) $r['description'];
            if ($desc === '' && (string) $r['tip'] !== '') {
                $desc = (string) $r['tip'];
            }
            if (!$r['has_test']) {
                $desc = __('【无 test】执行请 cron:task:run -f。') . ($desc !== '' ? ' ' . $desc : '');
            }
            $desc = $this->trimWidth($desc, $c3);
            $lines[] = '│ ' . $this->pad((string) $r['id'], $c1) . ' │ ' . $this->pad($mod, $c2) . ' │ ' . $this->pad($desc, $c3) . ' │';
        }

        $lines[] = '└' . $this->hline($c1) . '┴' . $this->hline($c2) . '┴' . $this->hline($c3) . '┘';
        self::outLine(\implode(\PHP_EOL, $lines));
    }

    private function hline(int $w): string
    {
        return \str_repeat('─', $w + 2);
    }

    private function strWidth(string $s): int
    {
        if (\function_exists('mb_strwidth')) {
            return (int) \mb_strwidth($s, 'UTF-8');
        }

        return \strlen($s);
    }

    private function trimWidth(string $s, int $max): string
    {
        if ($s === '') {
            return '';
        }
        if (\function_exists('mb_strimwidth')) {
            $t = \mb_strimwidth($s, 0, $max, '…', 'UTF-8');

            return $t === false ? $s : $t;
        }

        return \strlen($s) <= $max ? $s : \substr($s, 0, \max(0, $max - 1)) . '…';
    }

    private function pad(string $s, int $w): string
    {
        $sw = $this->strWidth($s);
        if ($sw >= $w) {
            return $this->trimWidth($s, $w);
        }

        return $s . \str_repeat(' ', $w - $sw);
    }

    private function shortModule(string $name): string
    {
        if (\str_starts_with($name, 'Weline_')) {
            return \substr($name, 7) ?: $name;
        }

        return $name;
    }

    public function tip(): string
    {
        return __('Cron 手动测试（#[CronTestHelp] + 可选 test($options)）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'cron:test',
            self::tip(),
            [
                '--list, -l' => __('列出可测任务（含说明）'),
                '--task=, -t' => __('execute_name 或测试 id'),
                '--help, -h' => __('仅打印该任务的测试说明'),
                __('其它参数') => __('全部进入 test($options)，如 --domain= -v --hourly'),
            ],
            [],
            [
                'php bin/w cron:test --list' => '',
                'php bin/w cron:test --task=domain_auto_resolve --domain=example.com -v' => '',
            ]
        );
    }
}
