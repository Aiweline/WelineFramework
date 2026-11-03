<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Code3d;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * 扫描 app/code 下 PHP 文件（php-parser AST），构建 3D 图数据：
 * meta / mods / nodes / edges / mcalls。只读仓库，不修改任何源码。
 */
final class CodeGraphBuilder
{
    /**
     * @param string $repoRoot 仓库根（含 app/code）
     * @param string $srcDir   源码目录绝对路径
     * @return array{meta:array<string,mixed>,mods:list<string>,nodes:list<array<string,mixed>>,edges:list<array{int,int,string}>,mcalls:array<string,list<string>>}
     */
    public function build(string $repoRoot, string $srcDir): array
    {
        $t0 = microtime(true);
        $repoRoot = rtrim($repoRoot, '/') . '/';

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $p = $f->getPathname();
            if (str_contains($p, '/doc/') || str_contains($p, '/fixtures/')) {
                continue;
            }
            /* 测试目录不参与调用图：Test/Test/test/tests 目录里的类与短名会污染
             * typeIndex（如 tests 里的伪 class Registry），造成短名误归属。 */
            if (preg_match('#/[Tt]ests?/#', $p)) {
                continue;
            }
            $files[] = $p;
        }
        sort($files);

        $visitor = new CodeGraphVisitor();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor($visitor);

        $parseErrors = [];
        foreach ($files as $file) {
            $code = @file_get_contents($file);
            if ($code === false || !str_contains($code, '<?php')) {
                continue;
            }
            try {
                $ast = $parser->parse($code);
            } catch (\Throwable $e) {
                $parseErrors[] = [substr($file, strlen($repoRoot)), $e->getMessage()];
                continue;
            }
            $visitor->setFile(substr($file, strlen($repoRoot)));
            $traverser->traverse($ast);
        }

        $graph = $this->normalize($visitor, $files, $parseErrors, $srcDir, $repoRoot, $t0);
        /* 模块依赖声明（etc/module.php 权威，register.php 仅回退），供分层规则校验 */
        $dependencyMap = new ModuleDependencyMap();
        $detailed = $dependencyMap->loadDetailed($repoRoot);
        $requires = [];
        $optional = [];
        foreach ($detailed as $module => $deps) {
            $requires[$module] = $deps['requires'];
            $optional[$module] = $deps['optional'];
        }
        $graph['meta']['mod_deps'] = $dependencyMap->load($repoRoot);
        $graph['meta']['mod_requires'] = $requires;
        $graph['meta']['mod_opt_deps'] = $optional;
        return $graph;
    }

    private function normalize(
        CodeGraphVisitor $visitor,
        array $files,
        array $parseErrors,
        string $srcDir,
        string $repoRoot,
        float $t0
    ): array {
        $known = $visitor->known;
        $edgesOut = [];
        $seen = [];
        $extRefs = [];
        foreach ($visitor->edges as $key => $type) {
            [$fromFq, $toFq] = explode("\t", $key);
            $i = $known[$fromFq] ?? null;
            $j = $known[$toFq] ?? null;
            if ($i === null) {
                continue;
            }
            if ($j === null) {
                $extRefs[$toFq] = true;
                continue;
            }
            if ($i === $j) {
                continue;
            }
            $sig = $i . '_' . $j . '_' . $type;
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            $edgesOut[] = [$i, $j, $type];
        }

        /* 动态（实例）调用：按推断出的接收者类型落边；接口类型分发到全部实现（u） */
        $impl = [];
        foreach ($visitor->edges as $key => $type) {
            if ($type !== 'e') {
                continue;
            }
            [$fromFq, $toFq] = explode("\t", $key);
            $impl[$toFq][$fromFq] = true;
        }
        $typeIndex = [];
        foreach ($known as $fq => $idx) {
            if (str_starts_with($fq, 'fn:')) {
                continue;
            }
            $pos = strrpos($fq, '\\');
            $typeIndex[strtolower($pos === false ? $fq : substr($fq, $pos + 1))][] = $fq;
        }
        /* 父类链（child => parent）：用于把继承自父类的实例调用归属到方法定义类 */
        $parentOf = [];
        foreach ($visitor->edges as $key => $type) {
            if ($type !== 'e') {
                continue;
            }
            [$fromFq, $toFq] = explode("\t", $key);
            $parentOf[$fromFq] = $toFq;
        }
        $resolveType = static function (string $raw) use ($known, $typeIndex): ?string {
            $raw = ltrim($raw, '\\');
            if (isset($known[$raw])) {
                return $raw;
            }
            $pos = strrpos($raw, '\\');
            $short = strtolower($pos === false ? $raw : substr($raw, $pos + 1));
            $cands = $typeIndex[$short] ?? [];
            return count($cands) === 1 ? $cands[0] : null;
        };
        $dynEdges = 0;
        $dynUnknown = 0;
        $extraCalls = [];
        foreach ($visitor->instanceCalls as $ic) {
            if (!isset($known[$ic['from']])) {
                continue;
            }
            $recv = $resolveType($ic['recv']);
            if ($recv === null) {
                $dynUnknown++;
                continue;
            }
            $recvIdx = $known[$recv] ?? null;
            $recvNode = $recvIdx !== null ? ($visitor->nodes[$recvIdx] ?? null) : null;
            $fanOut = $recvNode !== null && ($recvNode['k'] === 'i' || !empty($recvNode['ab']));
            /* 精确目标（接口/抽象类本身 = 声明方模块）始终是真实调用边 'i' */
            $fromIdx = $known[$ic['from']];
            if ($recv !== $ic['from'] && isset($known[$recv])) {
                /* 接收者未自行定义目标方法时，沿父类链找定义类；定义类即调用落点，
                 * 避免把「传入子类后调用父类方法」误归属成对子类所属模块的耦合。 */
                $methodOwner = $recv;
                $probe = $recv;
                $hops = 0;
                while ($hops < 8) {
                    $probeNode = $visitor->nodes[$known[$probe]] ?? null;
                    if ($probeNode !== null && in_array($ic['target'], $probeNode['mt'] ?? [], true)) {
                        break;
                    }
                    $next = $parentOf[$probe] ?? null;
                    if ($next === null || !isset($known[$next])) {
                        $methodOwner = null;
                        break;
                    }
                    $probe = $next;
                    $hops++;
                }
                if ($methodOwner !== null && $methodOwner !== $recv) {
                    /* 目标方法定义在父类：父类模块内部调用，不记跨模块边 */
                    $methodOwnerIdx = $known[$methodOwner];
                    if ($methodOwnerIdx !== $fromIdx) {
                        $extraCalls[$fromIdx . ':' . $ic['method']][$methodOwnerIdx . ':' . $ic['target']] = true;
                    }
                } elseif ($methodOwner === $recv) {
                    $sigI = $fromIdx . '_' . $known[$recv] . '_i';
                    if (!isset($seen[$sigI])) {
                        $seen[$sigI] = true;
                        $edgesOut[] = [$fromIdx, $known[$recv], 'i'];
                        $dynEdges++;
                    }
                    $extraCalls[$fromIdx . ':' . $ic['method']][$known[$recv] . ':' . $ic['target']] = true;
                }
                /* $methodOwner === null：沿父类链找不到目标方法定义（含 __call 魔术方法，
                 * 如 Model::getData）。无法证明接收者自身拥有该方法，不记跨模块耦合边——
                 * 这类调用本质是父类 __call 分发，归属父类模块内部。 */
            }
            /* 接口/抽象类的可能实现记 'u'：供影响面参考，不计入模块耦合统计 */
            if ($fanOut && isset($impl[$recv])) {
                foreach (array_keys($impl[$recv]) as $implFq) {
                    if ($implFq === $ic['from'] || !isset($known[$implFq])) {
                        continue;
                    }
                    $sigU = $fromIdx . '_' . $known[$implFq] . '_u';
                    if (!isset($seen[$sigU])) {
                        $seen[$sigU] = true;
                        $edgesOut[] = [$fromIdx, $known[$implFq], 'u'];
                        $dynEdges++;
                    }
                    $extraCalls[$fromIdx . ':' . $ic['method']][$known[$implFq] . ':' . $ic['target']] = true;
                }
            }
        }

        $callSets = [];
        foreach ($visitor->mcalls as $k => $targets) {
            foreach (array_keys($targets) as $t) {
                $callSets[$k][(string)$t] = true;
            }
        }
        foreach ($extraCalls as $k => $targets) {
            foreach (array_keys($targets) as $t) {
                $callSets[$k][$t] = true;
            }
        }
        $mcallsOut = [];
        foreach ($callSets as $k => $targets) {
            $colon = strpos($k, ':');
            $fi = (int)substr($k, 0, $colon);
            $fMethod = substr($k, $colon + 1);
            $list = [];
            foreach (array_keys($targets) as $t) {
                [$ti, $tm] = explode(':', (string)$t, 2);
                if ((int)$ti === $fi && $tm === $fMethod) {
                    continue;
                }
                $list[] = (string)$t;
            }
            if ($list) {
                $mcallsOut[$k] = $list;
            }
        }

        $nodes = $visitor->nodes;
        $mods = array_keys($visitor->mods);
        $kindCount = ['c' => 0, 'i' => 0, 't' => 0, 'n' => 0, 'f' => 0];
        foreach ($nodes as $n) {
            $kindCount[$n['k']] = ($kindCount[$n['k']] ?? 0) + 1;
        }
        $edgeCount = ['e' => 0, 'd' => 0, 's' => 0, 'i' => 0, 'u' => 0];
        foreach ($edgesOut as $e) {
            $edgeCount[$e[2]] = ($edgeCount[$e[2]] ?? 0) + 1;
        }
        $methodTotal = 0;
        foreach ($nodes as $n) {
            $methodTotal += count($n['mt']);
        }

        return [
            'meta' => [
                'schema' => 'weline-code3d-graph.v1',
                'gen_at' => date('c'),
                'src' => substr($srcDir, strlen($repoRoot)),
                'files_scanned' => count($files),
                'parse_errors' => count($parseErrors),
                'parse_error_samples' => array_slice($parseErrors, 0, 5),
                'nodes' => count($nodes),
                'kinds' => $kindCount,
                'methods' => $methodTotal,
                'edges' => count($edgesOut),
                'edge_kinds' => $edgeCount,
                'mcalls' => count($mcallsOut),
                'dyn_calls_resolved' => $dynEdges,
                'dyn_calls_unknown' => $dynUnknown,
                'external_refs' => count($extRefs),
                'modules' => count($mods),
                'runtime_s' => round(microtime(true) - $t0, 2),
            ],
            'mods' => $mods,
            'nodes' => $nodes,
            'edges' => $edgesOut,
            'mcalls' => $mcallsOut,
        ];
    }
}
