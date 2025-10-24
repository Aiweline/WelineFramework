# 运行新增的单元测试
# 用途：验证最近改进的功能（collect() JSON响应和 AiModel 新字段）

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  运行 Weline AI 模块新增单元测试" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 测试计数器
$TOTAL_TESTS = 0
$PASSED_TESTS = 0
$FAILED_TESTS = 0

# 测试文件列表
$TEST_FILES = @(
    "app/code/Weline/Ai/tests/unit/test_model_controller_json_response.php",
    "app/code/Weline/Ai/tests/unit/test_ai_model_new_fields.php"
)

Write-Host "测试文件:" -ForegroundColor Yellow
foreach ($file in $TEST_FILES) {
    Write-Host "  - $file"
}
Write-Host ""

# 运行每个测试文件
foreach ($test_file in $TEST_FILES) {
    $fileName = Split-Path $test_file -Leaf
    Write-Host "正在运行: $fileName" -ForegroundColor Yellow
    Write-Host "----------------------------------------"
    
    # 运行测试
    $result = & php bin/w phpunit:run $test_file
    $exitCode = $LASTEXITCODE
    
    if ($exitCode -eq 0) {
        Write-Host "✓ 测试通过" -ForegroundColor Green
        $PASSED_TESTS++
    } else {
        Write-Host "✗ 测试失败" -ForegroundColor Red
        Write-Host $result
        $FAILED_TESTS++
    }
    
    $TOTAL_TESTS++
    Write-Host ""
}

# 输出测试总结
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  测试总结" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "总测试文件: $TOTAL_TESTS"
Write-Host "通过: $PASSED_TESTS" -ForegroundColor Green
Write-Host "失败: $FAILED_TESTS" -ForegroundColor Red
Write-Host ""

if ($FAILED_TESTS -eq 0) {
    Write-Host "✓ 所有测试通过！" -ForegroundColor Green
    exit 0
} else {
    Write-Host "✗ 有 $FAILED_TESTS 个测试失败" -ForegroundColor Red
    exit 1
}

