<?php
/**
 * 批量测试所有后台控制器路由
 * 
 * 用途：快速检测所有 AI 模块后台路由是否正常工作
 * 运行：php app/code/Weline/Ai/Test/Http/Backend/BatchTest.php
 */

require __DIR__ . '/../../../../../../../app/bootstrap.php';

echo "\n";
echo "========================================\n";
echo "  Weline_Ai 后台路由批量测试\n";
echo "========================================\n";
echo "\n";

// 定义所有需要测试的路由（精简后仅保留 AI 管理、场景配置、模型、适配器、供应商、默认模型）
$routes = [
    'manager/index' => 'AI管理',
    'model/index' => 'AI模型管理',
    'adapter/index' => '场景适配器',
    'provider/index' => '供应商账户',
    'defaultmodel/index' => '默认模型管理',
];

// 统计
$total = count($routes);
$success = 0;
$errors = 0;
$warnings = 0;
$results = [];

echo "总共需要测试 {$total} 个路由\n";
echo "开始测试...\n\n";

foreach ($routes as $route => $description) {
    $fullRoute = "ai/backend/{$route}";
    echo str_pad("[" . ($success + $errors + $warnings + 1) . "/{$total}]", 10) . " 测试: {$description} ({$fullRoute})";
    
    // 执行测试命令
    $cmd = "php bin/w http:request {$fullRoute} -b --login -n=1 2>&1";
    $outputLines = [];
    $returnCode = 0;
    exec($cmd, $outputLines, $returnCode);
    $output = implode("\n", $outputLines);
    
    // 分析结果
    $status = 'UNKNOWN';
    $message = '';
    
    if (stripos($output, 'Fatal error') !== false || stripos($output, 'Uncaught') !== false) {
        $status = 'ERROR';
        $errors++;
        
        // 提取错误信息
        if (preg_match('/Fatal error: (.+?) in/i', $output, $matches)) {
            $message = $matches[1];
        } elseif (preg_match('/Uncaught (\w+): (.+?) in/i', $output, $matches)) {
            $message = "{$matches[1]}: {$matches[2]}";
        } else {
            $message = '代码执行错误';
        }
        
        echo " ❌\n";
        echo "         错误: {$message}\n";
        
    } elseif (stripos($output, 'SQLSTATE') !== false || stripos($output, 'Column not found') !== false) {
        $status = 'SQL_ERROR';
        $errors++;
        
        // 提取SQL错误
        if (preg_match('/SQLSTATE\[.+?\]: (.+?)(?:\s|$)/i', $output, $matches)) {
            $message = $matches[1];
        } else {
            $message = 'SQL查询错误';
        }
        
        echo " ❌\n";
        echo "         SQL错误: {$message}\n";
        
    } elseif (stripos($output, '模板文件不存在') !== false || stripos($output, 'Template') !== false && stripos($output, 'not found') !== false) {
        $status = 'NO_TEMPLATE';
        $warnings++;
        $message = '缺少模板文件';
        
        echo " ⚠️\n";
        echo "         警告: {$message}\n";
        
    } elseif (stripos($output, '响应状态码: 200') !== false || stripos($output, 'HTTP/2 200') !== false) {
        $status = 'OK';
        $success++;
        $message = '正常';
        
        echo " ✅\n";
        
    } elseif (stripos($output, '响应状态码: 302') !== false) {
        $status = 'REDIRECT';
        $warnings++;
        $message = '重定向（可能是权限问题）';
        
        echo " ⚠️\n";
        echo "         警告: {$message}\n";
        
    } else {
        $status = 'UNKNOWN';
        $warnings++;
        $message = '未知响应';
        
        echo " ⚠️\n";
        echo "         警告: {$message}\n";
    }
    
    // 记录结果
    $results[] = [
        'route' => $fullRoute,
        'description' => $description,
        'status' => $status,
        'message' => $message,
    ];
}

// 输出汇总
echo "\n";
echo "========================================\n";
echo "  测试结果汇总\n";
echo "========================================\n";
echo "总计: {$total} 个路由\n";
echo "✅ 成功: {$success}\n";
echo "❌ 错误: {$errors}\n";
echo "⚠️  警告: {$warnings}\n";
echo "\n";

// 输出详细错误列表
if ($errors > 0) {
    echo "错误详情:\n";
    echo "--------\n";
    foreach ($results as $result) {
        if ($result['status'] === 'ERROR' || $result['status'] === 'SQL_ERROR') {
            echo "  {$result['description']} ({$result['route']})\n";
            echo "    → {$result['message']}\n";
        }
    }
    echo "\n";
}

// 输出警告列表
if ($warnings > 0) {
    echo "警告详情:\n";
    echo "--------\n";
    foreach ($results as $result) {
        if ($result['status'] === 'NO_TEMPLATE' || $result['status'] === 'REDIRECT' || $result['status'] === 'UNKNOWN') {
            echo "  {$result['description']} ({$result['route']})\n";
            echo "    → {$result['message']}\n";
        }
    }
    echo "\n";
}

// 将结果保存到JSON文件
$jsonFile = __DIR__ . '/batch-test-results.json';
file_put_contents($jsonFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "详细结果已保存到: {$jsonFile}\n";

echo "\n测试完成时间：" . date('Y-m-d H:i:s') . "\n\n";

// 返回退出码
exit($errors > 0 ? 1 : 0);

