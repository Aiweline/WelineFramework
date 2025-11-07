<?php
/**
 * 运行所有HTTP集成测试
 * 
 * Usage: php app/code/Weline/Cdn/Test/Http/run-all-tests.script.php
 */

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║        Weline_Cdn HTTP Integration Tests Suite                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

$testFiles = [
    'Backend/AccountHttp.script.php',
    'Backend/DomainHttp.script.php',
    'Backend/RulesHttp.script.php',
    'Backend/WarmupHttp.script.php',
    'Api/ClearApiHttp.script.php',
];

$basePath = __DIR__;
$passed = 0;
$failed = 0;
$total = count($testFiles);

foreach ($testFiles as $testFile) {
    $filePath = $basePath . DIRECTORY_SEPARATOR . $testFile;
    
    if (!file_exists($filePath)) {
        echo "⚠️  Test file not found: $testFile\n\n";
        $failed++;
        continue;
    }
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Running: $testFile\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $output = [];
    $returnCode = 0;
    exec("php \"$filePath\"", $output, $returnCode);
    
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    if ($returnCode === 0) {
        echo "✅ PASSED\n\n";
        $passed++;
    } else {
        echo "❌ FAILED (exit code: $returnCode)\n\n";
        $failed++;
    }
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                    Test Summary                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "Total:   $total\n";
echo "Passed:  $passed ✅\n";
echo "Failed:  $failed ❌\n";
echo "Success Rate: " . round(($passed / $total) * 100, 1) . "%\n";
echo "\n";

exit($failed > 0 ? 1 : 0);

