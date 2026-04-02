<?php
declare(strict_types=1);

/**
 * Weline Framework - 状态污染检测器
 *
 * 自动检测 WLS 模式下可能导致状态污染的静态变量
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

/**
 * 状态污染检测器
 *
 * 功能：
 * - 扫描已加载的类，检测未注册的静态变量
 * - 在开发模式下自动警告潜在的状态污染
 * - 生成状态污染报告
 */
class StatePollutionDetector
{
    /**
     * 已知的安全静态变量（不需要重置）
     *
     * 这些静态变量是进程级配置或常量，不会导致请求间污染
     */
    private const SAFE_STATIC_PATTERNS = [
        // 单例实例（通过 ObjectManager 管理）
        'instance',
        'instances',

        // 反射缓存（进程级）
        'reflections',
        'reflection',
        'methodParamsMetadata',
        'constructorCache',
        'interfaceCache',
        'classExistsCache',

        // 编译缓存（进程级）
        'precompiledMetadata',
        'compiledFactories',
        'parsedClasses',

        // 配置缓存（进程级，除非配置变更）
        'config',
        'mergedCacheConfig',

        // 常量或枚举
        'const',
        'CONST',
    ];

    /**
     * 已知的危险静态变量模式（必须重置）
     */
    private const DANGEROUS_STATIC_PATTERNS = [
        // 请求级缓存
        'cache',
        'Cache',

        // 用户/会话状态
        'user',
        'session',
        'Session',

        // 递归保护标志
        'inProgress',
        'isCreating',
        'creating',

        // 请求级数据
        'data',
        'items',
        'result',
        'Result',

        // 计数器
        'count',
        'counter',

        // 标志位
        'flag',
        'enabled',
        'injected',
        'hooked',
    ];

    /**
     * 扫描已加载的类，检测潜在的状态污染
     *
     * @param bool $onlyDangerous 是否只检测危险的静态变量
     * @return array 检测结果
     */
    public static function scan(bool $onlyDangerous = true): array
    {
        $results = [];
        $registeredResets = StateManager::getStaticResets();
        $registeredKeys = array_keys($registeredResets);

        // 获取所有已加载的类
        $classes = get_declared_classes();

        foreach ($classes as $class) {
            // 只检查项目代码，跳过 vendor
            if (!str_starts_with($class, 'Weline\\') && !str_starts_with($class, 'GuoLaiRen\\')) {
                continue;
            }

            try {
                $reflection = new \ReflectionClass($class);
                $staticProperties = $reflection->getProperties(\ReflectionProperty::IS_STATIC);

                foreach ($staticProperties as $property) {
                    // 跳过常量
                    if ($property->isPublic() && defined($class . '::' . $property->getName())) {
                        continue;
                    }

                    $propertyName = $property->getName();
                    $key = $class . '::' . $propertyName;

                    // 检查是否已注册
                    if (in_array($key, $registeredKeys, true)) {
                        continue;
                    }

                    // 检查是否是安全的静态变量
                    if (self::isSafeStatic($propertyName)) {
                        continue;
                    }

                    // 检查是否是危险的静态变量
                    $isDangerous = self::isDangerousStatic($propertyName);

                    if ($onlyDangerous && !$isDangerous) {
                        continue;
                    }

                    $results[] = [
                        'class' => $class,
                        'property' => $propertyName,
                        'key' => $key,
                        'dangerous' => $isDangerous,
                        'visibility' => $property->isPrivate() ? 'private' : ($property->isProtected() ? 'protected' : 'public'),
                    ];
                }
            } catch (\Throwable $e) {
                // 跳过无法反射的类
                continue;
            }
        }

        return $results;
    }

    /**
     * 检查是否是安全的静态变量
     */
    private static function isSafeStatic(string $propertyName): bool
    {
        foreach (self::SAFE_STATIC_PATTERNS as $pattern) {
            if (str_contains($propertyName, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 检查是否是危险的静态变量
     */
    private static function isDangerousStatic(string $propertyName): bool
    {
        foreach (self::DANGEROUS_STATIC_PATTERNS as $pattern) {
            if (str_contains($propertyName, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 生成状态污染报告
     *
     * @param bool $onlyDangerous 是否只报告危险的静态变量
     * @return string 报告内容
     */
    public static function generateReport(bool $onlyDangerous = true): string
    {
        $results = self::scan($onlyDangerous);

        if (empty($results)) {
            return "✓ 未检测到" . ($onlyDangerous ? "危险的" : "") . "未注册静态变量\n";
        }

        $report = "⚠️  检测到 " . count($results) . " 个" . ($onlyDangerous ? "危险的" : "") . "未注册静态变量：\n\n";

        foreach ($results as $item) {
            $dangerFlag = $item['dangerous'] ? '🔴 DANGEROUS' : '⚠️  WARNING';
            $report .= "{$dangerFlag} {$item['key']}\n";
            $report .= "  Visibility: {$item['visibility']}\n";
            $report .= "  建议: StateManager::registerStaticReset('{$item['class']}', '{$item['property']}', <defaultValue>);\n\n";
        }

        return $report;
    }

    /**
     * 在开发模式下自动检测并警告
     */
    public static function autoDetect(): void
    {
        if (!function_exists('w_log_warning')) {
            return;
        }

        // 只在开发模式下检测
        if (!\Weline\Framework\App\Env::getInstance()->getConfig('dev')) {
            return;
        }

        $results = self::scan(true);

        if (!empty($results)) {
            $report = self::generateReport(true);
            w_log_warning('[StatePollutionDetector] ' . $report, [], 'wls');
        }
    }
}
