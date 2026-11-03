<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Code3d;

use Weline\DeveloperWorkspace\Service\Code3d\CodeGraphStore;
use Weline\Framework\Console\CommandAbstract;

/**
 * 查询代码 3D 图数据（AI / 脚本可用，--json 输出结构化）。
 *
 * 用法:
 *   php bin/w code3d:query --stats
 *   php bin/w code3d:query --find=App
 *   php bin/w code3d:query --class="Weline\Framework\App"
 *   php bin/w code3d:query --method="Weline\Framework\App::runResponse"
 *   php bin/w code3d:query --callers="Weline\Framework\App::dispatch"
 *   php bin/w code3d:query --callees="Weline\Framework\App::dispatch"
 *   php bin/w code3d:query --modules
 */
class Query extends CommandAbstract
{
    public const dir = 'Console\\Code3d';

    public function execute(array $args = [], array $data = [])
    {
        $store = new CodeGraphStore();
        $limit = isset($args['limit']) ? max(1, (int)$args['limit']) : 20;
        $asJson = isset($args['json']);

        try {
            if (isset($args['stats'])) {
                $result = $store->stats();
            } elseif (isset($args['modules'])) {
                $result = ['modules' => array_slice($store->modules(), 0, max(1, $limit))];
            } elseif (isset($args['flow'])) {
                $groupBy = is_string($args['flow']) && $args['flow'] !== '' ? $args['flow'] : 'module';
                $result = $store->flow($groupBy, max(1, $limit));
            } elseif (isset($args['coupling'])) {
                $spec = (string)$args['coupling'];
                $parts = explode('>', $spec, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    throw new \RuntimeException('格式应为 --coupling="Weline.A>Weline.B"');
                }
                $result = $store->coupling(trim($parts[0]), trim($parts[1]), max(1, $limit));
            } elseif (isset($args['layering'])) {
                $only = is_string($args['layering']) && $args['layering'] !== '' ? $args['layering'] : null;
                $result = $store->layering($only, max(1, $limit));
            } elseif (isset($args['find'])) {
                $kw = (string)$args['find'];
                $result = ['query' => $kw, 'hits' => $store->find($kw, $limit)];
            } elseif (isset($args['class'])) {
                $result = $store->classDetail((string)$args['class'], max(1, $limit));
            } elseif (isset($args['method'])) {
                $result = $store->methodDetail((string)$args['method'], max(1, $limit));
            } elseif (isset($args['callers']) || isset($args['callees'])) {
                $ref = (string)($args['callers'] ?? $args['callees']);
                $detail = $store->methodDetail($ref, max(1, $limit));
                $result = [
                    'class' => $detail['class'],
                    'method' => $detail['method'],
                    'callers' => $detail['callers'],
                    'callees' => $detail['callees'],
                ];
            } else {
                $this->printer->warning('缺少查询参数，可用:');
                $this->printer->printing((string)$this->help());
                return;
            }
        } catch (\RuntimeException $e) {
            $this->printer->error($e->getMessage());
            return;
        }

        if ($asJson) {
            $this->printer->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return;
        }
        $this->renderHuman($result);
    }

    private function renderHuman(array $result): void
    {
        if (isset($result['hits'])) {
            $this->printer->printing(sprintf('「%s」命中 %d 个:', $result['query'], count($result['hits'])));
            foreach ($result['hits'] as $h) {
                $this->printer->printing(sprintf(
                    '  %s  [%s / %s] %d 行 · %d 方法 → %s',
                    $h['fqcn'],
                    $h['kind'],
                    $h['module'],
                    $h['lines'],
                    $h['methods'],
                    $h['file']
                ));
            }
            return;
        }
        if (isset($result['modules'])) {
            foreach ($result['modules'] as $m) {
                $this->printer->printing(sprintf('  %-28s %d 个类', $m['module'], $m['classes']));
            }
            return;
        }
        if (isset($result['violations'])) {
            $this->printer->printing('分层规则：' . $result['rule']);
            $this->printer->printing(sprintf(
                '违规：%d 对模块 / %d 次调用 / 涉及 %d 个模块（直连违规 %d 对 / 契约未声明 %d 对 / 测试调用已忽略 %d 次 / 已声明 %d 对：requires %d、optional %d）',
                $result['pairs'],
                $result['calls'],
                $result['modules_with_violations'],
                $result['direct_pairs'],
                $result['api_pairs'],
                $result['test_calls_ignored'],
                $result['declared_pairs'] ?? 0,
                $result['declared_pairs_requires'] ?? 0,
                $result['declared_pairs_optional'] ?? 0
            ));
            foreach ($result['violations'] as $v) {
                $cp = $v['class_pairs'] ? array_key_first($v['class_pairs']) : '';
                $this->printer->printing(sprintf(
                    '  %s %s → %s  %d 次（静态 %d / 实例 %d｜直连 %d / 契约 %d）%s%s',
                    $v['severity'] === 'direct' ? '[直连]' : '[契约]',
                    $v['from'],
                    $v['to'],
                    $v['total'],
                    $v['static'],
                    $v['instance'],
                    $v['direct'],
                    $v['api'],
                    $v['mutual'] ? '  ↺ 互调（反向 ' . $v['reverse_total'] . '）' : '',
                    $cp !== '' ? '  例: ' . $cp : ''
                ));
            }
            return;
        }
        if (isset($result['class_pairs'])) {
            $this->printer->printing(sprintf(
                '%s → %s （静态 %d / 实例 %d）',
                $result['from'],
                $result['to'],
                $result['totals']['static'],
                $result['totals']['instance']
            ));
            $this->printer->printing('类对 Top ' . count($result['class_pairs']) . ':');
            foreach ($result['class_pairs'] as $pair => $n) {
                $this->printer->printing(sprintf('  %s  (%d)', $pair, $n));
            }
            $this->printer->printing('方法级样例 ' . count($result['method_pairs']) . ':');
            foreach ($result['method_pairs'] as $m) {
                $this->printer->printing('  ' . $m);
            }
            return;
        }
        if (isset($result['pairs'])) {
            $this->printer->printing(sprintf('调用流（按 %s 聚合）Top %d:', $result['group_by'], count($result['pairs'])));
            foreach ($result['pairs'] as $p) {
                $this->printer->printing(sprintf(
                    '  %s → %s  %d 次（静态 %d / 实例 %d）%s%s',
                    $p['from'],
                    $p['to'],
                    $p['total'],
                    $p['static'],
                    $p['instance'],
                    $p['mutual'] ? '  ↺ 互调（反向 ' . $p['reverse_total'] . '）' : '',
                    !empty($p['dispatch_targets']) ? '  [可能实现 ' . $p['dispatch_targets'] . ']' : ''
                ));
            }
            return;
        }
        if (isset($result['fqcn'])) {
            $d = $result;
            $this->printer->printing(sprintf('%s  [%s / %s] %d 行', $d['fqcn'], $d['kind'], $d['module'], $d['lines']));
            $this->printer->printing('文件: ' . $d['file']);
            $this->printer->printing(sprintf('方法 %d:', count($d['methods'])));
            foreach ($d['methods'] as $m) {
                $this->printer->printing(sprintf(
                    '  ƒ %s  →%d ←%d',
                    $m['name'],
                    count($m['callees']),
                    count($m['callers'])
                ));
            }
            $this->printer->printing(sprintf('出边 %d:', count($d['uses'])));
            foreach ($d['uses'] as $u) {
                $this->printer->printing(sprintf('  [%s] %s', $u['type'], $u['fqcn']));
            }
            $this->printer->printing(sprintf('入边 %d:', count($d['used_by'])));
            foreach ($d['used_by'] as $u) {
                $this->printer->printing(sprintf('  [%s] %s', $u['type'], $u['fqcn']));
            }
            return;
        }
        if (isset($result['method'])) {
            $this->printer->printing(sprintf('%s::%s', $result['class'], $result['method']));
            if (isset($result['file'])) {
                $this->printer->printing('文件: ' . $result['file']);
            }
            $this->printer->printing(sprintf('被调 → %d:', count($result['callees'])));
            foreach ($result['callees'] as $c) {
                $this->printer->printing('  ' . $c['class'] . '::' . $c['method']);
            }
            $this->printer->printing(sprintf('调用方 ← %d:', count($result['callers'])));
            foreach ($result['callers'] as $c) {
                $this->printer->printing('  ' . $c['class'] . '::' . $c['method']);
            }
            return;
        }
        $this->printer->printing(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public function tip(): string
    {
        return '查询代码 3D 图：类/方法/调用关系/模块（--json 供 AI 消费）';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'code3d:query <--stats|--find=KW|--class=FQCN|--method=Class::m|--callers=Class::m|--callees=Class::m|--modules|--flow[=module|namespace]> [--limit=N] [--json]',
            $this->tip(),
            [
                '--stats' => '图统计（生成时间、节点/边/模块数）',
                '--find' => '按短名/FQCN 模糊查类',
                '--class' => '类详情：方法清单 + 出边/入边依赖',
                '--method' => '方法详情：被调 / 调用方',
                '--callers' => '只列调用方',
                '--callees' => '只列被调',
                '--modules' => '模块及类数量',
                '--flow' => '有向调用流聚合（默认按模块；可 module|namespace），含互调标记',
                '--limit' => '条数上限',
                '--json' => '输出 JSON',
            ],
            [],
            []
        );
    }
}
