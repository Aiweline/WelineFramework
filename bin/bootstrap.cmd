@echo off
setlocal EnableExtensions
REM Windows CMD 一键安装（无需 bash）。尾部参数与 bash 路径相同，例如：-b dev -y
REM 远程一行：
REM   curl.exe -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.cmd -o %TEMP%\weline-bootstrap.cmd & %TEMP%\weline-bootstrap.cmd -b dev -y

set "BOOTSTRAP_PS1=https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1"
set "SCRIPT=%TEMP%\weline-bootstrap.ps1"
set "LOCAL_PS1=%~dp0bootstrap.ps1"

if exist "%LOCAL_PS1%" (
  powershell -NoProfile -ExecutionPolicy Bypass -File "%LOCAL_PS1%" %*
  exit /b %ERRORLEVEL%
)

where curl.exe >nul 2>&1
if %ERRORLEVEL%==0 (
  curl.exe -fsSL "%BOOTSTRAP_PS1%" -o "%SCRIPT%"
) else (
  powershell -NoProfile -Command "Invoke-WebRequest -Uri '%BOOTSTRAP_PS1%' -OutFile '%SCRIPT%' -UseBasicParsing"
)

if not exist "%SCRIPT%" (
  echo ERROR: 无法下载 bootstrap.ps1，请检查网络。>&2
  exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" %*
set "EC=%ERRORLEVEL%"
del "%SCRIPT%" 2>nul
exit /b %EC%
