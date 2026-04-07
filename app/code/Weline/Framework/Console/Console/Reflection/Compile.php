<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console\Console\Reflection;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * 空目录迭代器，用于在无权限子目录处“不下降”，满足 getChildren() 返回类型
 */
class EmptyRecursiveDirectoryIterator extends \RecursiveDirectoryIterator
{
    public function __construct()
    {
        $emptyDir = \sys_get_temp_dir() . '/weline_reflection_empty_' . \getmypid();
        if (!\is_dir($emptyDir)) {
            @\mkdir($emptyDir, 0700, true);
        }
        parent::__construct($emptyDir, \RecursiveDirectoryIterator::SKIP_DOTS);
    }

    public function hasChildren(bool $allowLinks = false): bool
    {
        return false;
    }
}

/**
 * 递归目录迭代器：子目录无权限时跳过该目录，不抛异常
 */
class SafeRecursiveDirectoryIterator extends \RecursiveDirectoryIterator
{
    public function getChildren(): \RecursiveDirectoryIterator
    {
        try {
            return parent::getChildren();
        } catch (\Throwable $e) {
            // 常见于 root 创建了 view/tpl、generated 等目录，weline 用户执行 CLI 时无读权限
            w_log_warning(__('Reflection Compile: 跳过无权限目录 %{1}，原因：%{2}', [$this->getPathname(), $e->getMessage()]));
            return new EmptyRecursiveDirectoryIterator();
        }
    }
}

/**
 * 预编译反射元数据与编译型工厂
 *
 * 1. 正则扫描框架内 ObjectManager::getInstance/make 的 ::class 调用（热点类）
 * 2. 扫描所有已注册模块的类，预编译构造函数参数元数据
 * 3. 生成 reflection_metadata.php 与 compiled_factories.php
 *
 * 生成文件：generated/reflection_metadata.php, generated/compiled_factories.php
 */
class Compile extends CommandAbstract
{
    /** 魔术方法名，用于静态类检测 */
    private const MAGIC_METHODS = ['__construct', '__destruct', '__clone', '__wakeup', '__sleep'];

    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note(__('开始预编译反射元数据与编译型工厂...'));

        $verbose = isset($args['v']) || isset($args['verbose']) || isset($data['v']) || isset($data['verbose']);

        $env = Env::getInstance();
        $modules = $env->getActiveModules();

        if (empty($modules)) {
            $this->printer->warning(__('没有找到活跃的模块'));
            return;
        }

        // 正则扫描：框架内使用 ObjectManager 的类（热点类统计）
        $hotClasses = $this->scanObjectManagerUsage($modules);
        $hotCount = \count($hotClasses);

        $metadata = [];
        $classCount = 0;
        $errorCount = 0;
        /** @var array<string, array{className: string, constructor: array|null, useGetInstance: bool, isStaticClass: bool}> */
        $factoryCandidates = [];
        /** @var array<string, array{candidates_hash: string, safe: list<string>}> */
        $safeClassesCache = ['modules' => []];
        $cacheFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'reflection_safe_classes.php';
        if (\is_file($cacheFile)) {
            $loaded = @(include $cacheFile);
            if (\is_array($loaded) && isset($loaded['modules']) && \is_array($loaded['modules'])) {
                $safeClassesCache['modules'] = $loaded['modules'];
            }
        }
        $seenModuleSignatures = [];

        foreach ($modules as $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !\is_dir($basePath)) {
                continue;
            }

            $phpFiles = $this->scanPhpFiles($basePath);

            // 第一步：收集候选类名（通过 token 分析，不加载文件）
            $candidates = [];
            foreach ($phpFiles as $phpFile) {
                $className = $this->resolveClassName($phpFile, $basePath, $module);
                // 优先用 token 分析提取真实类名（处理文件名≠类名的情况）
                $actualClassName = $this->extractClassNameFromFile($phpFile);
                if ($actualClassName !== null) {
                    $className = $actualClassName;
                } elseif ($className === null) {
                    continue;
                }
                $candidates[$className] = $phpFile;
            }

            $candidateKeys = \array_keys($candidates);
            \sort($candidateKeys);
            $moduleSignature = $this->computeModuleSignature($phpFiles);
            $candidatesHash = \hash('sha256', \implode(',', $candidateKeys));
            $seenModuleSignatures[$moduleSignature] = true;

            // 第二步：在子进程中批量验证哪些类可以安全加载（或从缓存复用）
            $cached = $safeClassesCache['modules'][$moduleSignature] ?? null;
            if ($cached !== null && ($cached['candidates_hash'] ?? '') === $candidatesHash) {
                $safeClasses = \array_fill_keys($cached['safe'], true);
            } else {
                $safeClasses = $this->verifySafeClasses($candidateKeys, $verbose);
                $safeClassesCache['modules'][$moduleSignature] = [
                    'candidates_hash' => $candidatesHash,
                    'safe' => \array_keys($safeClasses),
                ];
            }

            foreach ($candidates as $className => $phpFile) {
                // 跳过子进程验证失败的类
                if (!isset($safeClasses[$className])) {
                    continue;
                }

                try {
                    // 预检查：如果类已经加载，直接使用
                    if (!\class_exists($className, false) && !\interface_exists($className, false) && !\trait_exists($className, false)) {
                        $realFile = \realpath($phpFile);
                        if ($realFile !== false && \in_array($realFile, \get_included_files(), true)) {
                            continue;
                        }
                        if (!\class_exists($className, true)) {
                            continue;
                        }
                    }

                    $refClass = new \ReflectionClass($className);

                    if ($refClass->isInterface() || $refClass->isAbstract() || $refClass->isTrait()) {
                        continue;
                    }

                    $isStaticClass = $this->isStaticClass($refClass);
                    $constructor = $refClass->getConstructor();
                    $hasGetInstance = $refClass->hasMethod('getInstance');
                    $getInstanceMethod = $hasGetInstance ? $refClass->getMethod('getInstance') : null;
                    $getInstanceStatic = $getInstanceMethod !== null && $getInstanceMethod->isStatic();
                    // 仅当 getInstance() 无必需参数时才可编译为 ::getInstance()（否则会传 null 导致类型错误）
                    $getInstanceNoRequiredParams = $getInstanceStatic && $getInstanceMethod !== null
                        && $this->methodHasNoRequiredParameters($getInstanceMethod);
                    $constructorIsPublic = $constructor !== null && $constructor->isPublic();
                    $constructorIsPrivate = $constructor !== null && $constructor->isPrivate();

                    $paramMetadata = [];
                    $canGenerateFactory = true;
                    if ($constructor !== null) {
                        $params = $constructor->getParameters();
                        foreach ($params as $key => $param) {
                            $paramMeta = [
                                'index' => $key,
                                'name' => $param->getName(),
                                'typeName' => null,
                                'hasDefault' => false,
                                'defaultValue' => null,
                                'isClass' => false,
                            ];

                            if ($param->getType()) {
                                $type = $param->getType();
                                $typeName = '';

                                if ($type instanceof \ReflectionUnionType) {
                                    $types = $type->getTypes();
                                    foreach ($types as $unionType) {
                                        $unionTypeName = $unionType->getName();
                                        if ($unionTypeName !== 'null' && (\class_exists($unionTypeName, true) || \interface_exists($unionTypeName, true))) {
                                            $typeName = $unionTypeName;
                                            $paramMeta['isClass'] = true;
                                            break;
                                        }
                                    }
                                    if (empty($typeName) && !empty($types)) {
                                        foreach ($types as $unionType) {
                                            if ($unionType->getName() !== 'null') {
                                                $typeName = $unionType->getName();
                                                break;
                                            }
                                        }
                                    }
                                } elseif ($type instanceof \ReflectionNamedType) {
                                    $typeName = $type->getName();
                                    if (\class_exists($typeName, true) || \interface_exists($typeName, true)) {
                                        $paramMeta['isClass'] = true;
                                    }
                                }

                                $paramMeta['typeName'] = $typeName;
                            }

                            try {
                                if ($param->isDefaultValueAvailable()) {
                                    $paramMeta['hasDefault'] = true;
                                    $defaultValue = $param->getDefaultValue();
                                    if (\is_scalar($defaultValue) || \is_array($defaultValue) || $defaultValue === null) {
                                        $paramMeta['defaultValue'] = $defaultValue;
                                    }
                                }
                            } catch (\Exception $e) {
                                // ignore
                            }

                            $paramMetadata[] = $paramMeta;
                            // 可编译条件：类类型或具默认值，否则无法无参调用
                            if (!$paramMeta['isClass'] && !$paramMeta['hasDefault']) {
                                $canGenerateFactory = false;
                            }
                        }
                    }

                    if (!empty($paramMetadata)) {
                        $cacheKey = $className . '::__construct';
                        $metadata[$cacheKey] = [
                            'className' => $className,
                            'methodName' => '__construct',
                            'params' => $paramMetadata,
                        ];
                        $classCount++;
                    }

                    // 编译型工厂候选：非静态类，且（无参 / 全 DI 或默认 / 私有构造 + 无参 getInstance）
                    $useGetInstance = $constructorIsPrivate && $hasGetInstance && $getInstanceStatic && $getInstanceNoRequiredParams;
                    if ($isStaticClass && !$useGetInstance) {
                        $canGenerateFactory = false;
                    }
                    if ($canGenerateFactory && !$isStaticClass) {
                        $factoryCandidates[$className] = [
                            'className' => $className,
                            'constructor' => empty($paramMetadata) ? null : [
                                'params' => $paramMetadata,
                                'isPublic' => $constructorIsPublic,
                            ],
                            'useGetInstance' => $useGetInstance,
                            'isStaticClass' => $isStaticClass,
                        ];
                    }

                    if ($verbose && !empty($paramMetadata)) {
                        $this->printer->print("  [OK] {$className} (" . \count($paramMetadata) . " params)");
                    }
                } catch (\Throwable $e) {
                    $errorCount++;
                    if ($verbose) {
                        $this->printer->warning("  [SKIP] {$className}: {$e->getMessage()}");
                    }
                }
            }
        }

        // 保存安全类缓存（仅保留本次运行涉及的模块签名）
        $safeClassesCache['modules'] = \array_intersect_key(
            $safeClassesCache['modules'] ?? [],
            $seenModuleSignatures
        );
        $safeClassesCache['generated_at'] = \date('Y-m-d H:i:s');
        $generatedDir = BP . 'generated';
        if (!\is_dir($generatedDir)) {
            \mkdir($generatedDir, 0755, true);
        }

        $outputFile = $generatedDir . DIRECTORY_SEPARATOR . 'reflection_metadata.php';
        $metadataContent = "<?php\n\n";
        $metadataContent .= "/**\n";
        $metadataContent .= " * 预编译反射元数据 - 自动生成，请勿手动编辑\n";
        $metadataContent .= " * \n";
        $metadataContent .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $metadataContent .= " * 类数量: {$classCount}\n";
        $metadataContent .= " * \n";
        $metadataContent .= " * 用途：避免 FPM/WLS 模式下每次请求都执行反射操作\n";
        $metadataContent .= " * 重新生成：php bin/w reflection:compile\n";
        $metadataContent .= " */\n\n";
        $metadataContent .= "return " . var_export($metadata, true) . ";\n";

        \file_put_contents($outputFile, $metadataContent);

        $safeCacheContent = "<?php\n/**\n * 安全类验证缓存 - 自动生成，请勿手动编辑\n * 生成时间: " . $safeClassesCache['generated_at'] . "\n * 用途：reflection:compile 复用验证结果，减少子进程次数\n */\nreturn " . \var_export($safeClassesCache, true) . ";\n";
        \file_put_contents($cacheFile, $safeCacheContent);

        $factoryCount = $this->generateCompiledFactories($factoryCandidates, $generatedDir);

        $this->printer->success(__('反射元数据与编译型工厂预编译完成！'));
        $this->printer->note(__('  反射元数据类数: %{1}', [$classCount]));
        $this->printer->note(__('  热点类数（正则发现）: %{1}', [$hotCount]));
        $this->printer->note(__('  编译工厂数: %{1}', [$factoryCount]));
        if ($errorCount > 0) {
            $this->printer->warning(__('  跳过类数: %{1}（使用 -v 查看详情）', [$errorCount]));
        }
        $this->printer->note(__('  输出文件: %{1}', [$outputFile]));
        $this->printer->note(__('  编译工厂: %{1}', [$generatedDir . DIRECTORY_SEPARATOR . 'compiled_factories.php']));
    }

    /**
     * 扫描框架内 ObjectManager::getInstance(Foo::class) / make(Bar::class) 的静态类名（热点类）
     */
    private function scanObjectManagerUsage(array $modules): array
    {
        $hotClasses = [];
        $pattern = '/ObjectManager::(?:getInstance|make)\s*\(\s*(\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::class/';

        foreach ($modules as $module) {
            $basePath = $module['base_path'] ?? '';
            if (empty($basePath) || !\is_dir($basePath)) {
                continue;
            }
            $phpFiles = $this->scanPhpFilesForContent($basePath);
            foreach ($phpFiles as $path) {
                $content = @\file_get_contents($path);
                if ($content === false) {
                    continue;
                }
                if (\preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) > 0) {
                    foreach ($matches as $m) {
                        $fqcn = \ltrim($m[1], '\\');
                        $hotClasses[$fqcn] = true;
                    }
                }
            }
        }

        return $hotClasses;
    }

    /**
     * 递归扫描目录下 PHP 文件（含小写开头等，用于正则扫描内容）
     * 无权限目录会被跳过并打日志，不中断扫描。
     */
    private function scanPhpFilesForContent(string $dir): array
    {
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new SafeRecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\UnexpectedValueException $e) {
            w_log_warning(__('Reflection Compile: 无法打开目录 %{1}，已跳过。原因：%{2}', [$dir, $e->getMessage()]));
            return $files;
        }
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                if (\preg_match('#[\\\\/](test|tests|view|views|i18n|doc|docs)[\\\\/]#i', $path)) {
                    continue;
                }
                $files[] = $path;
            }
        }
        return $files;
    }

    /**
     * 判断方法是否无必需参数（所有参数均有默认值）
     */
    private function methodHasNoRequiredParameters(\ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $param) {
            if (!$param->isDefaultValueAvailable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * 检测是否为静态类（所有公共方法为静态，且无公共构造函数）
     */
    private function isStaticClass(\ReflectionClass $refClass): bool
    {
        $constructor = $refClass->getConstructor();
        if ($constructor !== null && $constructor->isPublic()) {
            return false;
        }
        if ($refClass->hasMethod('getInstance')) {
            $getInstance = $refClass->getMethod('getInstance');
            if ($getInstance->isStatic()) {
                return false;
            }
        }
        $methods = $refClass->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $name = $method->getName();
            if (\in_array($name, self::MAGIC_METHODS, true)) {
                continue;
            }
            if (!$method->isStatic()) {
                return false;
            }
        }
        return true;
    }

    /**
     * 生成 compiled_factories.php：每个类对应一个闭包，运行时零反射实例化
     *
     * @param array<string, array{className: string, constructor: array|null, useGetInstance: bool, isStaticClass: bool}> $factoryCandidates
     */
    private function generateCompiledFactories(array $factoryCandidates, string $generatedDir): int
    {
        $lines = [];
        $lines[] = "<?php";
        $lines[] = "";
        $lines[] = "/**";
        $lines[] = " * 编译型工厂容器 - 自动生成，请勿手动编辑";
        $lines[] = " * 生成时间: " . date('Y-m-d H:i:s');
        $lines[] = " * 用途：ObjectManager 无参 getInstance 时优先使用，避免反射";
        $lines[] = " * 重新生成：php bin/w reflection:compile 或 s:up";
        $lines[] = " */";
        $lines[] = "";
        $lines[] = "use Weline\\Framework\\Manager\\ObjectManager;";
        $lines[] = "";
        $lines[] = "return [";

        $count = 0;
        foreach ($factoryCandidates as $className => $info) {
            $useGetInstance = $info['useGetInstance'];
            $constructor = $info['constructor'] ?? null;

            // 数组 key 在单引号字符串中：反斜杠需要 \\\\ 表示
            $keyEscaped = \str_replace('\\', '\\\\', $className);
            // PHP 代码体中的 FQCN：使用原始反斜杠（\ 前缀即可）
            $fqcnForNew = '\\' . $className;

            if ($useGetInstance) {
                $body = "\\{$className}::getInstance()";
            } elseif ($constructor === null || empty($constructor['params'])) {
                $body = "new {$fqcnForNew}()";
            } else {
                $args = [];
                foreach ($constructor['params'] as $param) {
                    if (($param['isClass'] ?? false) && empty($param['hasDefault'])) {
                        $depClass = $param['typeName'] ?? '';
                        if ($depClass !== '') {
                            // getInstance 参数在单引号字符串中，反斜杠需要转义
                            $depEscaped = \str_replace('\\', '\\\\', $depClass);
                            $args[] = "ObjectManager::getInstance('{$depEscaped}')";
                        } else {
                            $args[] = 'null';
                        }
                    } elseif ($param['hasDefault'] ?? false) {
                        $args[] = $this->exportDefaultValue($param['defaultValue'] ?? null);
                    } else {
                        $typeName = $param['typeName'] ?? null;
                        $args[] = ($typeName === 'array') ? '[]' : 'null';
                    }
                }
                $body = "new {$fqcnForNew}(" . \implode(', ', $args) . ")";
            }

            $lines[] = "    '{$keyEscaped}' => static fn() => {$body},";
            $count++;
        }

        $lines[] = "];";
        $content = \implode("\n", $lines);

        $outputFile = $generatedDir . DIRECTORY_SEPARATOR . 'compiled_factories.php';
        \file_put_contents($outputFile, $content);

        return $count;
    }

    /**
     * 导出默认值为 PHP 字面量字符串
     */
    private function exportDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value)) {
            return "'" . \str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
        }
        if (\is_array($value)) {
            if (empty($value)) {
                return '[]';
            }
            return '[]'; // 复杂数组不序列化，用空数组兜底
        }
        return 'null';
    }
    
    /**
     * 计算模块签名：基于 scanPhpFiles 结果，任意文件增删改会失效
     *
     * @param list<string> $phpFiles
     */
    private function computeModuleSignature(array $phpFiles): string
    {
        $parts = [];
        foreach ($phpFiles as $path) {
            $mtime = @\filemtime($path);
            $parts[] = $path . '|' . ($mtime !== false ? (string) $mtime : '0');
        }
        \sort($parts);
        return \hash('sha256', \implode("\n", $parts));
    }

    /**
     * 递归扫描目录下的 PHP 文件
     */
    private function scanPhpFiles(string $dir): array
    {
        $files = [];
        // 使用 SafeRecursiveDirectoryIterator：遇无权限子目录（如 root 创建的 view/tpl）时跳过，不抛异常
        $iterator = new \RecursiveIteratorIterator(
            new SafeRecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                // 跳过 test、view、i18n、doc、example、Lib、UnitTest、Observer 等目录
                // Observer 类由 EventsManager 动态实例化，不需要预编译 DI 元数据
                if (\preg_match('#[\\\\/](test|tests|view|views|i18n|doc|docs|Console|example|examples|Lib|UnitTest|Observer)[\\\\/]#i', $path)) {
                    continue;
                }
                // 跳过非类文件
                $basename = $file->getBasename('.php');
                if ($basename[0] !== \strtoupper($basename[0])) {
                    continue; // 跳过非大写开头的文件（非类文件）
                }
                $files[] = $path;
            }
        }
        
        return $files;
    }
    
    /** PHP 保留关键字（不能用作类名） */
    private const PHP_RESERVED_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class',
        'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else',
        'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch',
        'endwhile', 'eval', 'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach',
        'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace',
        'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require',
        'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset',
        'use', 'var', 'while', 'xor', 'yield',
    ];

    /**
     * 从文件路径解析类名
     */
    private function resolveClassName(string $filePath, string $basePath, array $module): ?string
    {
        // 获取模块的命名空间前缀
        $namespace = $module['namespace_path'] ?? '';
        if (empty($namespace)) {
            // 从模块名推断命名空间：Vendor_Module -> Vendor\Module
            $moduleName = $module['name'] ?? '';
            $namespace = \str_replace('_', '\\', $moduleName);
        }
        
        // 计算相对路径
        $relativePath = \str_replace($basePath, '', $filePath);
        $relativePath = \str_replace([DIRECTORY_SEPARATOR, '/'], '\\', $relativePath);
        $relativePath = \rtrim($relativePath, '.php');
        // 去掉开头的反斜杠
        $relativePath = \ltrim($relativePath, '\\');
        // 去掉 .php 后缀
        if (\str_ends_with($relativePath, '.php')) {
            $relativePath = \substr($relativePath, 0, -4);
        }
        
        $className = $namespace . '\\' . $relativePath;
        
        // 基本验证
        if (empty($className) || \str_contains($className, ' ')) {
            return null;
        }
        
        // 检查类名最后一段是否为 PHP 保留关键字（如 Extends.php → 类不可能叫 Extends）
        $shortName = \strtolower(\substr($className, \strrpos($className, '\\') + 1));
        if (\in_array($shortName, self::PHP_RESERVED_KEYWORDS, true)) {
            return null;
        }
        
        return $className;
    }

    /**
     * 在子进程中批量验证哪些类可以安全 class_exists（不会触发 fatal error）
     * 
     * 采用"二分法"策略：将所有类分批，每批在子进程中验证。
     * 如果子进程成功（exit 0），该批所有类标记为安全；
     * 如果失败，则递归拆分批次，直至找出有问题的类并跳过。
     *
     * @param list<string> $classNames
     * @param bool $verbose
     * @return array<string, true> 可以安全加载的类名集合
     */
    private function verifySafeClasses(array $classNames, bool $verbose = false): array
    {
        if (empty($classNames)) {
            return [];
        }
        
        $safeClasses = [];
        $phpBin = PHP_BINARY ?: 'php';
        $bootstrap = BP . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
        
        // 分批验证（每批最多 100 个类，平衡子进程开销和隔离粒度）
        $batches = \array_chunk($classNames, 100);
        
        foreach ($batches as $batch) {
            $result = $this->verifyBatch($batch, $phpBin, $bootstrap);
            if ($result === true) {
                // 整批通过
                foreach ($batch as $cls) {
                    $safeClasses[$cls] = true;
                }
            } else {
                // 批次失败，逐个验证（精确隔离问题类）
                foreach ($batch as $cls) {
                    $singleResult = $this->verifyBatch([$cls], $phpBin, $bootstrap);
                    if ($singleResult === true) {
                        $safeClasses[$cls] = true;
                    } elseif ($verbose) {
                        $this->printer->warning("  [UNSAFE] {$cls}");
                    }
                }
            }
        }
        
        return $safeClasses;
    }
    
    /**
     * 在子进程中验证一批类是否可以安全加载
     *
     * @param list<string> $classNames
     * @param string $phpBin
     * @param string $bootstrap
     * @return bool true = 全部安全
     */
    private function verifyBatch(array $classNames, string $phpBin, string $bootstrap): bool
    {
        // 构建临时验证脚本
        $escapedClasses = [];
        foreach ($classNames as $cls) {
            $escapedClasses[] = \addslashes($cls);
        }
        
        // 内联 PHP 脚本：加载 bootstrap 然后逐个 class_exists
        $script = \sprintf(
            'require_once \'%s\'; foreach([%s] as $c){ class_exists($c, true); } echo "OK";',
            \addslashes($bootstrap),
            "'" . \implode("','", $escapedClasses) . "'"
        );
        
        $cmd = \sprintf('"%s" -r "%s" 2>&1', $phpBin, \str_replace('"', '\\"', $script));
        
        $output = [];
        $exitCode = 0;
        @\exec($cmd, $output, $exitCode);
        
        $outputStr = \implode("\n", $output);
        
        // 如果输出包含 "OK" 且 exit code 为 0，则全部安全
        return $exitCode === 0 && \str_contains($outputStr, 'OK');
    }

    /**
     * 通过 token 分析从 PHP 文件中提取第一个具名类/接口/trait 的完全限定名
     * 不实际加载文件，避免 fatal error
     */
    private function extractClassNameFromFile(string $filePath): ?string
    {
        $code = @\file_get_contents($filePath);
        if ($code === false) {
            return null;
        }
        // 只读文件开头 8KB，提高性能（命名空间和类声明通常在文件顶部）
        $code = \substr($code, 0, 8192);
        
        $tokens = @\token_get_all($code);
        if ($tokens === false) {
            return null;
        }
        
        $namespace = '';
        $count = \count($tokens);
        
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            // 提取 namespace
            if ($token[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (\is_array($t) && \in_array($t[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $t[1];
                    } elseif (!\is_array($t) && ($t === ';' || $t === '{')) {
                        break;
                    }
                }
                $namespace = \trim($ns);
            }
            // 提取第一个 class / interface / trait 名称
            if (\in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                // 跳过 anonymous classes ("new class {")
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (\is_array($t) && $t[0] === T_WHITESPACE) {
                        continue;
                    }
                    if (\is_array($t) && $t[0] === T_STRING) {
                        $shortName = $t[1];
                        return $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                    }
                    break; // 匿名类或语法异常
                }
            }
        }
        
        return null;
    }

    public function tip(): string
    {
        return __('预编译反射元数据（加速 ObjectManager 依赖注入）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'reflection:compile',
            __('预编译所有模块类的构造函数参数元数据，生成 generated/reflection_metadata.php 文件。'
                . '避免 FPM 模式下每次请求都执行反射操作，显著提升性能。'),
            [
                '-v, --verbose' => __('显示详细编译信息'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('基本用法') => 'php bin/w reflection:compile',
                __('详细模式') => 'php bin/w reflection:compile -v',
            ]
        );
    }
}
