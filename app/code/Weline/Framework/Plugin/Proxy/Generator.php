<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Plugin\Proxy;

use mysql_xdevapi\Exception;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Plugin\PluginsManager;
use function PHPUnit\Framework\throwException;

class Generator
{
    private static string $proxyClassTemplate = '<?php
/**
 * 文件信息 Weline框架自动侦听拦截类，请勿随意修改，以免造成系统异常
 * 作者：WelineFramework                       【Aiweline/邹万才】
 * 网名：WelineFramework框架                    【秋枫雁飞(Aiweline)】
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：WelineFramework框架
 * 日期：${DATE}
 * 时间：${TIME}
 * 描述：此文件源码由WelineFramework框架自动侦听拦截类，请勿随意修改源码，以免造成系统异常！
 */
namespace ${namespace};

class ${className} extends ${targetClass}
{
    // 继承侦听器trait
    use \Weline\Framework\Interception\Interceptor;
${functionList}
}
';

    // 代理类关系:多次加载时减少重复
    private static array $classProxyMap = [];

    public static function createInterceptor(string $class): array
    {
        return self::getProxyInterceptor($class);
    }

    private static function getProxyInterceptor(string $targetClassName): array
    {
        $proxyClassName = self::$classProxyMap[$targetClassName] ?? null;
        if ($proxyClassName !== null) {
            return $proxyClassName;
        }

        $proxyClass = self::genProxyClass($targetClassName);

        //eval();动态加载代码

        self::$classProxyMap[$targetClassName] = $proxyClass;

        return $proxyClass;
    }

    #[\JetBrains\PhpStorm\ArrayShape(['name' => 'string', 'body' => 'string|string[]', 'file' => 'string'])]
    private static function genProxyClass(string $class): array
    {
        try {
            $classRef = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new \Error($e->getMessage(), $e->getCode(), $e);
        }
        if ($classRef->isFinal()) {
            throw new \Error(__('无法动态代理final类:%{1}', [$class]));
        }
        $functionList = [];
        $methods = $classRef->getMethods(\ReflectionMethod::IS_PUBLIC);

        // 仅监听被监听的函数
        /**@var PluginsManager $pluginsManager */
        $pluginsManager = ObjectManager::getInstance(PluginsManager::class);
        $type_plugin = $pluginsManager->getClassPluginInstanceList($class);

        $plugin_listen_type_methods = $type_plugin['listen_methods'] ?? [];
        $plugin_listen_type_methods[] = '__construct';
        // 排除当前类尚未代理的函数
        foreach ($methods as $key => $method) {
            if ($class !== $method->class || !in_array($method->name, $plugin_listen_type_methods, true)) {
                unset($methods[$key]);
            }
        }

        // 创建侦听代理函数
        foreach ($methods as $method) {
            if ($method->isFinal()) {
                throw new \Error('无法动态代理final方法' . $method->name);
            }
            // 获取返回类型签名
            $returnTypeName = '';
            $methodReturnType = $method->getReturnType();
            if ($methodReturnType === null) {
                $methodReturnType = '';
            } else {
                // (string) 同时正确输出 named / nullable(?T) / union(A|B) / intersection(A&B)；
                // ReflectionUnionType / ReflectionIntersectionType **没有 getName()**，
                // 旧写法对 union 返回类型直接 fatal：Call to undefined method ReflectionUnionType::getName()。
                // 且 getName() 会丢掉可空标记（?array → array），使产物与源方法签名不一致。
                $returnTypeName = \Weline\Framework\Plugin\Proxy\Generator::normalizeReturnTypeName(
                    (string) $methodReturnType
                );
                $methodReturnType = ': ' . $returnTypeName;
            }
            // 方法参数
            $args = [];
            $parameters = [];

            foreach ($method->getParameters() as $parameter) {
                // 处理默认值
                $parameter_value = null;
                try {
                    $parameter_value = $parameter->getDefaultValue();
                    $parameter_value = '=' . var_export($parameter_value, true);
                } catch (\Exception $exception) {
                    $parameter_value = '';
                }
                $args[] = self::extractParameterType($parameter) . ' $' . $parameter->getName() . $parameter_value;
                $parameters[] = '$' . $parameter->getName();
            }
            $args_tpl = implode(',' . PHP_EOL . '        ', $args);
            $params_tpl = implode(',' . PHP_EOL . '        ', $parameters);
            if ($method->isStatic()) {
                throw new \Exception(__('静态函数尚不支持代理：%{1}', $method->getFileName()) . '[' . $method->getStartLine() . ']' . $method->getStartLine() .
                    ':' .
                    $method->getEndLine());
            }
            $method_modifier = ($method->isPublic() ? 'public' : ($method->isPrivate() ? 'private' : 'protected'));
            // 方法模板
            // void / never 方法禁止 `return <expr>`（PHP Fatal），必须产出无 return 的调用形态；
            // 其余返回类型（含无返回类型、union、可空、self/static）保持原 `return` 语义。
            $bareReturnType = \strtolower(\ltrim(\ltrim($returnTypeName, '?'), '\\'));
            $needsReturn = !\in_array($bareReturnType, ['void', 'never'], true);
            $pluginCallHead = '
        ${construct_content}
        $pluginInfo = $this->pluginsManager->getPluginInfo($this->subjectType, \'${methodName}\');';
            $func_tpl = $needsReturn
                ? '
    ${func_doc}
    ' . $method_modifier . ' function ${methodName}(
        ${arguments}
    )${returntype}
    {' . $pluginCallHead . '
        if (!$pluginInfo) {
            return parent::${methodName}(${parameters});
        } else {
            return $this->___callPlugins(\'${methodName}\', func_get_args(), $pluginInfo);
        } 
    }'
                : '
    ${func_doc}
    ' . $method_modifier . ' function ${methodName}(
        ${arguments}
    )${returntype}
    {' . $pluginCallHead . '
        if (!$pluginInfo) {
            parent::${methodName}(${parameters});
        } else {
            $this->___callPlugins(\'${methodName}\', func_get_args(), $pluginInfo);
        } 
    }';
            $construct_func_tpl = '
    ${func_doc}
    public function ${methodName}(
        ${arguments}
    )${returntype}
    {
        ${construct_content}
    }';
            $construct_content = '';
            if ('__construct' === $method->name) {
                $construct_content = '
//        $this->__init();
        parent::__construct(' . $params_tpl . ');
                    ';
                $func_tpl = $construct_func_tpl;
            }
            $functionList[$method->name] = '    ' . str_replace(
                    [
                        '${methodName}',
                        '${returntype}',
                        '${arguments}',
                        '${parameters}',
                        '${func_doc}',
                        '${construct_content}',
                    ],
                    [
                        $method->name,
                        $methodReturnType,
                        $args_tpl,
                        $params_tpl,
                        $method->getDocComment(),
                        $construct_content,
                    ],
                    $func_tpl
                );
        }
        // 如果没有初始化函数 自行加上
//        if (! array_key_exists('__construct', $functionList)) {
//            $construct_func_tpl = '
//    public function __construct()
//    {
//        $this->__init();
//    }';
//            $functionList['__construct'] = $construct_func_tpl;
//        }
        $replaceMap = [
            '${DATE}' => date('Y-m-d'),
            '${TIME}' => date('H:m:s'),
            '${namespace}' => $class,
            '${className}' => /*$classRef->getShortName().*/
                'Interceptor',
            '${targetClass}' => '\\' . $class,
            '${functionList}' => join(PHP_EOL, $functionList),
        ];
        $classBody = str_replace(array_keys($replaceMap), array_values($replaceMap), self::$proxyClassTemplate);

        // 写入代理文件
        $class_name = $replaceMap['${namespace}'] . '\\' . $replaceMap['${className}'];
        $interceptor_path = Env::path_framework_generated_code . str_replace('\\', DS, $class_name) . '.php';

//       if('Weline\Framework\Http\Request\Interceptor'===$class_name) {
//           p(array_values($replaceMap) );
//       };
        /**@var \Weline\Framework\System\File\Io\File $file */
        $file = ObjectManager::getInstance(\Weline\Framework\System\File\Io\File::class);
        $file->open($interceptor_path, \Weline\Framework\System\File\Io\File::mode_w)
            ->write($classBody)
            ->close();

        return [
            'name' => '\\' . $class_name,
            'body' => $classBody,
            'file' => $interceptor_path,
        ];
    }

    /**
     * 获取方法参数类型
     *
     * @param \ReflectionParameter $parameter
     *
     * @return null|string
     */
    public static function extractParameterType(
        \ReflectionParameter $parameter
    ): ?string
    {
        /** @var string|null $typeName */
        $typeName = null;
        if ($parameter->hasType()) {
            $refType = $parameter->getType();
            $typeName = $refType instanceof \ReflectionNamedType
                ? $refType->getName()
                : (string) $refType;
            // 类名补命名空间反斜杠必须在加 `?` **之前**：先加 `?` 会让
            // class_exists('?Foo') 恒为 false，类名就丢了绝对命名空间（会解析到错误类）。
            if (\strpos($typeName, '|') === false && \class_exists($typeName)) {
                $typeName = '\\' . $typeName;
            }
            // 可空标记 `?T` 是被拦截方法签名的一部分，必须一并复刻。
            // 只取 getName() 会生成 `string $scope = null`，PHP 8.4 报
            // "Implicitly marking parameter as nullable is deprecated" 且签名与父类不一致。
            // `mixed` 已天然含 null（不能写成 ?mixed）；union 已由 (string) 完整输出。
            if ($refType instanceof \ReflectionNamedType
                && $refType->allowsNull()
                && \strtolower(\ltrim($typeName, '\\')) !== 'mixed'
                && \strtolower(\ltrim($typeName, '\\')) !== 'null'
            ) {
                $typeName = '?' . $typeName;
            }
        }

        return $typeName;
    }

    /**
     * 规范化返回类型名，供拦截器产物使用。
     *
     * 生成物是 namespace 文件（例如 `namespace Weline\SystemConfig\...;`）且不注入 `use`，
     * 所以类型里的类名必须写成绝对形式（前导 `\`），否则会按产物所在 namespace 解析到错误类。
     * 相对类名 `self` / `static` / `parent` 必须保持原样（`\self` 是 SyntaxError）。
     *
     * @param string $typeName 形如 `string` / `?Foo` / `A|B` / `A&B` / `self` / `mixed` / `void`
     */
    public static function normalizeReturnTypeName(string $typeName): string
    {
        $typeName = \trim($typeName);
        if ($typeName === '') {
            return '';
        }
        $nullable = \str_starts_with($typeName, '?');
        $bare = $nullable ? \substr($typeName, 1) : $typeName;
        $lower = \strtolower($bare);
        if (\in_array($lower, ['self', 'static', 'parent', 'mixed', 'void', 'never', 'null', 'false', 'true'], true)) {
            return $typeName;
        }
        // union / intersection：逐段补前导 `\`（仅真实类），系统类型原样保留。
        if (\strpbrk($bare, '|&') !== false) {
            $separator = \str_contains($bare, '&') ? '&' : '|';
            $segments = \preg_split('/[|&]/', $bare) ?: [];
            $segments = \array_map(static function (string $segment): string {
                $segment = \trim($segment);
                if ($segment === '' || \str_starts_with($segment, '\\')) {
                    return $segment;
                }
                $segmentLower = \strtolower($segment);
                if (\in_array($segmentLower, ['null', 'false', 'true', 'self', 'static', 'parent', 'mixed'], true)) {
                    return $segment;
                }

                return \class_exists($segment) || \interface_exists($segment) ? '\\' . $segment : $segment;
            }, $segments);

            return implode($separator, $segments);
        }
        if (\str_starts_with($bare, '\\')) {
            return $typeName;
        }
        if (\class_exists($bare) || \interface_exists($bare)) {
            $bare = '\\' . $bare;
        }

        return ($nullable ? '?' : '') . $bare;
    }

    /**
     * @return array
     */
    public static function getClassProxyMap(): array
    {
        return self::$classProxyMap;
    }

    /**
     * 设置
     *
     * @param array $classProxyMap
     */
    public static function setClassProxyMap(array $classProxyMap): void
    {
        self::$classProxyMap = $classProxyMap;
    }
}
