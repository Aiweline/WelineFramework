<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Router;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Console\ConsoleException;
use Weline\Framework\Controller\Data\DataInterface as DataInterfaceAlias;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Register\RegisterInterface;
use Weline\Framework\Register\Router\Data\DataInterface;
use Weline\Framework\Router\Helper\Data;

class Handle implements RegisterInterface
{
    public const path_backend_PC = Env::path_BACKEND_PC_ROUTER_FILE;

    public const path_frontend_PC = Env::path_FRONTEND_PC_ROUTER_FILE;

    public const path_fronted_API = Env::path_FRONTEND_REST_API_ROUTER_FILE;

    public const path_backend_API = Env::path_BACKEND_REST_API_ROUTER_FILE;

    private Data $helper;

    private array $modules;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct()
    {
        $this->helper   = new Data();
        $this->modules  = Env::getInstance()->getModuleList();
        $this->printing = new Printing();
    }

    /**
     * @DESC         |路由注册
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string       $type
     * @param string       $module_name
     * @param array|string $param
     * @param string       $version
     * @param string       $description
     *
     * @return array
     * @throws Exception
     */
    public function register(string $type, string $module_name, array|string $param, string $version = '', string $description = ''): array
    {
        $controller = explode('Controller', $param['class']);
        $controller = array_pop($controller);
        $controller = ltrim(str_replace('\\', '/', $controller), '/');
        switch ($param['type']) {
            case DataInterface::type_API:
                $path = '';
                if (in_array(DataInterfaceAlias::type_api_REST_FRONTEND, $param['area'], true)) {
                    $path          = self::path_fronted_API;
                    $param['area'] = DataInterfaceAlias::type_api_REST_FRONTEND;
                } elseif (in_array(DataInterfaceAlias::type_api_BACKEND, $param['area'], true)) {
                    $path          = self::path_backend_API;
                    $param['area'] = DataInterfaceAlias::type_api_BACKEND;
                } else {
                    $param['area'] = self::path_fronted_API;
                }
                if ($path) {
                    $router = [
                        'module'      => $param['module'],
                        'module_path' => $param['module_path'],
                        'router'      => $param['base_router'],
                        'class'       => [
                            'area'            => $param['area'],
                            'name'            => $param['class'],
                            'controller_name' => $controller,
                            'method'          => $param['method'],
                            'request_method'  => $param['request_method'],
                        ],
                    ];
                    // 如果模块已安装
                    $api = ['router' => $param['router'], 'rule' => $router];
                    // 更新api路由
                    $this->helper->updateApiRouters($path, $api);

                    return $api;
                }

                break;
            case DataInterface::type_PC:
                $path = '';
                if (in_array(DataInterfaceAlias::type_pc_FRONTEND, $param['area'], true)) {
                    $path          = self::path_frontend_PC;
                    $param['area'] = DataInterfaceAlias::type_pc_FRONTEND;
                } elseif (in_array(DataInterfaceAlias::type_pc_BACKEND, $param['area'], true)) {
                    $path          = self::path_backend_PC;
                    $param['area'] = DataInterfaceAlias::type_pc_BACKEND;
                }
                $routers = [];
                if ($path) {
                    if (is_file($path)) {
                        $routers = require $path;
                    }
                    $router                    = [
                        'module'      => $param['module'],
                        'module_path' => $param['module_path'],
                        'router'      => $param['base_router'],
                        'class'       => [
                            'area'            => $param['area'],
                            'name'            => $param['class'],
                            'method'          => $param['method'],
                            'controller_name' => $controller,
                            'request_method'  => $param['request_method'],
                        ],
                    ];
                    $routers[$param['router']] = $router;
                    // 写入路由文件
                    $this->helper->updatePcRouters($path, $routers);
                }
                return $routers;
            default:
                throw new ConsoleException('未知的路由类型：' . $param['type']);
        }
        return [];
    }
}
