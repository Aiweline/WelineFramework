#!/bin/bash

# GuoLaiRen SMTP 模块使用示例脚本
# 本脚本展示了如何使用 mail:send 和 mail:test 命令

echo "=========================================="
echo "GuoLaiRen SMTP 模块使用示例"
echo "=========================================="
echo ""

# 设置颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}注意：运行前请先配置 app/code/GuoLaiRen/Smtp/etc/env.php${NC}"
echo ""

# 示例1：测试SMTP配置
echo -e "${GREEN}示例1: 测试SMTP配置${NC}"
echo "命令：php bin/w mail:test --to=test@example.com"
echo ""
# php bin/w mail:test --to=test@example.com
echo "---"
echo ""

# 示例2：发送简单的纯文本邮件
echo -e "${GREEN}示例2: 发送简单的纯文本邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --subject=\"测试邮件\" --body=\"这是一封测试邮件\""
echo ""
# php bin/w mail:send --to=user@example.com --subject="测试邮件" --body="这是一封测试邮件"
echo "---"
echo ""

# 示例3：发送HTML格式邮件
echo -e "${GREEN}示例3: 发送HTML格式邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --subject=\"HTML邮件\" --body=\"<h1>欢迎</h1><p>这是HTML邮件</p>\" --html=1"
echo ""
# php bin/w mail:send --to=user@example.com --subject="HTML邮件" --body="<h1>欢迎</h1><p>这是HTML邮件</p>" --html=1
echo "---"
echo ""

# 示例4：发送带收件人姓名的邮件
echo -e "${GREEN}示例4: 发送带收件人姓名的邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --to-name=\"张三\" --subject=\"问候\" --body=\"您好，张三！\""
echo ""
# php bin/w mail:send --to=user@example.com --to-name="张三" --subject="问候" --body="您好，张三！"
echo "---"
echo ""

# 示例5：发送带抄送的邮件
echo -e "${GREEN}示例5: 发送带抄送的邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --subject=\"会议通知\" --body=\"会议内容\" --cc=cc1@example.com,cc2@example.com"
echo ""
# php bin/w mail:send --to=user@example.com --subject="会议通知" --body="会议内容" --cc=cc1@example.com,cc2@example.com
echo "---"
echo ""

# 示例6：发送带密送的邮件
echo -e "${GREEN}示例6: 发送带密送的邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --subject=\"私密通知\" --body=\"通知内容\" --bcc=bcc@example.com"
echo ""
# php bin/w mail:send --to=user@example.com --subject="私密通知" --body="通知内容" --bcc=bcc@example.com
echo "---"
echo ""

# 示例7：发送带附件的邮件
echo -e "${GREEN}示例7: 发送带附件的邮件${NC}"
echo "命令：php bin/w mail:send --to=user@example.com --subject=\"报告\" --body=\"请查收附件\" --attachment=/path/to/report.pdf"
echo ""
# php bin/w mail:send --to=user@example.com --subject="报告" --body="请查收附件" --attachment=/path/to/report.pdf
echo "---"
echo ""

# 示例8：完整示例（包含所有参数）
echo -e "${GREEN}示例8: 完整示例（包含多个参数）${NC}"
cat << 'EOF'
命令：
php bin/w mail:send \
  --to=user@example.com \
  --to-name="李四" \
  --subject="月度报告" \
  --body="<h1>月度报告</h1><p>请查收本月的业绩报告。</p>" \
  --html=1 \
  --cc=manager@example.com \
  --attachment=/path/to/monthly-report.pdf
EOF
echo ""
# 取消注释下面的代码来执行
# php bin/w mail:send \
#   --to=user@example.com \
#   --to-name="李四" \
#   --subject="月度报告" \
#   --body="<h1>月度报告</h1><p>请查收本月的业绩报告。</p>" \
#   --html=1 \
#   --cc=manager@example.com \
#   --attachment=/path/to/monthly-report.pdf

echo "---"
echo ""

echo -e "${YELLOW}提示：${NC}"
echo "1. 将上述示例中的邮箱地址替换为实际地址"
echo "2. 取消注释相应的命令行即可执行"
echo "3. 查看帮助：php bin/w mail:send --help"
echo "4. 测试帮助：php bin/w mail:test --help"
echo ""

echo "=========================================="
echo "示例脚本结束"
echo "=========================================="

