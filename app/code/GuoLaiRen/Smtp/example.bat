@echo off
REM GuoLaiRen SMTP 模块使用示例脚本 (Windows版本)
REM 本脚本展示了如何使用 mail:send 和 mail:test 命令

echo ==========================================
echo GuoLaiRen SMTP 模块使用示例 (Windows)
echo ==========================================
echo.

echo 注意：运行前请先配置 app\code\GuoLaiRen\Smtp\etc\env.php
echo.

REM 示例1：测试SMTP配置
echo 示例1: 测试SMTP配置
echo 命令：php bin\w mail:test --to=test@example.com
echo.
REM php bin\w mail:test --to=test@example.com
echo ---
echo.

REM 示例2：发送简单的纯文本邮件
echo 示例2: 发送简单的纯文本邮件
echo 命令：php bin\w mail:send --to=user@example.com --subject="测试邮件" --body="这是一封测试邮件"
echo.
REM php bin\w mail:send --to=user@example.com --subject="测试邮件" --body="这是一封测试邮件"
echo ---
echo.

REM 示例3：发送HTML格式邮件
echo 示例3: 发送HTML格式邮件
echo 命令：php bin\w mail:send --to=user@example.com --subject="HTML邮件" --body="<h1>欢迎</h1><p>这是HTML邮件</p>" --html=1
echo.
REM php bin\w mail:send --to=user@example.com --subject="HTML邮件" --body="<h1>欢迎</h1><p>这是HTML邮件</p>" --html=1
echo ---
echo.

REM 示例4：发送带收件人姓名的邮件
echo 示例4: 发送带收件人姓名的邮件
echo 命令：php bin\w mail:send --to=user@example.com --to-name="张三" --subject="问候" --body="您好，张三！"
echo.
REM php bin\w mail:send --to=user@example.com --to-name="张三" --subject="问候" --body="您好，张三！"
echo ---
echo.

REM 示例5：发送带抄送的邮件
echo 示例5: 发送带抄送的邮件
echo 命令：php bin\w mail:send --to=user@example.com --subject="会议通知" --body="会议内容" --cc=cc1@example.com,cc2@example.com
echo.
REM php bin\w mail:send --to=user@example.com --subject="会议通知" --body="会议内容" --cc=cc1@example.com,cc2@example.com
echo ---
echo.

REM 示例6：发送带密送的邮件
echo 示例6: 发送带密送的邮件
echo 命令：php bin\w mail:send --to=user@example.com --subject="私密通知" --body="通知内容" --bcc=bcc@example.com
echo.
REM php bin\w mail:send --to=user@example.com --subject="私密通知" --body="通知内容" --bcc=bcc@example.com
echo ---
echo.

REM 示例7：发送带附件的邮件
echo 示例7: 发送带附件的邮件
echo 命令：php bin\w mail:send --to=user@example.com --subject="报告" --body="请查收附件" --attachment=C:\path\to\report.pdf
echo.
REM php bin\w mail:send --to=user@example.com --subject="报告" --body="请查收附件" --attachment=C:\path\to\report.pdf
echo ---
echo.

REM 示例8：完整示例（包含所有参数）
echo 示例8: 完整示例（包含多个参数）
echo 命令：
echo php bin\w mail:send ^
echo   --to=user@example.com ^
echo   --to-name="李四" ^
echo   --subject="月度报告" ^
echo   --body="<h1>月度报告</h1><p>请查收本月的业绩报告。</p>" ^
echo   --html=1 ^
echo   --cc=manager@example.com ^
echo   --attachment=C:\path\to\monthly-report.pdf
echo.
REM 取消注释下面的代码来执行
REM php bin\w mail:send --to=user@example.com --to-name="李四" --subject="月度报告" --body="<h1>月度报告</h1><p>请查收本月的业绩报告。</p>" --html=1 --cc=manager@example.com --attachment=C:\path\to\monthly-report.pdf

echo ---
echo.

echo 提示：
echo 1. 将上述示例中的邮箱地址替换为实际地址
echo 2. 取消注释相应的命令行即可执行
echo 3. 查看帮助：php bin\w mail:send --help
echo 4. 测试帮助：php bin\w mail:test --help
echo.

echo ==========================================
echo 示例脚本结束
echo ==========================================
pause

