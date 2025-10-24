# Phase 3.6 & 3.7 单元测试运行脚本 (PowerShell)
# 用于验证新功能的正确性

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Weline AI Module - Phase 3.6 & 3.7 测试" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

# 测试计数
$TotalTests = 0
$PassedTests = 0
$FailedTests = 0

# 测试函数
function Run-Test {
    param(
        [string]$TestFile,
        [string]$TestName
    )
    
    Write-Host "运行测试: $TestName" -ForegroundColor Yellow
    
    $TotalTests++
    
    try {
        $output = php bin/w phpunit:run --file="app/code/Weline/Ai/tests/unit/$TestFile" 2>&1 | Out-String
        
        if ($output -match "OK" -or $output -match "Tests: ") {
            Write-Host "✓ $TestName 通过" -ForegroundColor Green
            $PassedTests++
        } else {
            Write-Host "✗ $TestName 失败" -ForegroundColor Red
            Write-Host $output -ForegroundColor Gray
            $FailedTests++
        }
    } catch {
        Write-Host "✗ $TestName 执行失败" -ForegroundColor Red
        Write-Host $_.Exception.Message -ForegroundColor Red
        $FailedTests++
    }
    
    Write-Host ""
}

# Phase 3.6: 服务层测试
Write-Host "=== Phase 3.6: Advanced Features 测试 ===" -ForegroundColor Cyan
Run-Test "test_business_insight_service.php" "商业洞察服务测试"
Run-Test "test_monitoring_service.php" "监控告警服务测试"

# Phase 3.7: 扩展模型测试
Write-Host "=== Phase 3.7: Extended Features 测试 ===" -ForegroundColor Cyan
Run-Test "test_extended_models.php" "扩展模型测试"

# 输出测试结果摘要
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "测试结果摘要" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "总测试数: $TotalTests"
Write-Host "通过: $PassedTests" -ForegroundColor Green
Write-Host "失败: $FailedTests" -ForegroundColor Red
Write-Host ""

# 设置退出码
if ($FailedTests -eq 0) {
    Write-Host "✓ 所有测试通过！" -ForegroundColor Green
    exit 0
} else {
    Write-Host "✗ 有测试失败，请检查上面的输出。" -ForegroundColor Red
    exit 1
}

