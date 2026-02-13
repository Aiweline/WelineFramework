<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Register;

use Weline\Framework\App;
use Weline\Framework\App\Exception;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Dependency\Sort;

class Register implements RegisterDataInterface
{
    /** 阶段：仅执行 MODULE 类型注册（用于升级时先装模块再刷新依赖再注册其余） */
    public const PHASE_MODULE_ONLY = 'module_only';
    /** 阶段：执行所有类型注册（默认） */
    public const PHASE_ALL = 'all';

    /** 当前注册阶段，由 Upgrade 在两阶段流程中设置 */
    private static string $registerPhase = self::PHASE_ALL;
    /** 在 MODULE_ONLY 阶段被延后的非 MODULE 注册项（待依赖刷新后执行） */
    private static array $pendingRegistrations = [];

    private array $original_module_data = [];

    /**
     * @DESC         |注册
     *
     * @param string $type 注册类型
     * @param string $module_name 模组名
     * @param array|string $param 参数
     * @param string $version 版本
     * @param string $description 描述
     * @param array $dependencies 依赖定义
     *
     * @return mixed
     * @throws App\Exception
     * @throws \ReflectionException
     */
    public static function register(string $type, string $module_name, array|string $param, string $version = '', string $description = '', array $dependencies = []): mixed
    {
        if (self::$registerPhase === self::PHASE_MODULE_ONLY && $type !== self::MODULE) {
            self::$pendingRegistrations[] = func_get_args();
            return null;
        }
        $install_params = func_get_args();
        switch ($type) {
            // 模块安装
            case self::MODULE:
                // 如果 $param 是字符串路径
                if (is_string($param)) {
                    $appPathArray = explode(DS, $param);
                    $module_name_dir = array_pop($appPathArray);
                    $vendor_dir = array_pop($appPathArray);
                    // 安装数据
                    $install_params = [$type, $module_name, ['dir_path' => $vendor_dir . DS . $module_name_dir . DS, 'base_path' => $param . DS, 'module_name' => $module_name], $version, $description, $dependencies];
                } else {
                    // 保留原始传参（如果传入的是数组形式）
                    $install_params = [$type, $module_name, $param, $version, $description, $dependencies];
                }
                break;
            // 路由注册 或 其他类型
            case self::ROUTER:
            default:
        }
        /*
         * 采用观察者模式 是的其余类型的安装可自定义注册
         */
        /**@var DataObject $installerPathData */
        $installerPathData = ObjectManager::getInstance(DataObject::class);
        $installerPathData
            ->setData('installer', self::NAMESPACE . ucfirst($type) . '\Handle')
            ->setData('register_arguments', $install_params);
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $eventsManager->dispatch('Weline_Framework_Register::register_installer', $installerPathData);
        $installer_class = $installerPathData->getData('installer');
        /**@var RegisterInterface $installer */
        $installer = ObjectManager::getInstance($installer_class);
        if ($installer instanceof RegisterInterface) {
            $register_arguments = $installerPathData->getData('register_arguments');
            return $installer->register(...$register_arguments);
        } else {
            throw new ConsoleException($installer_class . __('安装器必须继承：') . RegisterInterface::class);
        }
    }

    /**
     * 设置注册阶段（仅 Upgrade 两阶段流程使用）
     * PHASE_MODULE_ONLY：只执行 MODULE，其余入队；切换到此阶段时会清空待执行队列。
     */
    public static function setRegisterPhase(string $phase): void
    {
        self::$registerPhase = $phase;
        if ($phase === self::PHASE_MODULE_ONLY) {
            self::$pendingRegistrations = [];
        }
    }

    /**
     * 执行所有延后的注册项（依赖刷新后由 Upgrade 调用）
     * 对 THEME 类型按 parent 依赖排序后再执行，保证父主题先于子主题安装。
     */
    public static function runPendingRegistrations(): void
    {
        self::$registerPhase = self::PHASE_ALL;
        $ordered = self::sortPendingRegistrationsByThemeParent(self::$pendingRegistrations);
        foreach ($ordered as $args) {
            self::register(...$args);
        }
        self::$pendingRegistrations = [];
    }

    /**
     * 对待执行队列按主题父依赖排序：THEME 类型根据 param['parent'] 拓扑排序，其余类型保持原序。
     * 父主题名 'default' 与框架默认主题名 'Default 默认主题' 视为等价。
     *
     * @param array $pending 延后注册项列表，每项为 register(...) 的 func_get_args()
     * @return array 排序后的列表（非 THEME 原序 + THEME 按父依赖拓扑序）
     */
    private static function sortPendingRegistrationsByThemeParent(array $pending): array
    {
        $themes = [];
        $nonThemes = [];
        foreach ($pending as $args) {
            $type = $args[0] ?? '';
            if ($type === self::THEME && is_array($args[2] ?? null)) {
                $themes[] = $args;
            } else {
                $nonThemes[] = $args;
            }
        }
        if (empty($themes)) {
            return $pending;
        }
        $sortedThemes = self::topoSortThemesByParent($themes);
        return array_merge($nonThemes, $sortedThemes);
    }

    /**
     * 对 THEME 注册项按 parent 做拓扑排序（父主题先于子主题）
     *
     * @param array $themes 每项为 [type, module_name, param, version, description, dependencies]
     * @return array 拓扑序
     */
    private static function topoSortThemesByParent(array $themes): array
    {
        $n = count($themes);
        $nameToIndex = []; // 主题 name => 在 $themes 中的下标（第一个提供该 name 的）
        $defaultThemeIndex = null; // 提供 "Default 默认主题" 的下标

        for ($i = 0; $i < $n; $i++) {
            $param = $themes[$i][2] ?? [];
            $name = isset($param['name']) ? trim((string)$param['name']) : '';
            if ($name !== '') {
                if (!isset($nameToIndex[$name])) {
                    $nameToIndex[$name] = $i;
                }
                if ($name === 'Default 默认主题') {
                    $defaultThemeIndex = $i;
                }
            }
        }
        if ($defaultThemeIndex !== null && !isset($nameToIndex['default'])) {
            $nameToIndex['default'] = $defaultThemeIndex;
        }

        $deps = array_fill(0, $n, []); // $deps[i] = 必须在 i 之前执行的下标列表
        for ($i = 0; $i < $n; $i++) {
            $param = $themes[$i][2] ?? [];
            $parent = isset($param['parent']) ? trim((string)$param['parent']) : '';
            if ($parent === '') {
                continue;
            }
            $providerIndex = $nameToIndex[$parent] ?? null;
            if ($providerIndex !== null && $providerIndex !== $i) {
                $deps[$i][] = $providerIndex;
            }
        }

        $inDegree = array_fill(0, $n, 0);
        foreach ($deps as $list) {
            foreach ($list as $j) {
                $inDegree[$j]++;
            }
        }
        $queue = [];
        for ($i = 0; $i < $n; $i++) {
            if ($inDegree[$i] === 0) {
                $queue[] = $i;
            }
        }
        $order = [];
        while (!empty($queue)) {
            $i = array_shift($queue);
            $order[] = $themes[$i];
            foreach ($deps[$i] as $j) {
                $inDegree[$j]--;
                if ($inDegree[$j] === 0) {
                    $queue[] = $j;
                }
            }
        }
        if (count($order) < $n) {
            return $themes;
        }
        return $order;
    }

    /**
     * 恢复默认阶段并清空待执行队列
     */
    public static function clearRegisterPhase(): void
    {
        self::$registerPhase = self::PHASE_ALL;
        self::$pendingRegistrations = [];
    }

    /**
     * 从 register.php 文件反解析 Register::register(...) 的参数（完整版本）
     *
     * @param string $register_file
     * @return array
     * @throws Exception
     * @throws \ReflectionException
     */
    static public function parserRegisterFunctionParams(string $register_file)
    {
        if (!is_file($register_file)) {
            throw new Exception($register_file . __('注册文件不存在！'));
        }

        $registerCalls = self::getStaticFunctions($register_file);

        // 尝试查找 Register::register 调用（支持不同的类名格式）
        $registerArgs = [];
        $foundRegisterCall = false;
        foreach ($registerCalls as $functionName => $params) {
            // 检查是否是 register 方法调用（类名可能不同，但方法名必须是 register）
            if (preg_match('/::register$/i', $functionName)) {
                $foundRegisterCall = true;
                $registerArgs = $params;
                break;
            }
        }
        
        // 如果找到了 register 调用但参数为空，可能是解析问题，尝试重新解析
        if ($foundRegisterCall && empty($registerArgs)) {
            // 重新尝试解析整个文件
            $registerArgs = self::parseRegisterCallDirectly($register_file);
        }

        // 如果没找到，尝试获取第一个调用（容错）
        if (empty($registerArgs) && !empty($registerCalls)) {
            $registerArgs = array_shift($registerCalls) ?? [];
        }

        // 如果仍然为空，说明没有找到任何调用或解析失败
        // 对于空文件或缺少调用的文件，返回空数组而不是抛出异常
        // 这样调用方可以优雅地处理（跳过该文件）
        if (empty($registerArgs)) {
            // 检查文件是否为空
            $fileContent = trim(file_get_contents($register_file));
            if (empty($fileContent)) {
                // 文件为空，返回空数组，调用方会跳过该文件
                return [];
            }
            
            // 文件不为空但没有找到 Register::register 调用
            // 记录警告但不中断流程
            $foundCalls = array_keys($registerCalls);
            $warningMsg = $register_file . __(' 文件中：Register::register(...)  函数参数不能为空');
            if (!empty($foundCalls)) {
                $warningMsg .= __('。找到的调用：') . implode(', ', $foundCalls);
            } else {
                $warningMsg .= __('。未找到任何 Register::register 调用。');
            }
            // 使用框架的日志系统记录警告
            \Weline\Framework\App\Env::log_warning('register_parse.log', $warningMsg);
            // 返回空数组，让调用方跳过该文件
            return [];
        }

        // 反解析参数名
        $registerRef = new \ReflectionClass(\Weline\Framework\Register\Register::class);
        $method = $registerRef->getMethod('register');
        $finalArgs = [];

        foreach ($method->getParameters() as $key => $argument) {
            $paramValue = $registerArgs[$key] ?? null;
            $paramType = $argument->getType();
            $paramName = $argument->getName();

            // 解析常量、__DIR__、字面量字符串
            $paramValue = self::resolveValueToken($paramValue, $register_file);

            // 如果参数类型是数组（包括联合类型中的数组），且值是字符串形式的数组字面量，需要解析
            if ($paramType && self::isArrayType($paramType)) {
                if (is_string($paramValue)) {
                    $trimmed = trim($paramValue);
                    if ($trimmed !== '' && ($trimmed[0] === '[' || stripos($trimmed, 'array') === 0)) {
                        // 尝试解析数组字面量字符串
                        $paramValue = self::parseArrayLiteral($paramValue);
                    } else {
                        // 如果是一个非数组的字符串（常量或路径），包装为单元素数组
                        $paramValue = $paramValue === '' ? [] : [$paramValue];
                    }
                } elseif ($paramValue === null) {
                    $paramValue = [];
                }
            } else {
                // 非数组类型：如果是字符串并带引号，去掉外层引号
                if (is_string($paramValue)) {
                    $paramValue = trim($paramValue, "\"'");
                }
            }

            $finalArgs[$paramName] = $paramValue;
        }

        return $finalArgs;
    }

    /**
     * 解析 __DIR__ / 常量 / 字面量 等 token 值为最终值（尽量解析）
     *
     * @param mixed $value
     * @param string $contextFile 用来计算 __DIR__ 的上下文文件
     * @return mixed
     */
    private static function resolveValueToken($value, string $contextFile)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        // 字面量字符串 'abc' 或 "abc"
        if (($trimmed[0] === "'" && substr($trimmed, -1) === "'") || ($trimmed[0] === '"' && substr($trimmed, -1) === '"')) {
            return substr($trimmed, 1, -1);
        }

        // 常用魔术常量 __DIR__ 、__FILE__
        if (strtoupper($trimmed) === '__DIR__') {
            // 返回该 register 文件的目录
            return dirname($contextFile);
        }
        if (strtoupper($trimmed) === '__FILE__') {
            return $contextFile;
        }

        // 如果是常量名，尝试解析
        if (defined($trimmed)) {
            return constant($trimmed);
        }

        // 可能是表达式、变量或常量访问（例如 Register::MODULE），直接返回原始 token（保持字符串）
        return $trimmed;
    }

    /**
     * 检查反射类型是否包含数组类型
     *
     * @param \ReflectionType $type
     * @return bool
     */
    private static function isArrayType(\ReflectionType $type): bool
    {
        // 处理命名类型（单一类型）
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName() === 'array';
        }

        // 处理联合类型（PHP 8.0+）
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof \ReflectionNamedType && $unionType->getName() === 'array') {
                    return true;
                }
            }
        }

        // 处理交集类型（PHP 8.1+）
        if ($type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $intersectionType) {
                if ($intersectionType instanceof \ReflectionNamedType && $intersectionType->getName() === 'array') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 解析数组字面量字符串为实际数组
     *
     * 支持格式：
     *  - ['a', 'b']
     *  - array('a', 'b')
     *  - 支持嵌套、字符串带引号、忽略转义等
     *
     * @param string $arrayString
     * @return array
     */
    private static function parseArrayLiteral(string $arrayString): array
    {
        $arrayString = trim($arrayString);

        // 空数组
        if ($arrayString === '[]' || $arrayString === 'array()') {
            return [];
        }

        // array(...) => [ ... ]
        if (preg_match('/^array\s*\((.*)\)$/s', $arrayString, $matches)) {
            $arrayString = '[' . $matches[1] . ']';
        }

        // 必须是 [ ... ]
        if (strlen($arrayString) < 2 || $arrayString[0] !== '[' || substr($arrayString, -1) !== ']') {
            return [];
        }

        $content = trim(substr($arrayString, 1, -1));
        if ($content === '') return [];

        // 解析元素（支持嵌套、字符串）
        $elements = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = '';
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];

            // 字符串开闭
            if (($ch === "'" || $ch === '"') && ($i === 0 || $content[$i - 1] !== '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $ch;
                } elseif ($ch === $stringChar) {
                    $inString = false;
                    $stringChar = '';
                }
                $current .= $ch;
                continue;
            }

            if ($inString) {
                $current .= $ch;
                continue;
            }

            // 嵌套数组
            if ($ch === '[') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ']') {
                $depth--;
                $current .= $ch;
                continue;
            }

            // 顶层逗号分隔
            if ($ch === ',' && $depth === 0) {
                $element = trim($current);
                if ($element !== '') {
                    // 处理键=>值的情况（取 value）
                    if (strpos($element, '=>') !== false) {
                        $parts = explode('=>', $element, 2);
                        $element = trim($parts[1]);
                    }
                    // 去引号
                    if (($element[0] === "'" && substr($element, -1) === "'") || ($element[0] === '"' && substr($element, -1) === '"')) {
                        $element = substr($element, 1, -1);
                    }
                    $elements[] = $element;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        // 最后一个元素
        $element = trim($current);
        if ($element !== '') {
            if (strpos($element, '=>') !== false) {
                $parts = explode('=>', $element, 2);
                $element = trim($parts[1]);
            }
            if (($element[0] === "'" && substr($element, -1) === "'") || ($element[0] === '"' && substr($element, -1) === '"')) {
                $element = substr($element, 1, -1);
            }
            $elements[] = $element;
        }

        return $elements;
    }

    /**
     * @DESC          # 获取所有注册文件
     *
     * @return array
     */
    static function scanRegisters(): array
    {
        # 扫描app模块
        $app_modules = glob(APP_CODE_PATH . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT) ?: [];
        # 扫描vendor模块
        $vendor_modules = glob(VENDOR_PATH . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT) ?: [];
        # 扫描主题设计目录 (app/design/Vendor/ThemeName/register.php)
        $theme_registers = glob(App\Env::path_CODE_DESIGN . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT) ?: [];
        # 扫描语言包目录 (app/i18n/Vendor/LocaleCode/register.php)
        $i18n_registers = glob(App\Env::path_LANGUAGE_PACK . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT) ?: [];
        # 合并所有注册文件
        return array_merge($vendor_modules, $app_modules, $theme_registers, $i18n_registers);
    }

    /**
     * @DESC          # 解析注册文件中的注册函数
     *
     * @param $register_file
     * @return array
     */
    static function getStaticFunctions($register_file)
    {
        $content = file_get_contents($register_file);
        $tokens = token_get_all($content);
        $calls = array();
        $tokenCount = count($tokens);

        for ($key = 0; $key < $tokenCount; $key++) {
            $token = $tokens[$key];

            // 查找 T_DOUBLE_COLON (::)
            if (is_array($token) && $token[0] == T_DOUBLE_COLON) {
                // 获取类名（前面的token）
                $className = '';
                $prevKey = $key - 1;
                while ($prevKey >= 0) {
                    $prevToken = $tokens[$prevKey];
                    if (is_array($prevToken)) {
                        // 跳过空白字符和注释
                        if ($prevToken[0] == T_WHITESPACE || $prevToken[0] == T_COMMENT || $prevToken[0] == T_DOC_COMMENT) {
                            $prevKey--;
                            continue;
                        }
                        if ($prevToken[0] == T_STRING || $prevToken[0] == T_NS_SEPARATOR) {
                            $className = $prevToken[1] . $className;
                            $prevKey--;
                        } else {
                            break;
                        }
                    } else {
                        // 跳过空白字符
                        if (trim($prevToken) === '') {
                            $prevKey--;
                            continue;
                        }
                        break;
                    }
                }

                // 获取方法名（后面的token）
                $nextToken = $key < $tokenCount - 1 ? $tokens[$key + 1] : null;

                // 查找函数名 "register"（不区分大小写）
                if ($nextToken && is_array($nextToken) && $nextToken[0] == T_STRING && strtolower($nextToken[1]) === 'register') {
                    // 查找左括号（跳过可能的空白字符）
                    $parenPos = $key + 2;
                    while ($parenPos < $tokenCount) {
                        $parenToken = $tokens[$parenPos];
                        $parenValue = is_array($parenToken) ? $parenToken[1] : $parenToken;

                        // 跳过空白字符
                        if (is_array($parenToken) && $parenToken[0] == T_WHITESPACE) {
                            $parenPos++;
                            continue;
                        }

                        if ($parenValue === '(') {
                            // 找到函数调用的开始，开始解析参数
                            $params = self::parseFunctionParams($tokens, $parenPos + 1);
                            // 使用类名（可能是短名称如 "Register" 或完整名称）或默认 'Register'
                            $function_name = (!empty(trim($className)) ? trim($className) : 'Register') . '::register';
                            $calls[$function_name] = $params;
                            break;
                        }
                        $parenPos++;
                    }
                }
            }
        }

        return $calls;
    }

    /**
     * 直接解析 register.php 文件中的 Register::register 调用
     * 作为备用方法，当 getStaticFunctions 失败时使用
     * 
     * @param string $register_file
     * @return array
     */
    private static function parseRegisterCallDirectly(string $register_file): array
    {
        $content = file_get_contents($register_file);
        
        // 使用正则表达式直接提取参数
        // 匹配 Register::register(...) 的调用，支持多行
        // 使用平衡括号匹配来正确提取参数内容
        if (preg_match('/Register\s*::\s*register\s*\(\s*/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $startPos = $matches[0][1] + strlen($matches[0][0]);
            
            // 找到匹配的右括号（考虑嵌套）
            $depth = 1;
            $paramsStr = '';
            $len = strlen($content);
            $inString = false;
            $stringChar = '';
            
            for ($i = $startPos; $i < $len && $depth > 0; $i++) {
                $ch = $content[$i];
                
                // 处理字符串
                if (($ch === "'" || $ch === '"') && ($i === 0 || $content[$i - 1] !== '\\')) {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $ch;
                    } elseif ($ch === $stringChar) {
                        $inString = false;
                        $stringChar = '';
                    }
                    $paramsStr .= $ch;
                    continue;
                }
                
                if ($inString) {
                    $paramsStr .= $ch;
                    continue;
                }
                
                // 处理括号
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                    $paramsStr .= $ch;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                    if ($depth > 0) {
                        $paramsStr .= $ch;
                    }
                } else {
                    $paramsStr .= $ch;
                }
            }
            
            if (trim($paramsStr) === '') {
                return [];
            }
            
            // 简单分割参数（处理多行情况）
            $params = [];
            $depth = 0;
            $inString = false;
            $stringChar = '';
            $current = '';
            
            for ($i = 0; $i < strlen($paramsStr); $i++) {
                $ch = $paramsStr[$i];
                
                // 处理字符串
                if (($ch === "'" || $ch === '"') && ($i === 0 || $paramsStr[$i - 1] !== '\\')) {
                    if (!$inString) {
                        $inString = true;
                        $stringChar = $ch;
                    } elseif ($ch === $stringChar) {
                        $inString = false;
                        $stringChar = '';
                    }
                    $current .= $ch;
                    continue;
                }
                
                if ($inString) {
                    $current .= $ch;
                    continue;
                }
                
                // 处理括号和数组
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                    $current .= $ch;
                    continue;
                }
                
                if ($ch === ')' || $ch === ']') {
                    $depth--;
                    $current .= $ch;
                    continue;
                }
                
                // 参数分隔符
                if ($ch === ',' && $depth === 0) {
                    $param = trim($current);
                    if ($param !== '') {
                        $params[] = $param;
                    }
                    $current = '';
                    continue;
                }
                
                $current .= $ch;
            }
            
            // 最后一个参数
            $param = trim($current);
            if ($param !== '') {
                $params[] = $param;
            }
            
            return $params;
        }
        
        return [];
    }
    
    /**
     * 解析函数参数
     *
     * @param array $tokens token数组
     * @param int $startPos 开始位置（左括号后的第一个token）
     * @return array 参数数组（每项保留原始token串）
     */
    private static function parseFunctionParams(array $tokens, int $startPos): array
    {
        $params = [];
        $currentParam = '';
        $depth = 0; // 括号深度
        $arrayDepth = 0; // 数组深度
        $tokenCount = count($tokens);

        for ($i = $startPos; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            $tokenValue = is_array($token) ? $token[1] : $token;
            $tokenType = is_array($token) ? $token[0] : null;

            // 处理字符串字面量（PHP tokenizer 会将完整字符串作为一个token）
            if ($tokenType === T_CONSTANT_ENCAPSED_STRING || $tokenType === T_ENCAPSED_AND_WHITESPACE) {
                $currentParam .= $tokenValue;
                continue;
            }

            // 处理括号（用于嵌套函数调用，如 __DIR__() 情况等）
            if ($tokenValue === '(') {
                $depth++;
                $currentParam .= $tokenValue;
                continue;
            }

            if ($tokenValue === ')') {
                if ($depth > 0) {
                    $depth--;
                    $currentParam .= $tokenValue;
                } else {
                    // 函数调用结束，保存最后一个参数
                    if (trim($currentParam) !== '') {
                        $params[] = trim($currentParam);
                    }
                    break;
                }
                continue;
            }

            // 处理数组
            if ($tokenValue === '[') {
                $arrayDepth++;
                $currentParam .= $tokenValue;
                continue;
            }

            if ($tokenValue === ']') {
                $arrayDepth--;
                $currentParam .= $tokenValue;
                continue;
            }

            // 处理参数分隔符（只在最外层括号和数组外部分割）
            if ($tokenValue === ',' && $depth === 0 && $arrayDepth === 0) {
                if (trim($currentParam) !== '') {
                    $params[] = trim($currentParam);
                }
                $currentParam = '';
                continue;
            }

            // 其他token添加到当前参数（包括空白字符，以保持格式）
            $currentParam .= $tokenValue;
        }

        return $params;
    }

    /**
     * @DESC          # 获取原始模组的信息（包含未注册的模组）
     *
     * @return array
     * @throws \Weline\Framework\App\Exception
     */
    static function getOriginModulesData(): array
    {
        $registers = Register::scanRegisters();
        $modules = [];
        foreach ($registers as $register) {
            $registerArgs = Register::parserRegisterFunctionParams($register);
            $module = trim($registerArgs['module_name'] ?? '', '\'\"');
            if ($module === '') {
                // 跳过不合法的 register 文件（或继续处理为占位）
                continue;
            }
            $vendorArr = explode('_', $module);
            $vendor = array_shift($vendorArr);
            $base_path = str_replace(Register::register_file, '', $register);
            $env_file = $base_path . 'etc' . DS . 'env.php';
            $env = [];
            if (file_exists($env_file)) {
                $env = (array)include $env_file;
            }
            $dependencies = $registerArgs['dependencies'] ?? [];
            // 清理依赖项两端的引号
            foreach ($dependencies as &$dependency) {
                $dependency = trim($dependency, '\'"');
            }
            $dependencies = array_unique(array_merge($dependencies, ($env['dependencies'] ?? [])));
            $pathArr = explode(DS, $base_path);
            $path = array_pop($pathArr);
            if (empty($path)) {
                $path = array_pop($pathArr);
            }
            $path = array_pop($pathArr) . DS . $path;
            $modules[$vendor][$module] = [
                'vendor' => $vendor,
                'name' => $module,
                'path' => $path,
                'register' => $register,
                'id' => $module,
                'dependencies' => $dependencies,
                'env_file' => $env_file,
                'base_path' => $base_path,
                'env' => $env
            ];
        }
        // 更新依赖排序
        $dependency_modules = [];
        foreach ($modules as $vendor_modules) {
            foreach ($vendor_modules as $module_name => $module) {
                $dependency_modules[$module_name] = $module;
            }
        }
        /**@var Sort $dependencyModel */
        $dependencyModel = ObjectManager::getInstance(Sort::class);
        $dependencyModules = $dependencyModel->dependenciesSort($dependency_modules);
        return [$modules, $dependencyModules];
    }
}
