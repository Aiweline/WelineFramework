# 文档API HTTP测试脚本 (PowerShell版本)
# 使用方法：.\app\code\Weline\DeveloperWorkspace\Test\Http\test_document_api.ps1

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  开发者文档管理系统 API 测试" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# 检查服务器是否运行
Write-Host "[1/6] 检查服务器状态..." -ForegroundColor Yellow
$serverStatus = php bin/w server:status 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ 服务器未运行，正在启动..." -ForegroundColor Red
    php bin/w server:start -b
    Start-Sleep -Seconds 8
    Write-Host "✅ 服务器已启动" -ForegroundColor Green
}
else {
    Write-Host "✅ 服务器正在运行" -ForegroundColor Green
}
Write-Host ""

# 测试1：获取模块列表
Write-Host "[2/6] 测试：获取模块列表" -ForegroundColor Yellow
Write-Host "命令：php bin/w http:request GET /api/dev/document/modules" -ForegroundColor Gray
php bin/w http:request GET /api/dev/document/modules
Write-Host ""

# 测试2：搜索所有文档
Write-Host "[3/6] 测试：搜索所有文档" -ForegroundColor Yellow
Write-Host "命令：php bin/w http:request GET '/api/dev/document/search'" -ForegroundColor Gray
php bin/w http:request GET "/api/dev/document/search"
Write-Host ""

# 测试3：关键词搜索
Write-Host "[4/6] 测试：关键词搜索" -ForegroundColor Yellow
Write-Host "命令：php bin/w http:request GET '/api/dev/document/search?keyword=API'" -ForegroundColor Gray
php bin/w http:request GET "/api/dev/document/search?keyword=API"
Write-Host ""

# 测试5：按模块过滤
Write-Host "[5/6] 测试：按模块过滤" -ForegroundColor Yellow
Write-Host "命令：php bin/w http:request GET '/api/dev/document/search?module=Weline_Framework'" -ForegroundColor Gray
php bin/w http:request GET "/api/dev/document/search?module=Weline_Framework"
Write-Host ""

# 测试6：获取目录树
Write-Host "[6/6] 测试：获取目录树" -ForegroundColor Yellow
Write-Host "命令：php bin/w http:request GET /api/dev/document/catalogs" -ForegroundColor Gray
php bin/w http:request GET /api/dev/document/catalogs
Write-Host ""

Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  ✅ 所有API测试完成" -ForegroundColor Green
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "如需测试文档详情API，请先运行文档扫描：" -ForegroundColor Yellow
Write-Host "  php bin/w doc:import" -ForegroundColor White
Write-Host "然后执行：" -ForegroundColor Yellow
Write-Host "  php bin/w http:request GET '/api/dev/document/detail?id=1'" -ForegroundColor White

