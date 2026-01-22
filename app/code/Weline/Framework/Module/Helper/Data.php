<?php

/*
 * æœ¬æ–‡ä»¶ç”± ç§‹æž«é›�é£ž ç¼–å†™ï¼Œæ‰€æœ‰è§£é‡Šæ�ƒå½’Aiwelineæ‰€æœ‰ã€?
 * é‚®ç®±ï¼šaiweline@qq.com
 * ç½‘å�€ï¼šaiweline.com
 * è®ºå�›ï¼šhttps://bbs.aiweline.com
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
    
    /** @var array 收集的控制器属性事件数据，用于批量发送 */
    private array $collected_controller_attributes_events = [];
    
    /** @var array 收集的路由注册参数，用于批量注册 */
    private array $collected_route_registrations = [];

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
     * @param array $modules 模块数组
     * @param Module $register_module 注册模块
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     */
    public function registerModuleRouter(array &$modules, Module &$register_module)
    {
        // 清空当前模块的事件收集数组和路由注册数组
        $this->collected_controller_attributes_events = [];
        $this->collected_route_registrations = [];
        
        $name = $register_module->getName();
        $path = $register_module->getBasePath();
        if (!$this->isDisabled($modules, $name)) {
            $module = $modules[$name];
            # API 路由注册
            $api_dir = $path . Handle::api_DIR . DS;
            if (is_dir($api_dir)) {
                $api_classs = [];
                $this->scan->globFile($api_dir . '*', $api_classs, '.php', $path, $module['namespace_path'] . '\\', true, true, $module['base_path']);
                foreach ($api_classs as $api_class) {
                    // 查找控制器文件
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $api_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    if (is_file($classFile) && !class_exists($api_class, false)) {
                        require_once $classFile;
                    }
                    // 如果控制器类不存在，跳过
                    if (!class_exists($api_class, false)) {
                        continue;
                    }
                    $apiDirArray = explode(Handle::api_DIR, $api_class);
                    // 获取控制器文件的 baseRouter
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

                    $this->parent_class_arr = [];// 清空父类数组
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
                    // API 路由注册包含 PC 路由注册，因此需要注册原始路由和方法路由
                    // area 在 URL 中请求时，获取前端 API URL（在 getFrontendApiUrl() 中）
                    $baseRouter = trim($router . $baseRouter, '/');
                    // 处理路由中的重复斜杠
                    $baseRouter = preg_replace('#/+#', '/', $baseRouter);
                    foreach ($ctl_methods as $method => $attributes) {
                        // 解析方法路由
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
                        # 如果方法路由为空，且方法路由为大写请求方法，则使用大写请求方法
                        if (in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 获取方法路由
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_rule_arr = explode('/', trim($rule_router, '/'));
                        $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                        while ('index' === array_pop($rule_rule_arr)) {
                            $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                            continue;
                        }
                        $rule_router = implode('/', $rule_rule_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
                        $rule_router = trim($rule_router, '/');
                        // 处理路由中的重复斜杠
                        $rule_router = preg_replace('#/+#', '/', $rule_router);

                        $request_method = $request_method ?? RequestInterface::GET;
                        # 获取路由
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
                                // 收集事件数据，不立即发送
                                $this->collected_controller_attributes_events[] = clone $data;
                            }
                            // 收集路由注册参数，不立即注册
                            $this->collected_route_registrations[] = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];

                            // 原始路由与 baseRouter 路由不一致时，注册原始路由
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
                                    // 收集事件数据，不立即发送
                                    $this->collected_controller_attributes_events[] = clone $data;
                                }
                                // 收集路由注册参数，不立即注册
                                $this->collected_route_registrations[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                            }
                            
                            // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 小写方法路由
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
                                        // 收集事件数据，不立即发送
                                        $this->collected_controller_attributes_events[] = clone $data;
                                    }
                                // 收集路由注册参数，不立即注册
                                $this->collected_route_registrations[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                                
                                // 2. 小写方法路由
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
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
                                        // 收集事件数据，不立即发送
                                        $this->collected_controller_attributes_events[] = clone $data;
                                    }
                                    // 收集路由注册参数，不立即注册
                                    $this->collected_route_registrations[] = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            # PC 路由注册
            $pc_dir = $path . Handle::pc_DIR . DS;

            if (is_dir($pc_dir)) {
                $pc_classs = [];
                $this->scan->globFile($pc_dir . '*', $pc_classs, '.php', $path, $module['namespace_path'] . '\\', true, true, $module['base_path']);
                foreach ($pc_classs as $pc_class) {
                    // 查找控制器文件
                    $classRelativePath = str_replace('\\', DS, str_replace($module['namespace_path'] . '\\', '', $pc_class)) . '.php';
                    $classFile = $module['base_path'] . $classRelativePath;
                    if (is_file($classFile) && !class_exists($pc_class, false)) {
                        require_once $classFile;
                    }
                    // 如果控制器类不存在，跳过
                    if (!class_exists($pc_class, false)) {
                        continue;
                    }
                    $pcDirArray = explode(Handle::pc_DIR, $pc_class);
                    // 获取控制器文件的 baseRouter
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

                    $this->parent_class_arr = [];// 清空父类数组
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
                        // 解析方法路由
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
                        # 如果方法路由为空，且方法路由为大写请求方法，则使用大写请求方法
                        if (!$request_method && in_array(strtoupper($rule_method), Request::METHODS)) {
                            $request_method = strtoupper($rule_method);
                            $rule_method = '';
                        }
                        # 获取方法路由
                        $rule_router = strtolower($baseRouter . '/' . $rule_method);
                        $rule_rule_arr = explode('/', trim($rule_router, '/'));
                        $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                        while ('index' === array_pop($rule_rule_arr)) {
                            $last_rule_value = empty($rule_rule_arr) ? '' : ($rule_rule_arr[array_key_last($rule_rule_arr)] ?? '');
                            continue;
                        }

                        $rule_router = implode('/', $rule_rule_arr) . (('index' !== $last_rule_value) ? '/' . $last_rule_value : '');
                        $rule_router = trim($rule_router, '/');

                        # 获取路由
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
                                // 收集事件数据，不立即发送
                                $this->collected_controller_attributes_events[] = clone $data;
                            }
                            // 收集路由注册参数，不立即注册
                            $this->collected_route_registrations[] = [
                                'type' => RegisterDataInterface::ROUTER,
                                'module_name' => $name,
                                'params' => $params
                            ];

                            // 原始路由与 baseRouter 路由不一致时，注册原始路由
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
                                    // 收集事件数据，不立即发送
                                    $this->collected_controller_attributes_events[] = clone $data;
                                }
                                // 收集路由注册参数，不立即注册
                                $this->collected_route_registrations[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $params
                                ];
                            }
                            
                            // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
                            if ($request_method && $rule_method !== strtolower($method)) {
                                // 1. 小写方法路由
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
                                        // 收集事件数据，不立即发送
                                        $this->collected_controller_attributes_events[] = clone $data;
                                    }
                                // 收集路由注册参数，不立即注册
                                $this->collected_route_registrations[] = [
                                    'type' => RegisterDataInterface::ROUTER,
                                    'module_name' => $name,
                                    'params' => $full_method_kebab_params
                                ];
                                
                                // 2. 小写方法路由
                                $full_method_lower_router = strtolower($baseRouter . '/' . strtolower($method));
                                $full_method_lower_router = trim($full_method_lower_router, '/');
                                $full_method_lower_router = preg_replace('#/+#', '/', $full_method_lower_router);
                                // 小写方法路由与 baseRouter 方法路由不一致时，注册小写方法路由
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
                                        // 收集事件数据，不立即发送
                                        $this->collected_controller_attributes_events[] = clone $data;
                                    }
                                    // 收集路由注册参数，不立即注册
                                    $this->collected_route_registrations[] = [
                                        'type' => RegisterDataInterface::ROUTER,
                                        'module_name' => $name,
                                        'params' => $full_method_lower_params
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // 批量发送收集的所有控制器属性事件
        $this->batchDispatchControllerAttributesEvents();
        
        // 批量注册收集的所有路由
        $this->batchRegisterRoutes();
    }
    
    /**
     * 批量注册收集的路由
     * 
     * @return void
     * @throws \Weline\Framework\App\Exception
     */
    private function batchRegisterRoutes(): void
    {
        if (empty($this->collected_route_registrations)) {
            return;
        }
        
        // 批量注册所有路由
        foreach ($this->collected_route_registrations as $registration) {
            Register::register(
                $registration['type'],
                $registration['module_name'],
                $registration['params']
            );
        }
        
        // 注意：不在这里调用 flushBatchRouters()
        // 批量写入由 RouteUpdateStage->commit() 统一处理
        // 这样可以确保在阶段提交时一次性写入所有路由文件
        
        // 清空收集的路由注册数据
        $this->collected_route_registrations = [];
    }
    
    /**
     * 批量发送收集的控制器属性事件
     * 
     * @return void
     */
    private function batchDispatchControllerAttributesEvents(): void
    {
        if (empty($this->collected_controller_attributes_events)) {
            return;
        }
        
        $eventsManager = $this->getEvenManager();
        
        // 批量发送：直接发送事件数据数组
        $eventsManager->dispatch('Weline_Framework_Module::controller_attributes', $this->collected_controller_attributes_events);
        
        // 清空收集的事件数据
        $this->collected_controller_attributes_events = [];
    }

    public function getEvenManager(): EventsManager
    {
        return ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * @DESC         |将模块名称转换为路径
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
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
     * @DESC         |获取模块路径
     *
     * @param string $name 模块名称
     *
     * @return string
     */
    public function getModulePath(string $name): string
    {
        return APP_CODE_PATH . str_replace('_', DS, $name);
    }

    /**
     * @DESC         |解析控制器
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
        // 清空父类数组
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
            // 在父类中查找控制器方法
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
                // 如果父类不是抽象类，则添加父类区域
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
     * @DESC         |判断模块是否已安装
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
     *
     * @return bool
     */
    public function isInstalled(array &$modules, string $name): bool
    {
        return array_key_exists($name, $modules);
    }

    /**
     * @DESC         |判断模块是否已禁用
     *
     * @param array $modules 模块数组
     * @param string $name 模块名称
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
     * @DESC         |判断模块是否需要升级
     *
     * @param string $version 当前版本
     * @param string $new_version 新版本
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
     * @DESC         |更新模块
     *
     * @param array $modules 模块数组
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
     * @param array $routers 路由数组
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
