<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Service\Code3d;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

/**
 * 代码 3D 图的 AST 访问器：
 * 节点 = class / interface / trait / enum / 顶层函数；方法清单；
 * 边 = 继承(e) / 依赖(d) / 静态调用(s)；方法级调用 = 静态调用 + $this-> 调用。
 */
final class CodeGraphVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array<string, mixed>> */
    public array $nodes = [];
    /** @var array<string, int> fq => 节点下标 */
    public array $known = [];
    /** @var array<string, string> "fromFq\ttoFq\ttype" => type */
    public array $edges = [];
    /** @var array<string, array<string, true>> "fromIdx:method" => ["toIdx:method" => true] */
    public array $mcalls = [];
    /** @var array<string, int> 模块名 => 下标 */
    public array $mods = [];
    /** @var array<string, array<string, true>> 外部类引用（未扫描到） */
    public array $external = [];
    /** @var array<string, array<string, string>> 类 => 属性名 => 类型（原生类型 / @var / 构造器提升） */
    public array $classProps = [];
    /** @var array<string, array<string, string>> 类 => 方法名 => 返回类型 */
    public array $methodReturns = [];
    /** @var list<array{from:string,method:string,recv:string,target:string}> 实例调用（待按类型/接口分发解析） */
    public array $instanceCalls = [];
    /** @var array<string, string> 当前方法的变量类型环境：$name / this->prop / this => fq */
    private array $varEnv = [];

    private int $scope = -1;
    private string $scopeFq = '';
    private string $parentFq = '';
    private ?string $curMethod = null;
    private string $relFile = '';

    public function setFile(string $relFile): void
    {
        $this->relFile = $relFile;
    }

    private function modIdx(string $relFile): int
    {
        $rest = $relFile;
        if (str_starts_with($rest, 'app/code/')) {
            $rest = substr($rest, strlen('app/code/'));
            $segs = explode('/', $rest);
            $vendor = $segs[0] ?? '_root';
            $module = $segs[1] ?? '_root';
            $name = ($vendor === '' || $module === '' || $module === '_root')
                ? $vendor
                : $vendor . '.' . $module;
        } else {
            $name = '_root';
        }
        if (!isset($this->mods[$name])) {
            $this->mods[$name] = count($this->mods);
        }
        return $this->mods[$name];
    }

    /** 同名类出现在多个文件时的择优评分：命名空间与路径匹配优先，Test/桩文件降权 */
    private function fileScore(string $fq, string $relFile, string $short): int
    {
        $score = 0;
        $parts = explode('\\', ltrim($fq, '\\'));
        if (($parts[0] ?? '') === 'Weline' && isset($parts[1])) {
            if (str_contains($relFile, '/Weline/' . $parts[1] . '/')) {
                $score += 3;
            }
        } elseif (str_contains($relFile, '/' . ($parts[0] ?? 'x') . '/')) {
            $score += 1;
        }
        if (str_contains($relFile, '/Test/') || str_contains($relFile, '/tests/') || str_ends_with($relFile, '.script.php')) {
            $score -= 3;
        }
        if (str_contains($relFile, '/fixtures/')) {
            $score -= 2;
        }
        if (basename($relFile) === $short . '.php') {
            $score += 2;
        }
        return $score;
    }

    private function register(string $fq, string $short, string $kind, int $start, int $end, bool $abstract = false): int
    {
        if (isset($this->known[$fq])) {
            $idx = $this->known[$fq];
            $cur = $this->nodes[$idx];
            if ($this->fileScore($fq, $this->relFile, $short) > $this->fileScore($fq, $cur['f'], $short)) {
                $this->nodes[$idx]['f'] = $this->relFile;
                $this->nodes[$idx]['m'] = $this->modIdx($this->relFile);
                $this->nodes[$idx]['lc'] = max(1, $end - $start + 1);
                $this->nodes[$idx]['ab'] = $abstract;
            }
            return $idx;
        }
        $idx = count($this->nodes);
        $this->known[$fq] = $idx;
        $this->nodes[] = [
            'fq' => $fq,
            'n' => $short,
            'k' => $kind,
            'm' => $this->modIdx($this->relFile),
            'f' => $this->relFile,
            'lc' => max(1, $end - $start + 1),
            'mt' => [],
            'ab' => $abstract,
        ];
        return $idx;
    }

    private function addEdge(string $fromFq, int $fromIdx, string $toFq, string $type): void
    {
        if ($toFq === '' || strcasecmp($toFq, $fromFq) === 0) {
            return;
        }
        $this->edges[$fromFq . "\t" . $toFq . "\t" . $type] = $type;
    }

    private function addCall(int $fromIdx, string $fromMethod, int $toIdx, string $toMethod): void
    {
        $this->mcalls[$fromIdx . ':' . $fromMethod][$toIdx . ':' . $toMethod] = true;
    }

    /** 调用名：php-parser v5 里是 Identifier 对象，少数场景是 string */
    private function callName($n): ?string
    {
        if ($n instanceof Node\Identifier) {
            return $n->toString();
        }
        return is_string($n) ? $n : null;
    }

    private function resolveClass(Name $n): string
    {
        $s = $n->toString();
        $lc = strtolower($s);
        if ($lc === 'self' || $lc === 'static') {
            return $this->scopeFq;
        }
        if ($lc === 'parent') {
            return $this->parentFq;
        }
        return $s;
    }

    /** @return list<string> */
    private function namesFromType(Node $t): array
    {
        if ($t instanceof NullableType) {
            return $this->namesFromType($t->type);
        }
        if ($t instanceof UnionType || $t instanceof IntersectionType) {
            $out = [];
            foreach ($t->types as $sub) {
                if ($sub instanceof Node) {
                    $out = array_merge($out, $this->namesFromType($sub));
                }
            }
            return $out;
        }
        if ($t instanceof Name) {
            $s = $t->toString();
            if (in_array(strtolower($s), ['self', 'static', 'parent'], true)) {
                return [];
            }
            return [$s];
        }
        return [];
    }

    /** 从 docblock 取类类型：@return Foo / @var Foo / @param Foo $x */
    private function docType(?string $doc, string $tag, string $varName = ''): ?string
    {
        if ($doc === null || $doc === '') {
            return null;
        }
        foreach (preg_split('/\R/', $doc) as $line) {
            $pos = strpos($line, '@' . $tag);
            if ($pos === false) {
                continue;
            }
            if ($varName !== '' && !str_contains($line, '$' . $varName)) {
                continue;
            }
            $rest = trim(substr($line, $pos + strlen($tag) + 1));
            if ($rest === '') {
                continue;
            }
            $first = preg_split('/\s+/', $rest)[0] ?? '';
            $first = ltrim(trim($first), '?');
            $first = preg_replace('/\[\]$/', '', $first);
            if ($first === '' || !preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $first)) {
                continue;
            }
            if (in_array(strtolower($first), [
                'int', 'string', 'bool', 'boolean', 'float', 'double', 'array', 'mixed', 'void',
                'null', 'object', 'callable', 'iterable', 'self', 'static', 'this', 'false', 'true',
            ], true)) {
                continue;
            }
            return $first;
        }
        return null;
    }

    /** 推断表达式的类类型（fq 或原始类名）；无法判断返回 null */
    private function exprType(Node $e): ?string
    {
        if ($e instanceof New_ && $e->class instanceof Name) {
            return $this->resolveClass($e->class);
        }
        if ($e instanceof StaticCall && $e->class instanceof Name) {
            $m = $this->callName($e->name);
            if ($m === null) {
                return null;
            }
            /* 容器/单例：ObjectManager::getInstance(Foo::class) 之类 */
            $arg = $e->args[0] ?? null;
            if (in_array(strtolower($m), ['getinstance', 'instance', 'make', 'singleton', 'create'], true)
                && $arg instanceof Node\Arg && $arg->value instanceof ClassConstFetch
                && $arg->value->class instanceof Name
                && $arg->value->name instanceof Node\Identifier
                && strtolower($arg->value->name->toString()) === 'class'
            ) {
                return $this->resolveClass($arg->value->class);
            }
            $tf = $this->resolveClass($e->class);
            return $this->methodReturns[$tf][$m] ?? null;
        }
        if ($e instanceof Node\Expr\PropertyFetch
            && $e->var instanceof Node\Expr\Variable && $e->var->name === 'this'
            && $e->name instanceof Node\Identifier
        ) {
            $pn = $e->name->toString();
            return $this->classProps[$this->scopeFq][$pn] ?? ($this->varEnv['this->' . $pn] ?? null);
        }
        if ($e instanceof Node\Expr\Variable && is_string($e->name)) {
            return $this->varEnv[$e->name] ?? null;
        }
        if ($e instanceof MethodCall || $e instanceof NullsafeMethodCall) {
            $m = $this->callName($e->name);
            if ($m === null) {
                return null;
            }
            if ($e->var instanceof Node\Expr\Variable && $e->var->name === 'this') {
                return $this->methodReturns[$this->scopeFq][$m] ?? null;
            }
            $recv = $this->exprType($e->var);
            if ($recv === null) {
                return null;
            }
            return $this->methodReturns[$recv][$m] ?? null;
        }
        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
            $nsName = $node->namespacedName ?? null;
            if ($nsName === null) {
                return null;
            }
            $fq = $nsName->toString();
            $kind = $node instanceof Class_ ? 'c' : ($node instanceof Interface_ ? 'i' : ($node instanceof Trait_ ? 't' : 'n'));
            $idx = $this->register(
                $fq,
                $node->name->toString(),
                $kind,
                $node->getStartLine(),
                $node->getEndLine(),
                $node instanceof Class_ && $node->isAbstract()
            );
            $this->scope = $idx;
            $this->scopeFq = $fq;
            $parent = '';
            if ($node instanceof Class_ && $node->extends instanceof Name) {
                $parent = $node->extends->toString();
                $this->addEdge($fq, $idx, $parent, 'e');
            } elseif ($node instanceof Interface_) {
                foreach ($node->extends as $e) {
                    $this->addEdge($fq, $idx, $e->toString(), 'e');
                }
            } elseif ($node instanceof Enum_) {
                foreach ($node->implements as $e) {
                    $this->addEdge($fq, $idx, $e->toString(), 'e');
                }
            }
            $this->parentFq = $parent;
            return null;
        }

        if ($node instanceof Function_) {
            $fq = 'fn:' . ($node->namespacedName?->toString() ?? $node->name->toString());
            $this->scope = $this->register($fq, $node->name->toString(), 'f', $node->getStartLine(), $node->getEndLine());
            $this->scopeFq = $fq;
            $this->parentFq = '';
            return null;
        }

        if ($node instanceof ClassMethod && $this->scope >= 0) {
            $methodName = $node->name->toString();
            $this->nodes[$this->scope]['mt'][] = $methodName;
            $this->curMethod = $methodName;
            $doc = $node->getDocComment()?->getText();
            $ret = null;
            if ($node->returnType instanceof Node) {
                foreach ($this->namesFromType($node->returnType) as $t) {
                    $this->addEdge($this->scopeFq, $this->scope, $t, 'd');
                    $ret = $ret ?: $t;
                }
            } else {
                $ret = $this->docType($doc, 'return');
            }
            if ($ret !== null) {
                $this->methodReturns[$this->scopeFq][$methodName] = $ret;
            }
            /* 变量类型环境：$this + 参数（原生类型优先，docblock @param 兜底） */
            $this->varEnv = ['this' => $this->scopeFq];
            foreach ($node->params as $p) {
                if (!$p instanceof Param || !$p->var instanceof Node\Expr\Variable || !is_string($p->var->name)) {
                    continue;
                }
                $type = null;
                if ($p->type instanceof Node) {
                    $names = $this->namesFromType($p->type);
                    $type = $names[0] ?? null;
                } else {
                    $type = $this->docType($doc, 'param', $p->var->name);
                }
                if ($type !== null) {
                    $this->varEnv[$p->var->name] = $type;
                }
                /* 构造器属性提升：private Foo $svc */
                if ($this->scopeFq !== '' && $p->flags !== 0 && $type !== null) {
                    $this->classProps[$this->scopeFq][$p->var->name] = $type;
                }
            }
            return null;
        }

        if ($node instanceof Property && $this->scope >= 0) {
            $type = null;
            $names = [];
            if ($node->type instanceof Node) {
                $names = $this->namesFromType($node->type);
                $type = $names[0] ?? null;
                foreach ($names as $t) {
                    $this->addEdge($this->scopeFq, $this->scope, $t, 'd');
                }
            } else {
                $type = $this->docType($node->getDocComment()?->getText(), 'var');
            }
            if ($type !== null) {
                foreach ($node->props as $prop) {
                    if ($prop instanceof Node\Stmt\PropertyProperty && $prop->name instanceof Node\Identifier) {
                        $this->classProps[$this->scopeFq][$prop->name->toString()] = $type;
                    }
                }
            }
            return null;
        }

        if ($node instanceof Param && $node->type instanceof Node) {
            foreach ($this->namesFromType($node->type) as $t) {
                $this->addEdge($this->scopeFq, $this->scope, $t, 'd');
            }
            return null;
        }

        if ($node instanceof TraitUse && $this->scope >= 0) {
            foreach ($node->traits as $t) {
                $this->addEdge($this->scopeFq, $this->scope, $t->toString(), 'e');
            }
            return null;
        }

        /* 赋值：记录变量/属性类型，供后续实例调用解析 */
        if ($node instanceof Node\Expr\Assign && $this->scope >= 0) {
            $t = $this->exprType($node->expr);
            if ($t !== null) {
                if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                    $this->varEnv[$node->var->name] = $t;
                } elseif ($node->var instanceof Node\Expr\PropertyFetch
                    && $node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this'
                    && $node->var->name instanceof Node\Identifier
                ) {
                    $pn = $node->var->name->toString();
                    $this->classProps[$this->scopeFq][$pn] = $t;
                    $this->varEnv['this->' . $pn] = $t;
                }
            }
            return null;
        }

        if ($node instanceof StaticCall && $node->class instanceof Name && ($mName = $this->callName($node->name)) !== null) {
            $tf = $this->resolveClass($node->class);
            if ($tf !== '') {
                $this->addEdge($this->scopeFq, $this->scope, $tf, 's');
                if (isset($this->known[$tf]) && $this->curMethod !== null) {
                    $this->addCall($this->scope, $this->curMethod, $this->known[$tf], $mName);
                }
            }
            return null;
        }

        if (($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && ($mName = $this->callName($node->name)) !== null && $this->scope >= 0
            && $this->curMethod !== null
        ) {
            if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                $this->addCall($this->scope, $this->curMethod, $this->scope, $mName);
                return null;
            }
            /* 动态/实例调用：接收者类型可推断则记录，解析阶段按精确类或接口分发落边 */
            $recv = $this->exprType($node->var);
            if ($recv !== null && $recv !== '') {
                $this->instanceCalls[] = [
                    'from' => $this->scopeFq,
                    'method' => $this->curMethod,
                    'recv' => $recv,
                    'target' => $mName,
                ];
            }
            return null;
        }

        if ($node instanceof New_ && $node->class instanceof Name) {
            $this->addEdge($this->scopeFq, $this->scope, $this->resolveClass($node->class), 'd');
            return null;
        }

        if ($node instanceof ClassConstFetch && $node->class instanceof Name) {
            if (!in_array(strtolower($node->class->toString()), ['self', 'static', 'parent'], true)) {
                $this->addEdge($this->scopeFq, $this->scope, $node->class->toString(), 'd');
            }
            return null;
        }

        if ($node instanceof Instanceof_ && $node->class instanceof Name) {
            $this->addEdge($this->scopeFq, $this->scope, $node->class->toString(), 'd');
            return null;
        }

        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $t) {
                $this->addEdge($this->scopeFq, $this->scope, $t->toString(), 'd');
            }
            return null;
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_
            || $node instanceof Enum_ || $node instanceof Function_
        ) {
            $this->scope = -1;
            $this->scopeFq = '';
            $this->parentFq = '';
            $this->curMethod = null;
        }
        if ($node instanceof ClassMethod) {
            $this->curMethod = null;
        }
        return null;
    }
}
