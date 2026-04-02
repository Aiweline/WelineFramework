#!/usr/bin/env php
<?php

/**
 * 数据库升级回滚系统测试执行脚本
 *
 * 用法:
 *   php run_database_tests.php [options]
 *
 * 选项:
 *   --unit              只执行单元测试
 *   --e2e               只执行E2E测试
 *   --coverage          生成覆盖率报告
 *   --verbose           详细输出
 *   --filter=<pattern>  过滤测试用例
 *   --help              显示帮助信息
 */

// 解析命令行参数
$options = getopt('', [
    'unit',
    'e2e',
    'coverage',
    'verbose',
    'filter:',
    'help'
]);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// 配置
$config = [
    'unit' => isset($options['unit']),
    'e2e' => isset($options['e2e']),
    'coverage' => isset($options['coverage']),
    'verbose' => isset($options['verbose']),
    'filter' => $options['filter'] ?? null,
];

// 如果没有指定测试类型，执行所有测试
if (!$config['unit'] && !$config['e2e']) {
    $config['unit'] = true;
    $config['e2e'] = true;
}

// 颜色输出
function colorOutput($text, $color = 'white') {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    return $colors[$color] . $text . $colors['reset'];
}

function showHelp() {
    echo colorOutput("数据库升级回滚系统测试执行脚本\n\n", 'blue');
    echo "用法:\n";
    echo "  php run_database_tests.php [options]\n\n";
    echo "选项:\n";
    echo "  --unit              只执行单元测试\n";
    echo "  --e2e               只执行E2E测试\n";
    echo "  --coverage          生成覆盖率报告\n";
    echo "  --verbose           详细输出\n";
    echo "  --filter=<pattern>  过滤测试用例\n";
    echo "  --help              显示帮助信息\n\n";
    echo "示例:\n";
    echo "  php run_database_tests.php --unit\n";
    echo "  php run_database_tests.php --e2e --verbose\n";
    echo "  php run_database_tests.php --coverage\n";
    echo "  php run_database_tests.php --filter=FieldBackupServiceTest\n";
}

function runTests($type, $config) {
    echo colorOutput("\n=== 执行{$type}测试 ===\n", 'blue');

    $testPath = __DIR__;
    // 修正路径：从 app/code/Weline/Framework/Test 到根目录
    $rootDir = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
    $phpunitBin = $rootDir . '/vendor/bin/phpunit';

    // 检查PHPUnit是否存在
    if (!file_exists($phpunitBin)) {
        echo colorOutput("错误: PHPUnit未安装在 {$phpunitBin}，请运行 composer install\n", 'red');
        return false;
    }

    // 构建命令
    $cmd = "php {$phpunitBin}";

    // 添加测试路径
    if ($type === '单元') {
        $cmd .= " {$testPath}/Unit";
    } elseif ($type === 'E2E') {
        $cmd .= " {$testPath}/E2E";
    }

    // 添加过滤器
    if ($config['filter']) {
        $cmd .= " --filter=" . escapeshellarg($config['filter']);
    }

    // 添加详细输出
    if ($config['verbose']) {
        $cmd .= " --verbose";
    }

    // 添加覆盖率
    if ($config['coverage']) {
        $coverageDir = $rootDir . '/var/test-coverage';
        if (!is_dir($coverageDir)) {
            mkdir($coverageDir, 0755, true);
        }
        $cmd .= " --coverage-html {$coverageDir}/{$type}";
        $cmd .= " --coverage-text";
    }

    // 添加颜色输出
    $cmd .= " --colors=always";

    // 执行测试
    echo colorOutput("执行命令: {$cmd}\n\n", 'yellow');

    $startTime = microtime(true);
    passthru($cmd, $returnCode);
    $endTime = microtime(true);

    $duration = round($endTime - $startTime, 2);

    if ($returnCode === 0) {
        echo colorOutput("\n✓ {$type}测试通过 (耗时: {$duration}秒)\n", 'green');
        return true;
    } else {
        echo colorOutput("\n✗ {$type}测试失败 (耗时: {$duration}秒)\n", 'red');
        return false;
    }
}

// 主执行流程
echo colorOutput("╔════════════════════════════════════════════════════════════╗\n", 'blue');
echo colorOutput("║     WelineFramework 数据库升级回滚系统测试套件           ║\n", 'blue');
echo colorOutput("╚════════════════════════════════════════════════════════════╝\n", 'blue');

$results = [];
$startTime = microtime(true);

// 执行单元测试
if ($config['unit']) {
    $results['unit'] = runTests('单元', $config);
}

// 执行E2E测试
if ($config['e2e']) {
    $results['e2e'] = runTests('E2E', $config);
}

$endTime = microtime(true);
$totalDuration = round($endTime - $startTime, 2);

// 输出总结
echo colorOutput("\n╔════════════════════════════════════════════════════════════╗\n", 'blue');
echo colorOutput("║                        测试总结                            ║\n", 'blue');
echo colorOutput("╚════════════════════════════════════════════════════════════╝\n", 'blue');

$allPassed = true;
foreach ($results as $type => $passed) {
    $status = $passed ? colorOutput('✓ 通过', 'green') : colorOutput('✗ 失败', 'red');
    $typeName = $type === 'unit' ? '单元测试' : 'E2E测试';
    echo "{$typeName}: {$status}\n";
    if (!$passed) {
        $allPassed = false;
    }
}

echo "\n总耗时: {$totalDuration}秒\n";

if ($config['coverage']) {
    $coverageDir = __DIR__ . '/../../../../var/test-coverage';
    echo colorOutput("\n覆盖率报告已生成: {$coverageDir}\n", 'yellow');
}

// 输出最终结果
echo "\n";
if ($allPassed) {
    echo colorOutput("╔════════════════════════════════════════════════════════════╗\n", 'green');
    echo colorOutput("║                  ✓ 所有测试通过！                         ║\n", 'green');
    echo colorOutput("╚════════════════════════════════════════════════════════════╝\n", 'green');
    exit(0);
} else {
    echo colorOutput("╔════════════════════════════════════════════════════════════╗\n", 'red');
    echo colorOutput("║                  ✗ 部分测试失败                           ║\n", 'red');
    echo colorOutput("╚════════════════════════════════════════════════════════════╝\n", 'red');
    exit(1);
}
