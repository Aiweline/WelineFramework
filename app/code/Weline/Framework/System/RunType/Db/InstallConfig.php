<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\System\RunType\Db;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Setup\DataInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Helper\Data;

class InstallConfig
{
    protected Data $helper;

    protected Printing $printer;

    public function __construct()
    {
        $this->helper  = new Data();
        $this->printer = new Printing();
    }

    public function run(array $params): array
    {
        $db_config = $params['db']??'';
        $sandbox_db_config = $params['sandbox_db']??'';
        $tmp    = [];
        $msg    = '-------  数据库配置安装...  -------';
        $hasErr = false;
        if (empty($db_config)||empty($sandbox_db_config)) {
            $hasErr = true;
            $msg    = '异常的$params参数';
            if (CLI) {
                $this->printer->error($msg, 'ERROR');
            }
            $tmp['-------  数据库配置安装...  -------'] = $msg . '【✖】';
        }
        unset($db_config['action']);
        $db_config['type'] = 'mysql';
        // 参数检测
        if (CLI) {
            $this->printer->note('数据库：1、参数检测...', '系统');
        }
        $tmp['数据库：1、参数检测...'] = '系统';
        $db_keys                     = DataInterface::db_keys;
        $db_config_check                 = array_intersect_key($db_config, $db_keys);
        foreach ($db_keys as $db_key => $v) {
            if (!isset($db_config_check[$db_key])) {
                $hasErr = true;
                $msg    = '数据库' . $db_key . '配置不能为空！示例：bin/w system:install --db-' . $db_key . '=demo';
                if (CLI) {
                    $this->printer->error($msg, '系统');
                    exit();
                }
                $tmp['缺少参数'] = $msg . '【✖】';
            }
        }
        // 数据库链接检测
        $db_conf = [
            'default' => $db_config['type'],
            'master'  => $db_config,
            'slaves'  => []
        ];
        if (CLI) {
            $this->printer->note('数据库：2、数据库链接检测...', '系统');
        }
        $tmp['数据库：2、数据库链接检测...'] = '系统';
        try {
            // 使用框架的数据库连接对象进行检测
            $configProvider = new ConfigProvider($db_conf);
            $connect = ConnectionFactory::getInstance($configProvider);
            // 尝试创建连接以检测是否成功
            $connect->getConnector();
            if (CLI) {
                $this->printer->success('数据库链接检测通过', 'OK');
            }
            $tmp['数据库链接检测通过'] = '【✔】';
            $connect->close();
        } catch (\Throwable $e) {
            if (CLI) {
                $this->printer->error('数据库链接检测失败!' . 'Error: ' . $e->getMessage(), 'ERROR');
                exit();
            };
            $hasErr                        = true;
            $msg                           = '数据库链接检测失败!' . 'Error: ' . $e->getMessage();
            $tmp['数据库链接检测失败!'] = $msg . '【✖】';
            return ['data' => $tmp, 'hasErr' => $hasErr, 'msg' => $msg . '【✖】'];
        }
        // 数据库信息安装
        if (CLI) {
            $this->printer->note('数据库：3、数据库信息安装...', '系统');
        }
        $tmp['数据库：3、数据库信息安装...'] = '系统';
        try {
            Env::getInstance()->setConfig('db', $db_conf);
            $msg = '数据库安装初始化成功【✔】';
            if (CLI) {
                $this->printer->success($msg, 'OK');
            }
            $tmp['初始化保存'] = $msg;
        } catch (Exception $exception) {
            $hasErr = true;
            $msg    = '数据库安装初始化失败' . '【✖】';
            if (CLI) {
                $this->printer->error($msg, 'ERROR');
                exit();
            }
            $tmp['初始化保存'] = $msg;
            return ['data' => $db_conf, 'hasErr' => $hasErr, 'msg' => $msg];
        }

        // 数据库链接检测
        $sandbox_db_conf = [
            'default' => $sandbox_db_config['type'],
            'master'  => $sandbox_db_config,
            'slaves'  => []
        ];
        if (CLI) {
            $this->printer->note('数据库：1、Debug调试数据库链接检测...', '系统');
        }
        $tmp['数据库：1、Debug调试数据库链接检测...'] = '系统';
        try {
            // 使用框架的数据库连接对象进行检测
            $configProvider = new ConfigProvider($sandbox_db_conf);
            $connect = ConnectionFactory::getInstance($configProvider);
            // 尝试创建连接以检测是否成功
            $connect->getConnector();
            if (CLI) {
                $this->printer->success('数据库链接检测通过', 'OK');
            }
            $tmp['数据库链接检测通过'] = '【✔】';
            $connect->close();
        } catch (\Throwable $e) {
            if (CLI) {
                $this->printer->error('数据库链接检测失败!' . 'Error: ' . $e->getMessage(), 'ERROR');
                exit();
            };
            $hasErr                        = true;
            $msg                           = '数据库链接检测失败!' . 'Error: ' . $e->getMessage();
            $tmp['数据库链接检测失败!'] = $msg . '【✖】';
        }
        // 调试数据库信息安装
        if (CLI) {
            $this->printer->note('数据库：2、调试Debug数据库信息安装...', '系统');
        }
        $tmp['数据库：2、调试Debug数据库信息安装...'] = '系统';
        try {
            Env::getInstance()->setConfig('debug_db', $sandbox_db_conf);
            $msg = '数据库安装初始化成功【✔】';
            if (CLI) {
                $this->printer->success($msg, 'OK');
            }
            $tmp['初始化保存'] = $msg;
        } catch (Exception $exception) {
            $hasErr = true;
            $msg    = '数据库安装初始化失败' . '【✖】';
            if (CLI) {
                $this->printer->error($msg, 'ERROR');
                exit();
            }
            $tmp['初始化保存'] = $msg;
            return ['data' => $sandbox_db_conf, 'hasErr' => $hasErr, 'msg' => $msg];
        }

        return ['data' => $tmp, 'hasErr' => $hasErr, 'msg' => '数据库配置...'];
    }
}
