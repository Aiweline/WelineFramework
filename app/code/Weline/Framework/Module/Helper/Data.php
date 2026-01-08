<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有�?
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Register\RegisterDataInterface;
use Weline\Framework\System\File\Io\File;
use Weline\Framework\Helper\AbstractHelper;
use Weline\Framework\Http\RequestInterface;
use Weline\Framework\Module\Handle;
use Weline\Framework\Register\Register;
use Weline\Framework\Register\Router\Data\DataInterface;
use Weline\Framework\System\File\Scan;

class Data extends AbstractHelper
{
    private array $parent_class_arr = [];
    private File $file;
    private Scan $scan;

    public function __construct(
        File $file,
        Scan $scan
    )
    {
        $this->file = $file;
        $this->scan = $scan;
    }

    public function getClassNamespace(\Weline\Framework\System\File\Data\File $controllerFile)
    {
        $namespace_arr = explode('\\', $controllerFile->getNamespace());
        $namespace_arr = array_slice($namespace_arr, 2);
//        foreach ($namespace_arr as &$item) {
//            if (is_int(strpos($item, '-'))) {
//                $item = explode('-', $item);
//                foreach ($item as &$i) {
//                    $i = ucfirst($i);
//                }
//                $item = implode('', $item);
//            }
//            $item = ucfirst($item);
//        }
        return implode('\\', $namespace_arr);
    }

    /**
     * @DESC         |注册模块路由
     *
     * 参数区：
     *
     * @param array $modules 【模组列表指针�?
     * @param string $path 【模组路径�?
     * @param string $name 【模组名�?
     * @param string $router 【控制器路径�?
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function registerModuleRouter(array &$modules, Module &$register_module)
    {
        $name = $register_module->getName();
        $path = $register_module->getBasePath();
        if (!$this->isDisabled($modules, $name)) {
            $module = $modules[$name];
            # API 路由
            $api_dir = $path . Handle::api_DIR . DS;
            if (is_dir($api_dir)) {
                $api_classs = [];
                $this->scan->globFile($api_dir . '*', $api_classs, '.php', $path, $module['namespace_path'] . '\\', true, true, $module['base_path']);
                foreach ($api_classs as $api_class) {
                    // 先从文件系统加载控制器类
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $api_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    if (is_file($classFile) && !class_exists($api_class, false)) {
                        require_once $classFile;
                    }
                    // 使用 false 参数避免触发自动加载
                    if (!class_exists($api_class, false)) {
                        continue;
                    }
                    $apiDirArray = explode(Handle::api_DIR, $api_class);
                    // 如果最后一个元素是空字符串（类名以Controller结尾），取倒数第二个元�?
                    $baseRouterPart = empty(end($apiDirArray)) && count($apiDirArray) > 1 ? $apiDirArray[count($apiDirArray) - 2] : array_pop($apiDirArray);
                    $baseRouter = str_replace('\\', '/', $baseRouterPart);
                    $baseRouterArr = preg_split('/(?=[A-Z])/', $baseRouter);
                    $baseRouter = '';
                    foreach ($baseRouterArr as $baseRouterKey => $baseRouter_) {
                        if (!isset($baseRouterArr[$baseRouterKey - 1])) {
                            $baseRouter .= $baseRouter_;
                            continue;
                        }
                        $pre_ = $baseRouterArr[$baseRouterKey - 1];
                        $lastChar = $pre_[strlen($pre_) - 1] ?? '';
                        if ($lastChar === '/') {
                            $baseRouter .= $baseRouter_;
                        } else {
                            $baseRouter .= '-' . $baseRouter_;
                        }
                    }

                    $this->parent_class_arr = [];// 清空父类信息
                    $ctl_data = $this->parserController($api_class, $name);
                    if (empty($ctl_data)) {
                        continue;
                    }
                    $ctl_methods = $ctl_data['methods'];
                    $ctl_area = $ctl_data['area'];
                    $backend = false;
                    if (in_array('BackendRestController', $ctl_area)) {
                        $backend = true;
                    }
                    $router = $register_module->getRouter($backend);
                    // API路由按照PC路由的方式生成，只包含模块路由和路径
                    // area 前缀会在 URL 生成时添加（�?getFrontendApiUrl() 中）
                    $baseRouter = trim($router . $baseRouter, '/');
                    // 清理双斜杠（处理所有连续斜杠）
                    $baseRouter = preg_replace('#/+#', '/', $baseRouter);
                    foreach ($ctl_methods as $method => $attributes) {
                        // 分析请求方法
                        $request_method = null;
                        $rule_method = $method;
                        $request_method_split_array = preg_split('/(?=[A-Z])/', $method);
                        if (1 === count($request_method_split_array)) {
                            $request_method_split_array[1] = $request_method_split_array[0];
                            $request_method_split_array[0] = 'get';
                        }
                        $first_value = $request_method_split_array[array_key_first($request_method_split_array)];
                        if (in_array(strtoupper($first_value), RequestInterface::METHODS)) {
                            $request_method = strtoupper($first_value);
                            array_shift($request_method_split_array);
                            $rule_method = implode('-', $request_method_split_array);
                        } else {
                            $rule_method = trim(implode('-', $request_method_split_array), '-');
                        }
                        # 检测请求方法和方法名是否重合，重合就使用方法名作为请求方法
                        if (in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 规则路由处理
                        # 删除index后缀
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_rule_arr = explode('/', trim($rule_router, '/'));
                        $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                        while ('index' === array_pop($rule_rule_arr)) {
                            $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                            continue;
                        }
                        $rule_router = implode('/', $rule_rule_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
                        $rule_router = trim($rule_router, '/');
                        // 再次清理双斜杠，确保最终路由格式正�?
                        $rule_router = preg_replace('#/+#', '/', $rule_router);

                        $request_method = $request_method ?? RequestInterface::GET;
                        # 模块路由解析
                        $routers = is_string($router) ? [$router] : $router;
                        foreach ($routers as $router_) {
                            $route = $rule_router . ($request_method ? '::' . $request_method : '');
                            $params = [
                                'type' => DataInterface::type_API,
                                'area' => $ctl_area,
                                'module' => $name,
                                'base_router' => $router_,
                                'router' => $route,
                                'class' => $api_class,
                                'module_path' => $path,
                                'method' => $method,
                                'request_method' => $request_method,
                                'is_backend' => $backend,
                                'is_enable' => true
                            ];
                            $data = new DataObject($params);
                            /**@var \ReflectionAttribute $attribute */
                            foreach ($attributes as $attribute) {
                                $data->setData('attribute', $attribute);
                                $data->setData('type', 'api');
                                $data->setData('controller_data', $ctl_data);
                                $data->setData('params', $params);
                                $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                            }
                            // 路由注册+
                            Register::register(RegisterDataInterface::ROUTER, $name, $params);

                            // 原始路由注册
                            $origin_route = str_replace('-', '', $route);
                            if ($router !== $origin_route) {
                                $backend = false;
                                if (in_array('BackendController', $ctl_area)) {
                                    $backend = true;
                                }
                                $params = [
                                    'type' => DataInterface::type_API,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $origin_route,
                                    'class' => $api_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true
                                ];
                                $data = new DataObject($params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'api');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $params);
                                    $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                }
                                // 路由注册+
                                Register::register(RegisterDataInterface::ROUTER, $name, $params);
                            }
                            
                            // 新增：如果方法名包含HTTP方法前缀，同时注册完整方法名路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 注册完整方法名的kebab-case路由�?get-add格式�?
                                $full_method_kebab = strtolower(preg_replace('/([A-Z])/', '-$1', $method));
                                $full_method_kebab_router = strtolower($baseRouter . '/' . $full_method_kebab);
                                $full_method_kebab_router = trim($full_method_kebab_router, '/');
                                $full_method_kebab_router = preg_replace('#/+#', '/', $full_method_kebab_router);
                                $full_method_kebab_route = $full_method_kebab_router . ($request_method ? '::' . $request_method : '');
                                $full_method_kebab_params = [
                                    'type' => DataInterface::type_API,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $full_method_kebab_route,
                                    'class' => $api_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true
                                ];
                                $data = new DataObject($full_method_kebab_params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'api');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $full_method_kebab_params);
                                    $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                }
                                // 路由注册+
                                Register::register(RegisterDataInterface::ROUTER, $name, $full_method_kebab_params);
                                
                                // 2. 注册完整方法名的小写路由�?getadd格式�?
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 只有当小写路由与kebab-case路由不同时才注册
                                if ($full_method_lower_router !== $full_method_kebab_router) {
                                    $full_method_lower_route = $full_method_lower_router . ($request_method ? '::' . $request_method : '');
                                    $full_method_lower_params = [
                                        'type' => DataInterface::type_API,
                                        'area' => $ctl_area,
                                        'module' => $name,
                                        'base_router' => $router_,
                                        'router' => $full_method_lower_route,
                                        'class' => $api_class,
                                        'module_path' => $path,
                                        'method' => $method,
                                        'request_method' => $request_method,
                                        'is_backend' => $backend,
                                        'is_enable' => true
                                    ];
                                    $data = new DataObject($full_method_lower_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'api');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_lower_params);
                                        $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                    }
                                    // 路由注册+
                                    Register::register(RegisterDataInterface::ROUTER, $name, $full_method_lower_params);
                                }
                            }
                        }
                    }
                }
            }

            # PC 路由
            $pc_dir = $path . Handle::pc_DIR . DS;

            if (is_dir($pc_dir)) {
                $pc_classs = [];
                $this->scan->globFile($pc_dir . '*', $pc_classs, '.php', $path, $module['namespace_path'] . '\\', true, true, $module['base_path']);
                foreach ($pc_classs as $pc_class) {
                    // 先从文件系统加载控制器类
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $pc_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    if (is_file($classFile) && !class_exists($pc_class, false)) {
                        require_once $classFile;
                    }
                    // 使用 false 参数避免触发自动加载
                    if (!class_exists($pc_class, false)) {
                        continue;
                    }
                    $pcDirArray = explode(Handle::pc_DIR, $pc_class);
                    // 如果最后一个元素是空字符串（类名以Controller结尾），取倒数第二个元�?
                    $baseRouterPart = empty(end($pcDirArray)) && count($pcDirArray) > 1 ? $pcDirArray[count($pcDirArray) - 2] : array_pop($pcDirArray);
                    $baseRouter = str_replace('\\', '/', $baseRouterPart);
                    $baseRouterArr = preg_split('/(?=[A-Z])/', $baseRouter);
                    $baseRouter = '';
                    foreach ($baseRouterArr as $baseRouterKey => $baseRouter_) {
                        if (!isset($baseRouterArr[$baseRouterKey - 1])) {
                            $baseRouter .= $baseRouter_;
                            continue;
                        }
                        $pre_ = $baseRouterArr[$baseRouterKey - 1];
                        if ($pre_) {
                            $lastChar = $pre_[strlen($pre_) - 1];
                            if ($lastChar === '/') {
                                $baseRouter .= $baseRouter_;
                            } else {
                                $baseRouter .= '-' . $baseRouter_;
                            }
                        }
                    }

                    $this->parent_class_arr = [];// 清空父类信息
                    $ctl_data = $this->parserController($pc_class, $name);
                    if (empty($ctl_data)) {
                        continue;
                    }
                    $ctl_methods = $ctl_data['methods'];
                    $ctl_area = $ctl_data['area'];
                    $backend = false;
                    if (in_array('BackendController', $ctl_area)) {
                        $backend = true;
                    }
                    $router = $register_module->getRouter($backend);
                    $baseRouter = trim($router . $baseRouter, '/');
                    foreach ($ctl_methods as $method => $attributes) {
                        // 分析请求方法
                        $request_method = '';
                        $rule_method = $method;
                        $request_method_split_array = preg_split('/(?=[A-Z])/', $method);
                        if (1 === count($request_method_split_array)) {
                            $request_method_split_array[1] = $request_method_split_array[0];
                            $request_method_split_array[0] = '';
                        }
                        $first_value = $request_method_split_array[array_key_first($request_method_split_array)];
                        if (in_array(strtoupper($first_value), RequestInterface::METHODS)) {
                            $request_method = strtoupper($first_value);
                            array_shift($request_method_split_array);
                            $rule_method = implode('-', $request_method_split_array);
                        } else {
                            $rule_method = trim(implode('-', $request_method_split_array), '-');
                        }
                        # 如果没有解析到请求方法就使用方法�?
                        if (!$request_method && in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 删除index后缀
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_rule_arr = explode('/', trim($rule_router, '/'));
                        $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                        while ('index' === array_pop($rule_rule_arr)) {
                            $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                            continue;
                        }

                        $rule_router = implode('/', $rule_rule_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
                        $rule_router = trim($rule_router, '/');

                        # 模块路由解析
                        $routers = is_string($router) ? [$router] : $router;
                        foreach ($routers as $router_) {
                            $route = $rule_router . ($request_method ? '::' . $request_method : '');
                            $params = [
                                'type' => DataInterface::type_PC,
                                'area' => $ctl_area,
                                'module' => $name,
                                'base_router' => $router_,
                                'router' => $route,
                                'class' => $pc_class,
                                'method' => $method,
                                'module_path' => $path,
                                'request_method' => $request_method,
                                'is_backend' => $backend,
                                'is_enable' => true,
                            ];
                            $data = new DataObject($params);
                            /**@var \ReflectionAttribute $attribute */
                            foreach ($attributes as $attribute) {
                                $data->setData('attribute', $attribute);
                                $data->setData('type', 'pc');
                                $data->setData('controller_data', $ctl_data);
                                $data->setData('params', $params);
                                $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                            }
                            // 路由注册+
                            Register::register(RegisterDataInterface::ROUTER, $name, $params);

                            // 原始路由注册
                            $origin_route = str_replace('-', '', $route);
                            if ($router !== $origin_route) {
                                $backend = false;
                                if (in_array('BackendController', $ctl_area)) {
                                    $backend = true;
                                }
                                $params = [
                                    'type' => DataInterface::type_PC,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $origin_route,
                                    'class' => $pc_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true,
                                ];
                                $data = new DataObject($params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'pc');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $params);
                                    $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                }
                                // 路由注册+
                                Register::register(RegisterDataInterface::ROUTER, $name, $params);
                            }
                            
                            // 新增：如果方法名包含HTTP方法前缀，同时注册完整方法名路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 注册完整方法名的kebab-case路由�?get-add格式�?
                                $full_method_kebab = strtolower(preg_replace('/([A-Z])/', '-$1', $method));
                                $full_method_kebab_router = strtolower($baseRouter . '/' . $full_method_kebab);
                                $full_method_kebab_router = trim($full_method_kebab_router, '/');
                                $full_method_kebab_router = preg_replace('#/+#', '/', $full_method_kebab_router);
                                $full_method_kebab_route = $full_method_kebab_router . ($request_method ? '::' . $request_method : '');
                                $full_method_kebab_params = [
                                    'type' => DataInterface::type_PC,
                                    'area' => $ctl_area,
                                    'module' => $name,
                                    'base_router' => $router_,
                                    'router' => $full_method_kebab_route,
                                    'class' => $pc_class,
                                    'module_path' => $path,
                                    'method' => $method,
                                    'request_method' => $request_method,
                                    'is_backend' => $backend,
                                    'is_enable' => true,
                                ];
                                $data = new DataObject($full_method_kebab_params);
                                /**@var \ReflectionAttribute $attribute */
                                foreach ($attributes as $attribute) {
                                    $data->setData('attribute', $attribute);
                                    $data->setData('type', 'pc');
                                    $data->setData('controller_data', $ctl_data);
                                    $data->setData('params', $full_method_kebab_params);
                                    $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                }
                                // 路由注册+
                                Register::register(RegisterDataInterface::ROUTER, $name, $full_method_kebab_params);
                                
                                // 2. 注册完整方法名的小写路由�?getadd格式�?
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 只有当小写路由与kebab-case路由不同时才注册
                                if ($full_method_lower_router !== $full_method_kebab_router) {
                                    $full_method_lower_route = $full_method_lower_router . ($request_method ? '::' . $request_method : '');
                                    $full_method_lower_params = [
                                        'type' => DataInterface::type_PC,
                                        'area' => $ctl_area,
                                        'module' => $name,
                                        'base_router' => $router_,
                                        'router' => $full_method_lower_route,
                                        'class' => $pc_class,
                                        'module_path' => $path,
                                        'method' => $method,
                                        'request_method' => $request_method,
                                        'is_backend' => $backend,
                                        'is_enable' => true,
                                    ];
                                    $data = new DataObject($full_method_lower_params);
                                    /**@var \ReflectionAttribute $attribute */
                                    foreach ($attributes as $attribute) {
                                        $data->setData('attribute', $attribute);
                                        $data->setData('type', 'pc');
                                        $data->setData('controller_data', $ctl_data);
                                        $data->setData('params', $full_method_lower_params);
                                        $this->getEvenManager()->dispatch('Weline_Framework_Module::controller_attributes', $data);
                                    }
                                    // 路由注册+
                                    Register::register(RegisterDataInterface::ROUTER, $name, $full_method_lower_params);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * @DESC         |模块名到路径转化
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码�?
     *
     * 参数区：
     *
     * @param array $modules
     * @param string $name
     *
     * @return string
     */
    public function moduleNameToPath(array &$modules, string $name): string
    {
        if ($this->isInstalled($modules, $name)) {
            return trim(str_replace('\\', DS, $modules[$name]['path']), DS);
        }

        return str_replace('_', DS, $name);
    }

    /**
     * @DESC         |模块名到路径转化
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码�?
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return string
     */
    public function getModulePath(string $name): string
    {
        return APP_CODE_PATH . str_replace('_', DS, $name);
    }

    /**
     * @DESC         |利用反射去除父类方法
     *
     * 参数区：
     *
     * @param string $class
     * @param        $module_name
     * @param        $router
     *
     * @return array
     * @throws \ReflectionException
     */
    private function parserController(string $class, $module_name): array
    {
        // 默认前端控制�?
//        $ctl_area = \Weline\Framework\Controller\Data\DataInterface::type_pc_FRONTEND;
        if (class_exists($class)) {
            $reflect = new \ReflectionClass($class);
            $controller_methods = [];
            foreach ($reflect->getMethods() as $method) {
                if (!is_int(strpos($method->getName(), '__'))) {
                    if ($method->isPublic()) {
                        $attributes = $method->getAttributes();
                        $controller_methods[$method->getName()] = $attributes;
                    }
                }
            }
            // 存在父类则过滤父类方�?
            if ($parent_class = $reflect->getParentClass()) {
                $controller_class = [];
                foreach (explode('\\', $parent_class->getName()) as $item) {
                    if (is_int(strpos($item, 'Controller'))) {
                        $controller_class[] = $item;
                    }
                }
                $this->parent_class_arr = array_merge($this->parent_class_arr, $controller_class);
                $parent_methods = [];
                foreach ($parent_class->getMethods() as $method) {
                    if (!is_int(strpos($method->getName(), '__'))) {
                        if ($method->isPublic()) {
                            $method_attributes = $method->getAttributes();
                            $parent_methods[$method->getName()] = $method_attributes;
                        }
                    }
                }
                $controller_methods = array_merge($parent_methods, $controller_methods);
                // 实例化类
                if (!$parent_class->isAbstract()) {
                    $this->parent_class_arr = array_merge($this->parent_class_arr, $this->parserController($parent_class->getName(), $module_name)['area']);
                }
            }

            return [
                'area' => array_unique($this->parent_class_arr),
                'methods' => $controller_methods,
                'attributes' => $reflect->getAttributes(),
                'class' => $class,
                'module_name' => $module_name,
            ];
        } else {
            return [];
        }
    }

    /**
     * @DESC         |模块是否已经安装
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码�?
     *
     * 参数区：
     *
     * @param array $modules
     * @param string $name
     *
     * @return bool
     */
    public function isInstalled(array &$modules, string $name): bool
    {
        return array_key_exists($name, $modules);
    }

    /**
     * @DESC         |模块是否已经安装
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码�?
     *
     * 参数区：
     *
     * @param array $modules
     * @param string $name
     *
     * @return bool
     */
    public function isDisabled(array &$modules, string $name): bool
    {
        if ($this->isInstalled($modules, $name) && !empty($modules[$name]['status'])) {
            return false;
        }

        return true;
    }

    /**
     * @DESC         |是否模块更新
     *
     * 参数区：
     *
     * @param array $modules
     * @param string $name
     * @param string $version
     *
     * @return bool
     */
    public function isUpgrade(string $version, string $new_version): bool
    {
        if (version_compare($new_version, $version, '>')) {
            return true;
        }

        return false;
    }

    /**
     * @DESC         |更新模块数据
     *
     * 参数区：
     *
     * @param array $modules
     */
    public function updateModules(array &$modules)
    {
        $this->file->open(Env::path_MODULES_FILE, $this->file::mode_w_add);
        $text = '<?php return ' . w_var_export($modules, true) . ';';
        $this->file->write($text);
        $this->file->close();
        Env::getInstance()->getModuleList(true);
    }

    /**
     * @DESC         |更新路由
     *
     * 参数区：
     *
     * @param array $routers
     *
     * @throws \Weline\Framework\App\Exception
     */
    public function updateRouters(array &$routers)
    {
        $this->file->open(Env::path_MODULES_FILE, $this->file::mode_w_add);
        $text = '<?php return ' . var_export($routers, true) . ';';
        $this->file->write($text);
        $this->file->close();
    }
}
