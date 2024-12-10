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
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private System $system;
    private Printing $printing;

    public function __construct(
        System   $system,
        Printing $printing
    ) {
        $this->system   = $system;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
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
        $modules      = Env::getInstance()->getActiveModules();
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

        foreach ($modules as $module) {
            $test_path      = $module['base_path'] . 'test' . DS;
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
                        $suite_name = $testsuite['@attributes']['name'] ?? $module['name'];
                        unset($testsuite['@attributes']);
                        foreach ($testsuite as $key => $testsuite_data) {
                            if (($key === 'file' or $key === 'directory') and !str_starts_with(BP, $testsuite_data)) {
                                $testsuite_data = $test_path . $testsuite_data;
                            }
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
        $code_framework_modules   = glob(APP_CODE_PATH . 'Weline' . DS . 'Framework' . DS . '*' . DS . 'test', GLOB_ONLYDIR);
        $vendor_framework_modules = glob(VENDOR_PATH . 'weline' . DS . 'framework' . DS . '*' . DS . 'test', GLOB_ONLYDIR);
        $framework_modules        = array_merge($vendor_framework_modules, $code_framework_modules);
        foreach ($framework_modules as $test_path) {
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
                        $suite_name = $testsuite['@attributes']['name'] ?? $module['name'];
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
                    $testsuites .= "
        <testsuite name='framework'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
        <testsuite name='unit'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
        <testsuite name='Weline_Framework'>
            <directory suffix=\"Test.php\">$test_path</directory>
        </testsuite>
                        ";
                }
                $php_unit_xml .= "
            $testsuites
            ";
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
        $text_suite_name = $args[1] ?? 'unit';
        $this->printing->note(__('正在测试套件: %1', $text_suite_name));
        $ds = DS;
        $command = $this->system->exec(PHP_BINARY.' '.VENDOR_PATH."{$ds}phpunit{$ds}phpunit{$ds}phpunit --configuration $php_unit_config_path", false);
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
