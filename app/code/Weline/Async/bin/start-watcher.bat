@echo off
REM 启动watcher的批处理脚本
REM 参数：%1 = node.exe路径, %2 = watcher.js路径, %3 = 配置文件路径, %4 = 日志文件路径

REM 移除参数中的引号（如果存在）
set NODE_PATH=%~1
set WATCHER_JS=%~2
set CONFIG_FILE=%~3
set LOG_FILE=%~4

REM 后台启动node进程，重定向输出到日志文件
REM 使用引号包裹路径，确保包含空格的路径能正确处理
start /B "" "%NODE_PATH%" "%WATCHER_JS%" "%CONFIG_FILE%" > "%LOG_FILE%" 2>&1
