# 部署 Widget Page 修复到线上并测试
# 用法：在 PowerShell 中执行 .\deploy-widget-page-fix.ps1
# 若 SSH 需密码，会提示输入；建议配置 www@weline 免密后再运行

$ErrorActionPreference = "Stop"
$ProjectRoot = "e:\WelineFramework\DEV-workspace"
$Remote = "www@weline"
$RemotePath = "/www/wwwroot/test.aiweline.com"

Write-Host "1. 上传 Page.php ..." -ForegroundColor Cyan
scp "$ProjectRoot\app\code\Weline\Widget\Model\Page.php" "${Remote}:${RemotePath}/app/code/Weline/Widget/Model/Page.php"
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Write-Host "2. 线上执行 setup:upgrade ..." -ForegroundColor Cyan
$out = ssh $Remote "cd $RemotePath && php bin/w setup:upgrade 2>&1"
$exit = $LASTEXITCODE
Write-Host $out
if ($exit -ne 0) { exit $exit }
if ($out -match 'Schema.*fail|create table.*fail|column.*does not exist') {
    Write-Host "Upgrade error detected, check output above." -ForegroundColor Red
    exit 1
}

Write-Host "Done. No schema/table errors above = deploy and upgrade OK." -ForegroundColor Green
