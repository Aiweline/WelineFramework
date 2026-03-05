<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Cdn\Service;

use Weline\Cdn\Model\ApiRule;
use Weline\Cdn\Model\Domain;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use ReflectionClass;
use ReflectionMethod;

/**
 * CDN规则收集器
 * 
 * 扫描Api和Controller目录，解析@Cdn注释，收集CDN缓存规则
 * 
 * @package Weline_Cdn
 */
class CdnRuleCollector
{
    private ApiRule $apiRuleModel;
    private Domain $domainModel;
    private RuleManager $ruleManager;
    private EventsManager $eventsManager;

    public function __construct(
        ApiRule $apiRuleModel,
        Domain $domainModel,
        RuleManager $ruleManager,
        EventsManager $eventsManager
    ) {
        $this->apiRuleModel = $apiRuleModel;
        $this->domainModel = $domainModel;
        $this->ruleManager = $ruleManager;
        $this->eventsManager = $eventsManager;
    }

    /**
     * 收集所有模块的规则
     * 
     * @return array 收集到的规则数组
     */
    public function collectAll(): array
    {
        $modules = Env::getInstance()->getModuleList();
        $collected = [];
        
        foreach ($modules as $moduleName => $module) {
            if (!($module['status'] ?? false)) {
                continue;
            }
            
            $moduleRules = $this->collectModule($moduleName, $module);
            $collected = array_merge($collected, $moduleRules);
        }
        
        return $collected;
    }

    /**
     * 收集指定模块的规则
     * 
     * @param string $moduleName 模块名称
     * @param array $module 模块信息
     * @return array
     */
    public function collectModule(string $moduleName, array $module): array
    {
        $collected = [];
        $basePath = $module['base_path'] ?? '';
        
        if (empty($basePath)) {
            return $collected;
        }

        // 扫描Api目录
        $apiPath = $basePath . DIRECTORY_SEPARATOR . 'Api';
        if (is_dir($apiPath)) {
            $collected = array_merge($collected, $this->scanDirectory($apiPath, $moduleName, $module));
        }

        // 扫描Controller目录
        $controllerPath = $basePath . DIRECTORY_SEPARATOR . 'Controller';
        if (is_dir($controllerPath)) {
            $collected = array_merge($collected, $this->scanDirectory($controllerPath, $moduleName, $module));
        }

        return $collected;
    }

    /**
     * 扫描目录下的所有PHP文件
     * 
     * @param string $dir 目录路径
     * @param string $moduleName 模块名称
     * @param array $module 模块信息
     * @return array
     */
    private function scanDirectory(string $dir, string $moduleName, array $module): array
    {
        $collected = [];
        $files = $this->getPhpFiles($dir);
        
        foreach ($files as $file) {
            $className = $this->getClassNameFromFile($file, $module);
            if (empty($className) || !class_exists($className, false)) {
                // 尝试加载文件
                if (file_exists($file)) {
                    require_once $file;
                }
                if (!class_exists($className, false)) {
                    continue;
                }
            }
            
            try {
                $reflection = new ReflectionClass($className);
                $classRules = $this->collectClass($reflection, $moduleName, $module);
                $collected = array_merge($collected, $classRules);
            } catch (\Exception $e) {
                // 忽略无法反射的类
                continue;
            }
        }
        
        return $collected;
    }

    /**
     * 收集类中的规则
     * 
     * @param ReflectionClass $reflection 类反射对象
     * @param string $moduleName 模块名称
     * @param array $module 模块信息
     * @return array
     */
    private function collectClass(ReflectionClass $reflection, string $moduleName, array $module): array
    {
        $collected = [];
        $className = $reflection->getName();
        
        foreach ($reflection->getMethods() as $method) {
            // 只处理public方法
            if (!$method->isPublic()) {
                continue;
            }
            
            // 跳过魔术方法
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }
            
            $docComment = $method->getDocComment();
            if (empty($docComment)) {
                continue;
            }
            
            // 解析@Cdn标签
            $rule = $this->parseCdnTag($docComment, $reflection, $method, $module);
            if (!$rule) {
                continue;
            }
            
            // 保存规则到数据库
            $apiRule = $this->saveRule($rule, $className, $method->getName(), $moduleName);
            
            // 如果是实时触发，立即推送
            if (($rule['trigger'] ?? 'cron') === 'realtime') {
                $this->pushRealtimeRule($apiRule);
            }
            
            $collected[] = $rule;
        }
        
        return $collected;
    }

    /**
     * 解析@Cdn标签
     * 
     * @param string $docComment 注释内容
     * @param ReflectionClass $reflection 类反射对象
     * @param ReflectionMethod $method 方法反射对象
     * @param array $module 模块信息
     * @return array|null
     */
    private function parseCdnTag(string $docComment, ReflectionClass $reflection, ReflectionMethod $method, array $module): ?array
    {
        // 匹配 @Cdn 标签
        if (!preg_match('/@Cdn\s+(.+)/s', $docComment, $matches)) {
            return null;
        }
        
        $configString = trim($matches[1]);
        
        // 解析配置参数
        $config = $this->parseCdnConfig($configString);
        
        // 生成路由
        $route = $this->generateRoute($reflection, $method, $module);
        
        // 转换为Cloudflare标准格式
        return $this->convertToCloudflareFormat($config, $route);
    }

    /**
     * 解析@Cdn配置参数
     * 
     * @param string $configString 配置字符串
     * @return array
     */
    private function parseCdnConfig(string $configString): array
    {
        $config = [];
        
        // 解析 cache 参数
        if (preg_match('/cache\s*=\s*([^\s]+)/', $configString, $matches)) {
            $cacheValue = trim($matches[1]);
            if ($cacheValue === 'false') {
                $config['cache'] = false;
            } else {
                $config['cache'] = $cacheValue;
            }
        }
        
        // 解析 status 参数
        if (preg_match('/status\s*=\s*([^\s]+)/', $configString, $matches)) {
            $statusValue = trim($matches[1]);
            $config['status'] = explode(',', $statusValue);
        }
        
        // 解析 trigger 参数
        if (preg_match('/trigger\s*=\s*([^\s]+)/', $configString, $matches)) {
            $config['trigger'] = trim($matches[1]);
        }
        
        // 解析 description 参数
        if (preg_match('/description\s*=\s*"([^"]+)"/', $configString, $matches)) {
            $config['description'] = $matches[1];
        }
        
        // 解析完整表达式（高级用法）
        if (preg_match('/expression\s*=\s*"([^"]+)"/', $configString, $matches)) {
            $config['expression'] = $matches[1];
        }
        
        // 解析完整action（高级用法）
        if (preg_match("/action\s*=\s*'([^']+)'/", $configString, $matches)) {
            $config['action'] = json_decode($matches[1], true);
        }
        
        return $config;
    }

    /**
     * 生成路由路径
     * 
     * @param ReflectionClass $reflection 类反射对象
     * @param ReflectionMethod $method 方法反射对象
     * @param array $module 模块信息
     * @return string
     */
    private function generateRoute(ReflectionClass $reflection, ReflectionMethod $method, array $module): string
    {
        $className = $reflection->getName();
        $methodName = $method->getName();
        
        // 获取模块路由前缀
        $moduleRouter = $module['router'] ?? strtolower($module['name'] ?? '');
        if (empty($moduleRouter)) {
            $moduleRouter = str_replace('_', '-', strtolower($module['name'] ?? ''));
        }
        
        // 判断是Api还是Controller
        $isApi = str_contains($className, '\\Api\\');
        $isController = str_contains($className, '\\Controller\\');
        
        $routeParts = [];
        
        if ($isApi) {
            // API路由：{模块router}/rest/v1/{路径}/{方法}
            $routeParts[] = $moduleRouter;
            $routeParts[] = 'rest';
            $routeParts[] = 'v1';
            
            // 检查是否有Backend或Frontend
            if (str_contains($className, '\\Backend\\')) {
                $routeParts[] = 'backend';
            } elseif (str_contains($className, '\\Frontend\\')) {
                $routeParts[] = 'frontend';
            }
            
            // 提取控制器路径
            $namespaceParts = explode('\\', $className);
            $apiIndex = array_search('Api', $namespaceParts);
            if ($apiIndex !== false) {
                $pathParts = array_slice($namespaceParts, $apiIndex + 1);
                // 移除Backend/Frontend和类名
                $pathParts = array_filter($pathParts, function($part) use ($reflection) {
                    return $part !== 'Backend' && $part !== 'Frontend' && $part !== $reflection->getShortName();
                });
                foreach ($pathParts as $part) {
                    $routeParts[] = strtolower(preg_replace('/([A-Z])/', '-$1', $part));
                }
            }
            
            // 添加类名（转换为kebab-case）
            $controllerName = strtolower(preg_replace('/([A-Z])/', '-$1', $reflection->getShortName()));
            $routeParts[] = trim($controllerName, '-');
            
            // 添加方法名（转换为kebab-case，移除HTTP方法前缀）
            $methodPath = strtolower(preg_replace('/([A-Z])/', '-$1', $methodName));
            $methodPath = preg_replace('/^(get|post|put|delete|patch)-/', '', $methodPath);
            $methodPath = trim($methodPath, '-');
            if ($methodPath && $methodPath !== 'index') {
                $routeParts[] = $methodPath;
            }
            
        } elseif ($isController) {
            // Controller路由：{模块router}/{area}/{路径}/{方法}
            $routeParts[] = $moduleRouter;
            
            // 检查是Backend还是Frontend
            if (str_contains($className, '\\Backend\\')) {
                $routeParts[] = 'backend';
            } elseif (str_contains($className, '\\Frontend\\')) {
                $routeParts[] = 'frontend';
            }
            
            // 提取控制器路径
            $namespaceParts = explode('\\', $className);
            $controllerIndex = array_search('Controller', $namespaceParts);
            if ($controllerIndex !== false) {
                $pathParts = array_slice($namespaceParts, $controllerIndex + 1);
                // 移除Backend/Frontend和类名
                $pathParts = array_filter($pathParts, function($part) use ($reflection) {
                    return $part !== 'Backend' && $part !== 'Frontend' && $part !== $reflection->getShortName();
                });
                foreach ($pathParts as $part) {
                    $routeParts[] = strtolower(preg_replace('/([A-Z])/', '-$1', $part));
                }
            }
            
            // 添加类名（转换为kebab-case）
            $controllerName = strtolower(preg_replace('/([A-Z])/', '-$1', $reflection->getShortName()));
            $routeParts[] = trim($controllerName, '-');
            
            // 添加方法名（转换为kebab-case，移除HTTP方法前缀）
            $methodPath = strtolower(preg_replace('/([A-Z])/', '-$1', $methodName));
            $methodPath = preg_replace('/^(get|post|put|delete|patch)-/', '', $methodPath);
            $methodPath = trim($methodPath, '-');
            if ($methodPath && $methodPath !== 'index') {
                $routeParts[] = $methodPath;
            }
        }
        
        // 清理路由，移除空值和重复的斜杠
        $routeParts = array_filter($routeParts, function($part) {
            return !empty($part);
        });
        
        $route = '/' . implode('/', $routeParts);
        $route = preg_replace('#/+#', '/', $route); // 清理重复斜杠
        
        return $route;
    }

    /**
     * 转换为Cloudflare Cache Rules格式
     * 
     * @param array $config 配置数组
     * @param string $route 路由路径
     * @return array
     */
    private function convertToCloudflareFormat(array $config, string $route): array
    {
        $rule = [
            'expression' => '',
            'action' => [],
            'description' => $config['description'] ?? '',
            'enabled' => true,
            'trigger' => $config['trigger'] ?? 'cron'
        ];
        
        // 如果提供了完整表达式，直接使用
        if (isset($config['expression'])) {
            $rule['expression'] = $config['expression'];
        } else {
            // 生成表达式
            $rule['expression'] = 'http.request.uri.path matches "^' . $route . '"';
        }
        
        // 如果提供了完整action，直接使用
        if (isset($config['action'])) {
            $rule['action'] = $config['action'];
        } else {
            // 构建action
            $rule['action'] = $this->buildCloudflareAction($config);
        }
        
        return $rule;
    }

    /**
     * 构建Cloudflare action
     * 
     * @param array $config 配置数组
     * @return array
     */
    private function buildCloudflareAction(array $config): array
    {
        $cache = $config['cache'] ?? false;
        
        if ($cache === false) {
            return ['cache' => false];
        }
        
        // 解析缓存时间
        $ttl = $this->parseCacheTime($cache);
        if ($ttl === null) {
            return ['cache' => false];
        }
        
        // 解析状态码
        $statusCodes = $config['status'] ?? [200, 301, 308];
        if (is_string($statusCodes)) {
            $statusCodes = explode(',', $statusCodes);
        }
        $statusCodes = array_map('intval', $statusCodes);
        
        return [
            'cache' => [
                'status_code' => $statusCodes,
                'ttl' => $ttl
            ]
        ];
    }

    /**
     * 解析缓存时间
     * 
     * @param string $cacheTime 缓存时间字符串（如：15m, 1h, 1d）
     * @return int|null TTL秒数
     */
    private function parseCacheTime(string $cacheTime): ?int
    {
        if (preg_match('/^(\d+)([mhd])$/', $cacheTime, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            
            switch ($unit) {
                case 'm': // 分钟
                    return $value * 60;
                case 'h': // 小时
                    return $value * 3600;
                case 'd': // 天
                    return $value * 86400;
            }
        }
        
        return null;
    }

    /**
     * 保存规则到数据库
     * 
     * @param array $rule 规则数组
     * @param string $className 类名
     * @param string $methodName 方法名
     * @param string $moduleName 模块名
     * @return ApiRule
     */
    private function saveRule(array $rule, string $className, string $methodName, string $moduleName): ApiRule
    {
        // 查找是否已存在
        $existing = $this->apiRuleModel->clear()
            ->where(ApiRule::schema_fields_MODULE, $moduleName)
            ->where(ApiRule::schema_fields_CLASS, $className)
            ->where(ApiRule::schema_fields_METHOD, $methodName)
            ->find()
            ->fetch();
        
        if ($existing->getId()) {
            // 更新现有规则
            $existing->setData(ApiRule::schema_fields_EXPRESSION, $rule['expression'])
                ->setActionArray($rule['action'])
                ->setData(ApiRule::schema_fields_DESCRIPTION, $rule['description'] ?? '')
                ->setData(ApiRule::schema_fields_ENABLED, $rule['enabled'] ? 1 : 0)
                ->setData(ApiRule::schema_fields_TRIGGER, $rule['trigger'] ?? 'cron')
                ->save();
            
            return $existing;
        } else {
            // 创建新规则
            $apiRule = $this->apiRuleModel->clear();
            $apiRule->setData(ApiRule::schema_fields_MODULE, $moduleName)
                ->setData(ApiRule::schema_fields_CLASS, $className)
                ->setData(ApiRule::schema_fields_METHOD, $methodName)
                ->setData(ApiRule::schema_fields_ROUTE, $this->extractRouteFromExpression($rule['expression']))
                ->setData(ApiRule::schema_fields_EXPRESSION, $rule['expression'])
                ->setActionArray($rule['action'])
                ->setData(ApiRule::schema_fields_DESCRIPTION, $rule['description'] ?? '')
                ->setData(ApiRule::schema_fields_ENABLED, $rule['enabled'] ? 1 : 0)
                ->setData(ApiRule::schema_fields_TRIGGER, $rule['trigger'] ?? 'cron')
                ->save();
            
            return $apiRule;
        }
    }

    /**
     * 从表达式中提取路由
     * 
     * @param string $expression 表达式
     * @return string
     */
    private function extractRouteFromExpression(string $expression): string
    {
        if (preg_match('/matches\s+"\^([^"]+)"/', $expression, $matches)) {
            return $matches[1];
        }
        
        return '';
    }

    /**
     * 推送实时规则
     * 
     * @param ApiRule $apiRule 规则对象
     * @return void
     */
    private function pushRealtimeRule(ApiRule $apiRule): void
    {
        // 获取所有启用的域名（不区分适配器）
        $domains = $this->domainModel->clear()
            ->where(Domain::schema_fields_ENABLED, 1)
            ->select()
            ->fetch();
        
        foreach ($domains as $domain) {
            try {
                // 合并规则（包含这个实时规则）
                $rules = $this->ruleManager->getMergedRules($domain, null); // null表示获取所有规则
                
                // 触发推送事件（所有适配器都会收到）
                $event = new Event([
                    'domain' => $domain,
                    'rules' => $rules, // 通用规则，所有适配器都可以使用
                    'adapter_code' => $domain->getData(Domain::schema_fields_ADAPTER), // 用于适配器过滤
                    'trigger_type' => 'realtime' // 标记为实时触发
                ]);
                
                $this->eventsManager->dispatch('Weline_Cdn::push_rules', $event);
            } catch (\Exception $e) {
                // 记录错误但不中断
                w_log_error("CDN实时规则推送失败 [域名: {$domain->getData(Domain::schema_fields_DOMAIN_NAME)}]: " . $e->getMessage());
            }
        }
    }

    /**
     * 获取目录下的所有PHP文件
     * 
     * @param string $dir 目录路径
     * @return array
     */
    private function getPhpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }

    /**
     * 从文件路径获取类名
     * 
     * @param string $file 文件路径
     * @param array $module 模块信息
     * @return string
     */
    private function getClassNameFromFile(string $file, array $module): string
    {
        $basePath = $module['base_path'] ?? '';
        $namespacePath = $module['namespace_path'] ?? '';
        
        if (empty($basePath) || empty($namespacePath)) {
            return '';
        }
        
        // 计算相对路径
        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file);
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        $relativePath = str_replace('.php', '', $relativePath);
        
        return $namespacePath . '\\' . $relativePath;
    }
}
