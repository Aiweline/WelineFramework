<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/16 08:37:10
 */

namespace Weline\DeveloperWorkspace\Console\PhpUnit;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\App\System;
use Weline\Framework\Output\Cli\Printing;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        System   $system,
        Printing $printing
    )
    {
        $this->system = $system;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 提示是否运行：生产环境禁止运行
        if (Env::get('deploy') !== 'dev') {
            $this->printing->setup(__('非开发环境禁止运行！如你确认是dev环境，请运行php bin/w deploy:model:set dev 转换环境后运行！'));
            exit(1);
        }
        $this->printing->note(__('正在 收集 测试套件...'));
        $php_unit_path = DEV_PATH . 'phpunit' . DS;
        if (!is_dir($php_unit_path)) {
            mkdir($php_unit_path, 755, true);
        }
        $php_unit_report_path = $php_unit_path . 'report';
        if (!is_dir($php_unit_report_path)) {
            mkdir($php_unit_report_path, 755, true);
        }
        $php_unit_config_path = $php_unit_path . 'config.xml';
        # 先把所有模组的test文件目录写到phpunit.xml【避免全目录扫描提升测试速度】
        $modules = Env::getInstance()->getActiveModules();
        $php_unit_xml = '<?xml version=\'1.0\' encoding=\'UTF-8\'?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="http://schema.phpunit.de/6.2/phpunit.xsd"
         backupGlobals="false"
         backupStaticAttributes="false"
         bootstrap="../../app/bootstrap.php"
         colors="true"
         convertErrorsToExceptions="true"
         convertNoticesToExceptions="true"
         convertWarningsToExceptions="true"
         defaultTestSuite="unit"
         processIsolation="false"
         stopOnFailure="false">
    <testsuites>';
        $exist_suites = [];
        foreach ($modules as $module) {
            $test_path = $module['base_path'] . 'test' . DS;
            $testsuite_path = $test_path . 'testsuite.xml';
            if (is_dir($test_path)) {
                $testsuites = '';
                if (is_file($testsuite_path)) {
                    $xml = simplexml_load_file($testsuite_path);
                    foreach ($xml->children() as $testsuite) {
                        $testsuite = get_object_vars($testsuite);
                        if (!isset($testsuite['@attributes']['name'])) {
                            throw new Exception(__('testsuite套件配置错误,未配置套件名：%1 ，示例：<testsuite name="unit"><file>CacheTest.php</file></testsuite>', $testsuite_path));
                        }
                        $suite_name = $testsuite['@attributes']['name'] ?? $module['name'];
                        unset($testsuite['@attributes']);
                        foreach ($testsuite as $key => $testsuite_data) {
                            if (($key === 'file' or $key === 'directory') and !str_starts_with(BP, $testsuite_data)) {
                                $testsuite_data = $test_path . $testsuite_data;
                            }
                            $exist_suites[$suite_name] = $suite_name;
                            $testsuites .= "
        <testsuite name='unit'>
            <{$key}>{$testsuite_data}</{$key}>
        </testsuite>
        <testsuite name='$suite_name'>
            <{$key}>{$testsuite_data}</{$key}>
        </testsuite>
                        ";
                        }
                    }
                } else {
                    $exist_suites[$module['name']] = $module['name'];
                    $testsuites .= "
        <testsuite name='unit'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
        <testsuite name='{$module['name']}'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
                        ";
                }
                $php_unit_xml .= "
            $testsuites
            ";
            }
        }
        $app_code_weline_framework_dir = APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS;
        $code_framework_modules = glob($app_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        foreach ($code_framework_modules as $key => $test_dir) {
            $key_new = str_replace($app_code_weline_framework_dir, '', $test_dir);
            $key_new = explode(DS,$key_new);
            array_pop($key_new);
            $key_new = implode(':',$key_new);
            unset($code_framework_modules[$key]);
            $code_framework_modules[$key_new] = $test_dir;
        }
        $vendor_code_weline_framework_dir = APP_CODE_PATH . 'weline' . DS . 'framework' . DS;
        $vendor_framework_modules = glob($vendor_code_weline_framework_dir . '*' . DS . 'test', GLOB_ONLYDIR);
        foreach ($vendor_framework_modules as $key => $test_dir) {
            $key_new = str_replace($vendor_code_weline_framework_dir, '', $test_dir);
            $key_new = explode(DS,$key_new);
            array_pop($key_new);
            $key_new = implode(':',$key_new);
            unset($vendor_framework_modules[$key]);
            $vendor_framework_modules[$key_new] = $test_dir;
        }
        # 代码code目录优先级最高
        $framework_modules = array_merge($vendor_framework_modules, $code_framework_modules);
        foreach ($framework_modules as $path_name => $test_path) {
            $path_name = str_replace(DS, ':', $path_name);
            $test_path = $test_path .DS;
            $testsuite_path = $test_path . 'testsuite.xml';
            if (is_dir($test_path)) {
                $testsuites = '';
                if (is_file($testsuite_path)) {
                    $xml = simplexml_load_file($testsuite_path);
                    foreach ($xml->children() as $testsuite) {
                        $testsuite = get_object_vars($testsuite);
                        if (!isset($testsuite['@attributes']['name'])) {
                            throw new Exception(__('testsuite套件配置错误,未配置套件名：%1 ，示例：<testsuite name="unit">
                                <file>CacheTest.php</file>
                            </testsuite>', $testsuite_path));
                        }
                        # 校验套件名称
                        $suite_name = $testsuite['@attributes']['name'] ?? '';
                        if(empty($suite_name)){
                            $suite_name = "framework::" . $path_name;
                        }
                        unset($testsuite['@attributes']);
                        foreach ($testsuite as $key => $testsuite_data) {
                            if (($key === 'file' or $key === 'directory') and !str_starts_with(BP, $testsuite_data)) {
                                $testsuite_data = $test_path . $testsuite_data;
                            }
                            $testsuites .= "
                                            <testsuite name='framework'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            <testsuite name='unit'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            <testsuite name='$suite_name'>
                                                <{$key}>{$testsuite_data}</{$key}>
                                            </testsuite>
                                            ";
                        }
                    }
                } else {
                    # 校验套件名称
                    $suite_name = "framework::" . $path_name;
                    $testsuites .= "
                                    <testsuite name='framework'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                                    <testsuite name='unit'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                                    <testsuite name='$suite_name'>
                                        <directory suffix=\"Test.php\">$test_path</directory>
                                    </testsuite>
                        ";
                }
                $php_unit_xml .= $testsuites;
            }
        }
        $php_unit_xml .= '</testsuites>
<coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">../../app</directory>
        </include>
    </coverage>
    <!--<php>
        <server name="APP_ENV" value="testing"/>
        <server name="BCRYPT_ROUNDS" value="4"/>
        <server name="CACHE_DRIVER" value="array"/>
        <server name="DB_CONNECTION" value="sqlite"/>
        <server name="DB_DATABASE" value=":memory:"/>
        <server name="MAIL_MAILER" value="array"/>
        <server name="QUEUE_CONNECTION" value="sync"/>
        <server name="SESSION_DRIVER" value="file"/>
        <server name="TELESCOPE_ENABLED" value="false"/>
    </php>-->
     <logging>
        <junit outputFile="' . $php_unit_report_path . '/junit.xml"/>
        <teamcity outputFile="' . $php_unit_report_path . '/teamcity.txt"/>
        <testdoxHtml outputFile="' . $php_unit_report_path . '/index.html"/>
        <testdoxText outputFile="' . $php_unit_report_path . '/testdox.txt"/>
        <testdoxXml outputFile="' . $php_unit_report_path . '/testdox.xml"/>
        <text outputFile="' . $php_unit_report_path . '/logfile.txt"/>
     </logging>
</phpunit>
';
        file_put_contents($php_unit_config_path, $php_unit_xml);
        # 去除非数字参数
        $command = $args['command'];
        foreach ($args as $arg_key => $arg) {
            if ($arg === $command) {
                unset($args[$arg_key]);
            }
        }
//        if (count($args) > 1) {
//            $this->printing->note(__('每次仅允许执行一个测试套件名，当前套件名: %1', implode(',', $args)));
//            exit(1);
//        }
        $text_suite_name = implode(',', $args) ?: 'unit';
        $this->printing->note(__('收集完成，准备运行...'));
        $this->printing->note(__('正在测试套件: %1', $text_suite_name));
        $this->printing->setup(__('重要提示：测试套件运行过程中会操作数据库，从而产生不可预知的风险。请确认当前环境非生产环境，你确认当前环境非生产环境么？(y/n)'));
        if (strtolower(trim($this->system->input())) !== 'y') {
            $this->printing->setup(__('已停止运行！'));
            exit(1);
        }
        $this->printing->setup(__('重要提示：再次确认需要运行么？(y/n)'));
        if (strtolower(trim($this->system->input())) !== 'y') {
            $this->printing->setup(__('已停止运行！'));
            exit(1);
        }
        $ds = DS;
        $command = $this->system->exec(PHP_BINARY . ' ' . VENDOR_PATH . "{$ds}phpunit{$ds}phpunit{$ds}phpunit --configuration $php_unit_config_path --testsuite $text_suite_name", false);
        $this->printing->success($command['command']);
        $this->printing->success(implode("\r\n", $command['output']));
        if ($command['return_vars']) {
            $this->printing->success((string)$command['return_vars']);
        }
        $this->system->exec("php -S localhost:8080 -t $php_unit_report_path");
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'PhpUnite测试套件测试命令';
    }
}
