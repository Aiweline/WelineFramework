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
    private array $original_module_data = [];

    /**
     * @DESC         |注册
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string       $type         注册类型
     * @param string       $module_name  模组名
     * @param array|string $param        参数[模组类型:此处传输目录__DIR__,主题类型：['name' => 'demo','path' => __DIR__,]]
     * @param array        $dependencies 依赖定义【例如:['Weline_Theme','Weline_Backend']】
     * @param string       $version      版本
     * @param string       $description  描述
     *
     * @return mixed
     * @throws App\Exception
     * @throws \ReflectionException
     */
    public static function register(string $type, string $module_name, array|string $param, string $version = '', string $description = '', array $dependencies = []): mixed
    {
        $install_params = func_get_args();
        switch ($type) {
            // 模块安装
            case self::MODULE:
                $appPathArray    = explode(DS, $param);
                $module_name_dir = array_pop($appPathArray);
                $vendor_dir      = array_pop($appPathArray);
                // 安装数据
                $install_params = [$type, $module_name, ['dir_path' => $vendor_dir . DS . $module_name_dir . DS, 'base_path' => $param . DS, 'module_name' => $module_name], $version, $description, $dependencies];
                break;
            // 路由注册
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
        $eventsManager->dispatch('Framework_Register::register_installer', ['data' => $installerPathData]);
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

    static public function parserRegisterFunctionParams(string $register_file)
    {
        if (!is_file($register_file)) {
            throw new Exception($register_file . __('注册文件不存在！'));
        }
        $registerArgs = self::getStaticFunctions($register_file);
        $registerArgs = array_shift($registerArgs) ?? [];
        if (empty($registerArgs)) {
            throw new Exception($register_file . __(' 文件中：Register::register(...)  函数参数不能为空'));
        }
        // 反解析参数名
        $registerRef = new \ReflectionClass(\Weline\Framework\Register\Register::class);
        $method      = $registerRef->getMethod('register');
        foreach ($method->getParameters() as $key => $argument) {
            $registerArgs[$argument->getName()] = $registerArgs[$key] ?? (($argument->getType()->getName() === 'array') ? [] : null);
            unset($registerArgs[$key]);
        }
        return $registerArgs;
    }

    /**
     * @DESC          # 获取所有注册文件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/6/23 20:49
     * 参数区：
     * @return array
     */
    static function scanRegisters(): array
    {
        # 扫描app模块
        $app_modules = glob(APP_CODE_PATH . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT);
        # 扫描vendor模块
        $vendor_modules = glob(VENDOR_PATH . '*' . DS . '*' . DS . RegisterInterface::register_file, GLOB_NOSORT);
        # 合并
        return array_merge($vendor_modules, $app_modules);
    }


    /**
     * @DESC          # 解析注册文件中的注册函数
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/6/24 0:09
     * 参数区：
     *
     * @param $register_file
     *
     * @return array
     */
    static function getStaticFunctions($register_file)
    {
        $tokens = token_get_all(file_get_contents($register_file));
        $calls  = array();
        foreach ($tokens as $key => $token) {
            if (is_array($token) && $token[0] == T_DOUBLE_COLON) {
                $call                = '';
                $start               = $key - 1;
                $brackets            = 0;
                $params              = array();
                $left_square_bracket = false;
                $params_key          = 0;
                $long_params         = '';
                $function_name       = '';
                while ($brackets >= 0) {
                    $current_token = is_array($tokens[$start]) ? $tokens[$start][1] : $tokens[$start];
                    $call          .= $current_token;

                    if ($current_token == '(') {
                        $brackets++;
                        $function_name = trim($tokens[$start - 3][1]) . trim($tokens[$start - 2][1]) . trim($tokens[$start - 1][1]);
                    } elseif ($current_token == ')') {
                        $brackets = -1;
                    }
                    if ($brackets > 0) {
                        if ($current_token == ']') {
                            $left_square_bracket = false;
                        }
                        if ($current_token == '[') {
                            $left_square_bracket = true;
                        }
                        if ($left_square_bracket) {
                            if ($current_token !== '[' && $current_token !== ']') {
                                if (trim($current_token)) {
                                    $long_params .= $current_token;
                                }
                            }
                        } else {
                            if ($current_token == ',') {
                                if ($tokens[$start - 2][1] == '::') {
                                    $params[$params_key] = trim($tokens[$start - 3][1]) . trim($tokens[$start - 2][1]) . trim($tokens[$start - 1][1]);
                                } else {
                                    $params[$params_key] = trim($tokens[$start - 1][1]);
                                }
                                $params_key += 1;
                            }
                        }
                        if (!$left_square_bracket) {
                            $long_params         = rtrim($long_params, ',');
                            $params[$params_key] = explode(',', $long_params);
                        }
                    }
                    $start++;
                }
                if ($function_name) {
                    $calls[$function_name] = $params;
                }
                $function_name = '';
                $params        = [];
            }
        }
        return $calls;
    }

    /**
     * @DESC          # 获取原始模组的信息（包含未注册的模组）
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/6/24 0:27
     * 参数区：
     * @return array
     * @throws \Weline\Framework\App\Exception
     */
    static function getOriginModulesData(): array
    {
        $registers = Register::scanRegisters();
        $modules   = [];
        foreach ($registers as $register) {
            $registerArgs = Register::parserRegisterFunctionParams($register);
            $module       = trim($registerArgs['module_name'], '\'\"');
            $vendorArr    = explode('_', $module);
            $vendor       = array_shift($vendorArr);
            $base_path    = str_replace(Register::register_file, '', $register);
            $env_file     = $base_path . 'etc' . DS . 'env.php';
            $env          = [];
            if (file_exists($env_file)) {
                $env = (array)include $env_file;
            }
            $dependencies = $registerArgs['dependencies'] ?? [];
            foreach ($dependencies as &$dependency) {
                $dependency = trim($dependency, '\'"');
            }
            $dependencies = array_merge($dependencies, ($env['dependencies'] ?? []));
            $pathArr      = explode(DS, $base_path);
            $path         = array_pop($pathArr);
            if (empty($path)) {
                $path = array_pop($pathArr);
            }
            $path                      = array_pop($pathArr) . DS . $path;
            $modules[$vendor][$module] = [
                'vendor'       => $vendor,
                'name'         => $module,
                'path'         => $path,
                'register'     => $register,
                'id'           => $module,
                'dependencies' => $dependencies,
                'env_file'     => $env_file,
                'base_path'    => $base_path,
                'env'          => $env
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
        $dependencyModel   = ObjectManager::getInstance(Sort::class);
        $dependencyModules = $dependencyModel->dependenciesSort($dependency_modules);
        return [$modules, $dependencyModules];
    }
}
