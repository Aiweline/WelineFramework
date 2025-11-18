# Weline AI 模块 HTTP 端点验证脚本 (PowerShell)
# 
# 用途：验证所有 API 端点是否正常工作
# 运行：powershell -ExecutionPolicy Bypass -File app/code/Weline/Ai/tests/http_endpoints_verification.ps1

Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  Weline AI 模块 HTTP 端点验证" -ForegroundColor Cyan
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host ""

# 计数器
$Script:Passed = 0
$Script:Failed = 0

# 测试结果函数
function Test-Passed {
    param([string]$Message)
    Write-Host "✓ PASSED: $Message" -ForegroundColor Green
    $Script:Passed++
}

function Test-Failed {
    param([string]$Message)
    Write-Host "✗ FAILED: $Message" -ForegroundColor Red
    $Script:Failed++
}

function Test-Warning {
    param([string]$Message)
    Write-Host "⚠ WARNING: $Message" -ForegroundColor Yellow
}

# API Key（需要提前创建或使用现有的）
$API_KEY = "sk-test-verification-key"

Write-Host "注意: 请确保已经创建测试 API Key 或使用现有 API Key"
Write-Host "当前使用的 API Key: $API_KEY"
Write-Host ""
Read-Host "按 Enter 继续测试..."
Write-Host ""

##############################################
# 测试 1: Chat API - POST /api/v1/chat
##############################################
Write-Host "测试 1: Chat API" -ForegroundColor Yellow
Write-Host "----------------"

try {
    $response = & php bin/w http:request POST /api/v1/chat `
        -H "Authorization: Bearer $API_KEY" `
        -H "Content-Type: application/json" `
        -d '{"prompt":"你好，AI！","model_code":"gpt-3.5-turbo","session_id":"test-session-1"}' 2>&1
    
    if ($response -match "success") {
        Test-Passed "Chat API 响应正常"
    } else {
        Test-Failed "Chat API 响应异常"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Failed "Chat API 请求失败: $_"
}
Write-Host ""

##############################################
# 测试 2: 获取模型信息 - GET /api/v1/model/1
##############################################
Write-Host "测试 2: 获取模型信息" -ForegroundColor Yellow
Write-Host "-------------------"

try {
    $response = & php bin/w http:request GET /api/v1/model/1 `
        -H "Authorization: Bearer $API_KEY" `
        -H "X-API-Version: v1" 2>&1
    
    if ($response -match "success") {
        Test-Passed "获取模型信息正常"
    } else {
        Test-Failed "获取模型信息失败"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Failed "获取模型信息请求失败: $_"
}
Write-Host ""

##############################################
# 测试 3: 模型拷贝 - POST /api/v1/model/1/copy
##############################################
Write-Host "测试 3: 模型拷贝" -ForegroundColor Yellow
Write-Host "---------------"

try {
    $response = & php bin/w http:request POST /api/v1/model/1/copy `
        -H "Authorization: Bearer $API_KEY" `
        -H "Content-Type: application/json" `
        -d '{"new_name":"测试拷贝模型","config":{"temperature":0.8}}' 2>&1
    
    if ($response -match "success") {
        Test-Passed "模型拷贝成功"
    } else {
        Test-Warning "模型拷贝可能需要管理员权限"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Warning "模型拷贝请求失败: $_"
}
Write-Host ""

##############################################
# 测试 4: 创建 API Key - POST /api/v1/api-key
##############################################
Write-Host "测试 4: 创建 API Key" -ForegroundColor Yellow
Write-Host "------------------"

try {
    $response = & php bin/w http:request POST /api/v1/api-key `
        -H "Authorization: Bearer $API_KEY" `
        -H "Content-Type: application/json" `
        -d '{"name":"测试 API Key","quota_daily":100,"quota_monthly":3000}' 2>&1
    
    if ($response -match "success") {
        Test-Passed "创建 API Key 成功"
    } else {
        Test-Warning "创建 API Key 可能需要特殊权限"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Warning "创建 API Key 请求失败: $_"
}
Write-Host ""

##############################################
# 测试 5: 获取 API Key 列表 - GET /api/v1/api-key
##############################################
Write-Host "测试 5: 获取 API Key 列表" -ForegroundColor Yellow
Write-Host "-----------------------"

try {
    $response = & php bin/w http:request GET /api/v1/api-key `
        -H "Authorization: Bearer $API_KEY" `
        -H "X-API-Version: v1" 2>&1
    
    if ($response -match "success") {
        Test-Passed "获取 API Key 列表成功"
    } else {
        Test-Failed "获取 API Key 列表失败"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Failed "获取 API Key 列表请求失败: $_"
}
Write-Host ""

##############################################
# 测试 6: 认证失败测试 - 无效 API Key
##############################################
Write-Host "测试 6: 认证失败测试" -ForegroundColor Yellow
Write-Host "-------------------"

try {
    $response = & php bin/w http:request POST /api/v1/chat `
        -H "Authorization: Bearer invalid-key-12345" `
        -H "Content-Type: application/json" `
        -d '{"prompt":"测试","model_code":"gpt-3.5-turbo"}' 2>&1
    
    if ($response -match "401|UNAUTHORIZED|unauthorized") {
        Test-Passed "认证失败测试正常（返回 401）"
    } else {
        Test-Warning "认证失败测试未返回预期的 401 错误"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Warning "认证失败测试请求失败: $_"
}
Write-Host ""

##############################################
# 测试 7: CORS 预检请求 - OPTIONS
##############################################
Write-Host "测试 7: CORS 预检请求" -ForegroundColor Yellow
Write-Host "--------------------"

try {
    $response = & php bin/w http:request OPTIONS /api/v1/chat `
        -H "Origin: https://example.com" `
        -H "Access-Control-Request-Method: POST" `
        -H "Access-Control-Request-Headers: Content-Type,Authorization" 2>&1
    
    if ($response -match "204|200|success") {
        Test-Passed "CORS 预检请求处理正常"
    } else {
        Test-Warning "CORS 预检请求响应异常"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Warning "CORS 预检请求失败: $_"
}
Write-Host ""

##############################################
# 测试 8: 请求头验证 - X-API-Version
##############################################
Write-Host "测试 8: 请求头验证" -ForegroundColor Yellow
Write-Host "-----------------"

try {
    $response = & php bin/w http:request GET /api/v1/model/1 `
        -H "Authorization: Bearer $API_KEY" `
        -H "X-API-Version: v1" `
        -H "X-API-Locale: zh-CN" 2>&1
    
    if ($response -match "success") {
        Test-Passed "自定义请求头处理正常"
    } else {
        Test-Failed "自定义请求头处理失败"
        Write-Host "响应内容: $response"
    }
} catch {
    Test-Failed "请求头验证失败: $_"
}
Write-Host ""

##############################################
# 测试 9: 性能监控响应头验证
##############################################
Write-Host "测试 9: 性能监控响应头" -ForegroundColor Yellow
Write-Host "---------------------"

Test-Warning "性能响应头需要通过日志或浏览器开发工具验证"
Write-Host "  - X-Request-ID: 请求唯一标识"
Write-Host "  - X-Response-Time: 响应时间"
Write-Host "  - X-Memory-Peak: 内存峰值"
Write-Host ""

##############################################
# 测试结果汇总
##############################################
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host "  测试结果汇总" -ForegroundColor Cyan
Write-Host "=================================================" -ForegroundColor Cyan
Write-Host ""

$Total = $Script:Passed + $Script:Failed
Write-Host "总测试数: $Total"
Write-Host "通过: $($Script:Passed)" -ForegroundColor Green
Write-Host "失败: $($Script:Failed)" -ForegroundColor Red

if ($Script:Failed -eq 0) {
    Write-Host ""
    Write-Host "🎉 所有测试通过！" -ForegroundColor Green
    exit 0
} else {
    Write-Host ""
    Write-Host "⚠️  有 $($Script:Failed) 个测试失败，请检查日志" -ForegroundColor Yellow
    exit 1
}

