<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Code3d;

/**
 * 代码 3D 图的存储与查询：
 * - 构建产物写 var/code3d/（graph.json 供查询、code3d.html 供浏览器打开）
 * - 查询：stats / find / class / method(callers|callees) / deps / modules
 */
final class CodeGraphStore
{
    private const KINDS = ['c' => '类', 'i' => '接口', 't' => 'Trait', 'n' => 'Enum', 'f' => '函数'];
    private const ETYPES = ['e' => '继承', 'd' => '依赖', 's' => '调用', 'i' => '实例调用', 'u' => '接口分发'];

    private static ?array $cache = null;

    public function repoRoot(): string
    {
        return defined('BP') ? \BP : dirname(__DIR__, 6) . '/';
    }

    public function outDir(): string
    {
        return $this->repoRoot() . 'var/code3d/';
    }

    public function jsonPath(): string
    {
        return $this->outDir() . 'graph.json';
    }

    public function htmlPath(): string
    {
        return $this->outDir() . 'code3d.html';
    }

    private function moduleRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function templatePath(): string
    {
        return $this->moduleRoot() . '/view/code3d/template.html';
    }

    public function libPath(): string
    {
        return $this->moduleRoot() . '/view/code3d/lib/3d-force-graph.min.js';
    }

    public function isBuilt(): bool
    {
        return is_file($this->jsonPath()) && is_file($this->htmlPath());
    }

    /**
     * 构建并落盘 graph.json + code3d.html。
     *
     * @return array<string,mixed> meta
     */
    public function build(?string $srcDir = null, ?string $outDir = null): array
    {
        $src = $srcDir ?: $this->repoRoot() . 'app/code';
        $out = $outDir ?: $this->outDir();
        if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
            throw new \RuntimeException('无法创建输出目录: ' . $out);
        }
        $graph = (new CodeGraphBuilder())->build($this->repoRoot(), $src);
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('graph.json 编码失败: ' . json_last_error_msg());
        }
        file_put_contents($out . '/graph.json', $json);

        $tpl = $this->templatePath();
        $lib = $this->libPath();
        foreach ([$tpl, $lib] as $need) {
            if (!is_file($need)) {
                throw new \RuntimeException('缺少模块资源: ' . $need);
            }
        }
        $html = file_get_contents($tpl);
        $libJs = str_replace('</script', '<\/script', file_get_contents($lib));
        $data = json_encode($graph, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html = str_replace(['__W3D_LIB__', '__W3D_DATA__'], [$libJs, $data], $html);
        file_put_contents($out . '/code3d.html', $html);

        self::$cache = null;
        return $graph['meta'];
    }

    /** @return array<string,mixed> */
    public function load(bool $reload = false): array
    {
        if (!$reload && self::$cache !== null) {
            return self::$cache;
        }
        $path = $this->jsonPath();
        if (!is_file($path)) {
            throw new \RuntimeException('图数据不存在，请先运行: php bin/w code3d:analyze');
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || !isset($data['nodes'], $data['edges'])) {
            throw new \RuntimeException('图数据损坏，请重跑: php bin/w code3d:analyze');
        }
        return self::$cache = $data;
    }

    /** @return array<string,mixed> */
    public function stats(): array
    {
        $g = $this->load();
        return $g['meta'] + [
            'graph' => $this->htmlPath(),
            'json' => $this->jsonPath(),
        ];
    }

    /**
     * 按关键字查类/接口/trait/函数（fq 或短名包含）。
     *
     * @return list<array<string,mixed>>
     */
    public function find(string $query, int $limit = 20): array
    {
        $g = $this->load();
        $q = strtolower($query);
        $rank = [];
        foreach ($g['nodes'] as $i => $n) {
            $short = strtolower($n['n']);
            $fq = strtolower($n['fq']);
            if ($short === $q) {
                $score = 0;
            } elseif (str_starts_with($short, $q)) {
                $score = 1;
            } elseif (str_contains($fq, $q)) {
                $score = 2;
            } else {
                continue;
            }
            $rank[] = [$score, $i];
        }
        usort($rank, fn($a, $b) => [$a[0], -$a[1]] <=> [$b[0], -$b[1]]);
        $out = [];
        foreach (array_slice($rank, 0, max(1, $limit)) as [, $i]) {
            $out[] = $this->nodeSummary($g, $i);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function classDetail(string $fqOrShort, int $depLimit = 40): array
    {
        $g = $this->load();
        $i = $this->resolveNode($g, $fqOrShort);
        $n = $g['nodes'][$i];
        $methods = [];
        foreach ($n['mt'] as $m) {
            $key = $i . ':' . $m;
            $methods[] = [
                'name' => $m,
                'callees' => $this->resolveKeys($g, $g['mcalls'][$key] ?? []),
                'callers' => $this->resolveKeys($g, $this->callersOf($g, $key)),
            ];
        }
        return [
            'fqcn' => $n['fq'],
            'kind' => self::KINDS[$n['k']] ?? $n['k'],
            'module' => $g['mods'][$n['m']] ?? '',
            'file' => $n['f'],
            'lines' => $n['lc'],
            'methods' => $methods,
            'uses' => $this->edgeList($g, $i, 'out', $depLimit),
            'used_by' => $this->edgeList($g, $i, 'in', $depLimit),
        ];
    }

    /** @return array<string,mixed> */
    public function methodDetail(string $classMethod, int $limit = 100): array
    {
        $g = $this->load();
        $pos = strrpos($classMethod, '::');
        if ($pos === false) {
            throw new \RuntimeException('方法格式应为 Class::method，例如 Weline\\Framework\\App::run');
        }
        $fq = substr($classMethod, 0, $pos);
        $method = substr($classMethod, $pos + 2);
        $i = $this->resolveNode($g, $fq);
        $n = $g['nodes'][$i];
        if (!in_array($method, $n['mt'], true)) {
            throw new \RuntimeException(sprintf('%s 没有方法 %s', $n['fq'], $method));
        }
        $key = $i . ':' . $method;
        return [
            'class' => $n['fq'],
            'method' => $method,
            'file' => $n['f'],
            'callees' => $this->resolveKeys($g, array_slice($g['mcalls'][$key] ?? [], 0, $limit)),
            'callers' => $this->resolveKeys($g, array_slice($this->callersOf($g, $key), 0, $limit)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function modules(): array
    {
        $g = $this->load();
        $count = array_fill(0, count($g['mods']), 0);
        foreach ($g['nodes'] as $n) {
            $count[$n['m']] = ($count[$n['m']] ?? 0) + 1;
        }
        $out = [];
        foreach ($g['mods'] as $mi => $name) {
            $out[] = ['module' => $name, 'classes' => $count[$mi] ?? 0];
        }
        usort($out, fn($a, $b) => $b['classes'] <=> $a['classes']);
        return $out;
    }

    /**
     * 有向调用流聚合：只统计「调用类」边（静态调用 s / 实例调用 i / 接口分发 u），
     * 按模块或命名空间分组，输出 A→B 次数 + 反向次数（互调）。
     *
     * @return array{group_by:string, pairs:list<array<string,mixed>>}
     */
    public function flow(string $groupBy = 'module', int $limit = 30): array
    {
        $g = $this->load();
        $groupBy = in_array($groupBy, ['module', 'namespace'], true) ? $groupBy : 'module';
        /* 只统计真实调用（静态调用 s + 精确实例调用 i）；接口分发 u 是「可能实现」，单独计数不计入耦合 */
        $callTypes = ['s' => 'static', 'i' => 'instance'];
        $keyOf = function (int $nodeIdx) use ($g, $groupBy): string {
            $n = $g['nodes'][$nodeIdx];
            $mod = $g['mods'][$n['m']] ?? '_';
            if ($groupBy === 'module') {
                return $mod;
            }
            $parts = explode('.', $mod);
            $fq = ltrim(str_starts_with($n['fq'], 'fn:') ? substr($n['fq'], 3) : $n['fq'], '\\');
            $segs = explode('\\', $fq);
            $tail = $segs[count($parts)] ?? '';
            return $tail === '' ? $mod : $mod . '.' . $tail;
        };
        $pairs = [];
        $dispatch = [];
        foreach ($g['edges'] as $e) {
            [$a, $b, $t] = $e;
            if ($t === 'u') {
                $ga = $keyOf((int)$a);
                $gb = $keyOf((int)$b);
                if ($ga !== $gb) {
                    $dispatch[$ga . "\t" . $gb] = ($dispatch[$ga . "\t" . $gb] ?? 0) + 1;
                }
                continue;
            }
            if (!isset($callTypes[$t])) {
                continue;
            }
            $ga = $keyOf((int)$a);
            $gb = $keyOf((int)$b);
            if ($ga === $gb) {
                continue;
            }
            $k = $ga . "\t" . $gb;
            if (!isset($pairs[$k])) {
                $pairs[$k] = ['from' => $ga, 'to' => $gb, 'static' => 0, 'instance' => 0, 'total' => 0];
            }
            $pairs[$k][$callTypes[$t]]++;
            $pairs[$k]['total']++;
        }
        $out = [];
        foreach ($pairs as $k => $p) {
            $rev = $pairs[$p['to'] . "\t" . $p['from']] ?? null;
            $p['reverse_total'] = $rev['total'] ?? 0;
            $p['mutual'] = ($rev['total'] ?? 0) > 0;
            $p['dispatch_targets'] = $dispatch[$k] ?? 0;
            $out[] = $p;
        }
        usort($out, fn($x, $y) => $y['total'] <=> $x['total']);
        return [
            'group_by' => $groupBy,
            'pairs' => array_slice($out, 0, max(1, $limit)),
        ];
    }

    /**
     * 分层规则校验：允许「模块 → Framework」，模块之间只有 register.php 显式声明依赖才算合法。
     * 返回未声明依赖的跨模块调用（含互调标记、类对/方法级样例）。
     *
     * @return array<string,mixed>
     */
    public function layering(?string $onlyModule = null, int $limit = 40): array
    {
        $g = $this->load();
        $nodes = $g['nodes'];
        $mods = $g['mods'];
        $depRaw = $g['meta']['mod_deps'] ?? [];
        $requiresRaw = $g['meta']['mod_requires'] ?? $depRaw;
        $optionalRaw = $g['meta']['mod_opt_deps'] ?? [];
        $normalize = static function (array $raw): array {
            $out = [];
            foreach ($raw as $mod => $list) {
                $out[str_replace('_', '.', (string)$mod)] = array_map(
                    static fn($d): string => str_replace('_', '.', (string)$d),
                    (array)$list
                );
            }
            return $out;
        };
        $declared = $normalize($depRaw);
        $requires = $normalize($requiresRaw);
        $optional = $normalize($optionalRaw);
        $modOf = static fn(int $i): string => $mods[$nodes[$i]['m']] ?? '_';
        $allowed = static function (string $src, string $dst) use ($declared): bool {
            if ($src === $dst || $dst === 'Weline.Framework') {
                return true;
            }
            return in_array($dst, $declared[$src] ?? [], true);
        };
        $dependencyOf = static function (string $src, string $dst) use ($requires, $optional): string {
            if (in_array($dst, $requires[$src] ?? [], true)) {
                return 'requires';
            }
            if (in_array($dst, $optional[$src] ?? [], true)) {
                return 'optional';
            }
            return 'absent';
        };
        $declaredPairs = [];
        $types = ['s' => 'static', 'i' => 'instance'];
        $pairs = [];
        $classPairs = [];
        $methods = [];
        /* 接口分发（u）是「可能实现」不是依赖：收集其配对，方法级样例里跳过 */
        $dispatchPairs = [];
        foreach ($g['edges'] as $e) {
            if (($e[2] ?? '') === 'u') {
                $dispatchPairs[$e[0] . '_' . $e[1]] = true;
            }
        }
        /* 分类：测试/夹具调用不计违规；调目标 \Api\ 命名空间算「契约未声明」，其余算「直连违规」 */
        $isTestFile = static function (string $file): bool {
            return str_contains($file, '/Test/') || str_contains($file, '/test/')
                || str_contains($file, '/tests/') || str_contains($file, '/fixtures/')
                || str_ends_with($file, '.script.php');
        };
        foreach ($g['edges'] as $e) {
            [$a, $b, $t] = $e;
            if (!isset($types[$t])) {
                continue;
            }
            $src = $modOf((int)$a);
            $dst = $modOf((int)$b);
            if ($allowed($src, $dst)) {
                if ($src !== $dst && $dst !== 'Weline.Framework' && !$isTestFile((string)$nodes[(int)$a]['f'])) {
                    $declaredPairs[$src . "\t" . $dst] = $dependencyOf($src, $dst);
                }
                continue;
            }
            $k = $src . "\t" . $dst;
            $pairs[$k] ??= ['from' => $src, 'to' => $dst, 'static' => 0, 'instance' => 0, 'total' => 0, 'direct' => 0, 'api' => 0, 'test' => 0, 'dependency' => $dependencyOf($src, $dst)];
            $isTest = $isTestFile((string)$nodes[(int)$a]['f']);
            $isApi = str_contains((string)$nodes[(int)$b]['fq'], '\\Api\\');
            $pairs[$k][$isTest ? 'test' : ($isApi ? 'api' : 'direct')]++;
            if (!$isTest) {
                $pairs[$k][$types[$t]]++;
                $pairs[$k]['total']++;
                $classPairs[$k][$nodes[$a]['fq'] . ' -> ' . $nodes[$b]['fq']] =
                    ($classPairs[$k][$nodes[$a]['fq'] . ' -> ' . $nodes[$b]['fq']] ?? 0) + 1;
            }
        }
        foreach ($g['mcalls'] as $key => $targets) {
            $colon = strpos((string)$key, ':');
            $fi = (int)substr((string)$key, 0, $colon);
            $src = $modOf($fi);
            if ($isTestFile((string)$nodes[$fi]['f'])) {
                continue;
            }
            $fm = substr((string)$key, $colon + 1);
            foreach ($targets as $t) {
                [$ti, $tm] = explode(':', (string)$t, 2);
                if (isset($dispatchPairs[$fi . '_' . (int)$ti])) {
                    continue; // 接口分发的可能实现，不算依赖
                }
                $dst = $modOf((int)$ti);
                $k = $src . "\t" . $dst;
                if (!isset($pairs[$k])) {
                    continue;
                }
                $methods[$k][$nodes[$fi]['fq'] . '::' . $fm . ' → ' . $nodes[(int)$ti]['fq'] . '::' . $tm] = true;
                if (count($methods[$k] ?? []) >= 200) {
                    break;
                }
            }
        }
        $out = [];
        foreach ($pairs as $k => $p) {
            if ($onlyModule !== null && $p['from'] !== $onlyModule && $p['to'] !== $onlyModule) {
                continue;
            }
            if ($p['total'] === 0) {
                continue; // 只有测试文件在调：不算违规
            }
            $rev = $pairs[$p['to'] . "\t" . $p['from']] ?? null;
            $p['mutual'] = $rev !== null && ($rev['total'] ?? 0) > 0;
            $p['reverse_total'] = $rev['total'] ?? 0;
            $p['severity'] = $p['direct'] > 0 ? 'direct' : 'api';
            $cp = $classPairs[$k] ?? [];
            arsort($cp);
            $p['class_pairs'] = array_slice($cp, 0, 5, true);
            $p['method_pairs'] = array_slice(array_keys($methods[$k] ?? []), 0, 5);
            $out[] = $p;
        }
        usort($out, fn($x, $y) => [$y['direct'], $y['total']] <=> [$x['direct'], $x['total']]);
        $totalCalls = array_sum(array_column($out, 'total'));
        $totalTest = array_sum(array_column($out, 'test'));
        $directPairs = count(array_filter($out, static fn($p): bool => $p['direct'] > 0));
        $declaredRequires = count(array_filter($declaredPairs, static fn($d): bool => $d === 'requires'));
        return [
            'rule' => '模块可以调用 Weline.Framework；模块之间调用必须在其 etc/module.php（缺 manifest 时 register.php）依赖声明中显式列出',
            'modules_with_violations' => count(array_unique(array_merge(array_column($out, 'from'), array_column($out, 'to')))),
            'pairs' => count($out),
            'calls' => $totalCalls,
            'direct_pairs' => $directPairs,
            'api_pairs' => count($out) - $directPairs,
            'test_calls_ignored' => $totalTest,
            'declared_pairs' => count($declaredPairs),
            'declared_pairs_requires' => $declaredRequires,
            'declared_pairs_optional' => count($declaredPairs) - $declaredRequires,
            'violations' => array_slice($out, 0, max(1, $limit)),
        ];
    }

    /**
     * 两个模块之间的耦合明细：谁调谁（类对次数）+ 方法级样例。
     * 只统计真实调用（s 静态 / i 实例），不含接口分发。
     *
     * @return array<string,mixed>
     */
    public function coupling(string $from, string $to, int $limit = 20): array
    {
        $g = $this->load();
        $nodes = $g['nodes'];
        $mods = $g['mods'];
        $modOf = static fn(int $i): string => $mods[$nodes[$i]['m']] ?? '_';
        $types = ['s' => 'static', 'i' => 'instance'];
        $classPairs = [];
        $totals = ['static' => 0, 'instance' => 0];
        foreach ($g['edges'] as $e) {
            [$a, $b, $t] = $e;
            if (!isset($types[$t]) || $modOf((int)$a) !== $from || $modOf((int)$b) !== $to) {
                continue;
            }
            $key = $nodes[$a]['fq'] . ' -> ' . $nodes[$b]['fq'];
            $classPairs[$key] = ($classPairs[$key] ?? 0) + 1;
            $totals[$types[$t]]++;
        }
        arsort($classPairs);
        $methods = [];
        foreach ($g['mcalls'] as $key => $targets) {
            $colon = strpos((string)$key, ':');
            $fi = (int)substr((string)$key, 0, $colon);
            if ($modOf($fi) !== $from) {
                continue;
            }
            $fm = substr((string)$key, $colon + 1);
            foreach ($targets as $t) {
                [$ti, $tm] = explode(':', (string)$t, 2);
                if ($modOf((int)$ti) !== $to) {
                    continue;
                }
                $methods[$nodes[$fi]['fq'] . '::' . $fm . ' → ' . $nodes[(int)$ti]['fq'] . '::' . $tm] = true;
                if (count($methods) >= 500) {
                    break 2;
                }
            }
        }
        return [
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'class_pairs' => array_slice($classPairs, 0, max(1, $limit), true),
            'method_pairs' => array_slice(array_keys($methods), 0, max(1, $limit)),
        ];
    }

    /** @param array<string,mixed> $g */
    public function resolveNode(array $g, string $fqOrShort): int
    {
        foreach ($g['nodes'] as $i => $n) {
            if ($n['fq'] === $fqOrShort) {
                return $i;
            }
        }
        $q = strtolower($fqOrShort);
        foreach ($g['nodes'] as $i => $n) {
            if (strtolower($n['fq']) === $q) {
                return $i;
            }
        }
        $hits = $this->find($fqOrShort, 5);
        if (!$hits) {
            throw new \RuntimeException('未找到类: ' . $fqOrShort);
        }
        throw new \RuntimeException('未找到精确类名，候选: ' . implode(', ', array_column($hits, 'fqcn')));
    }

    /** @return list<string> */
    private function callersOf(array $g, string $key): array
    {
        $out = [];
        foreach ($g['mcalls'] as $from => $targets) {
            if (in_array($key, $targets, true)) {
                $out[] = (string)$from;
            }
        }
        return $out;
    }

    /** @return list<array{class:string,method:string}> */
    private function resolveKeys(array $g, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $c = strpos($k, ':');
            $ti = (int)substr($k, 0, $c);
            $out[] = ['class' => $g['nodes'][$ti]['fq'] ?? '?', 'method' => substr($k, $c + 1)];
        }
        return $out;
    }

    /** @return list<array{fqcn:string,type:string}> */
    private function edgeList(array $g, int $i, string $dir, int $limit): array
    {
        $out = [];
        foreach ($g['edges'] as $e) {
            [$a, $b, $t] = $e;
            if ($dir === 'out' && $a === $i) {
                $out[] = ['fqcn' => $g['nodes'][$b]['fq'], 'type' => self::ETYPES[$t] ?? $t];
            } elseif ($dir === 'in' && $b === $i) {
                $out[] = ['fqcn' => $g['nodes'][$a]['fq'], 'type' => self::ETYPES[$t] ?? $t];
            }
        }
        return array_slice($out, 0, $limit);
    }

    /** @return array<string,mixed> */
    private function nodeSummary(array $g, int $i): array
    {
        $n = $g['nodes'][$i];
        return [
            'fqcn' => $n['fq'],
            'kind' => self::KINDS[$n['k']] ?? $n['k'],
            'module' => $g['mods'][$n['m']] ?? '',
            'file' => $n['f'],
            'lines' => $n['lc'],
            'methods' => count($n['mt']),
        ];
    }
}
