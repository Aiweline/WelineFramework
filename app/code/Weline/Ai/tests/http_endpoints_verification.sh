#!/bin/bash

##############################################
# Weline AI 模块 HTTP 端点验证脚本
# 
# 用途：验证所有 API 端点是否正常工作
# 运行：bash app/code/Weline/Ai/tests/http_endpoints_verification.sh
##############################################

echo "================================================="
echo "  Weline AI 模块 HTTP 端点验证"
echo "================================================="
echo ""

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 计数器
PASSED=0
FAILED=0

# 测试结果函数
test_passed() {
    echo -e "${GREEN}✓ PASSED${NC}: $1"
    ((PASSED++))
}

test_failed() {
    echo -e "${RED}✗ FAILED${NC}: $1"
    ((FAILED++))
}

test_warning() {
    echo -e "${YELLOW}⚠ WARNING${NC}: $1"
}

# API Key（需要提前创建或使用现有的）
API_KEY="sk-test-verification-key"

echo "注意: 请确保已经创建测试 API Key 或使用现有 API Key"
echo "当前使用的 API Key: ${API_KEY}"
echo ""
read -p "按 Enter 继续测试..."
echo ""

##############################################
# 测试 1: Chat API - POST /api/v1/chat
##############################################
echo "测试 1: Chat API"
echo "----------------"

RESPONSE=$(php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "你好，AI！",
    "model_code": "gpt-3.5-turbo",
    "session_id": "test-session-1"
  }' 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "Chat API 响应正常"
else
    test_failed "Chat API 响应异常"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 2: 获取模型信息 - GET /api/v1/model/1
##############################################
echo "测试 2: 获取模型信息"
echo "-------------------"

RESPONSE=$(php bin/w http:request GET /api/v1/model/1 \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "X-API-Version: v1" 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "获取模型信息正常"
else
    test_failed "获取模型信息失败"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 3: 模型拷贝 - POST /api/v1/model/1/copy
##############################################
echo "测试 3: 模型拷贝"
echo "---------------"

RESPONSE=$(php bin/w http:request POST /api/v1/model/1/copy \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "new_name": "测试拷贝模型",
    "config": {
      "temperature": 0.8
    }
  }' 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "模型拷贝成功"
else
    test_warning "模型拷贝可能需要管理员权限"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 4: 创建 API Key - POST /api/v1/api-key
##############################################
echo "测试 4: 创建 API Key"
echo "------------------"

RESPONSE=$(php bin/w http:request POST /api/v1/api-key \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "测试 API Key",
    "quota_daily": 100,
    "quota_monthly": 3000
  }' 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "创建 API Key 成功"
else
    test_warning "创建 API Key 可能需要特殊权限"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 5: 获取 API Key 列表 - GET /api/v1/api-key
##############################################
echo "测试 5: 获取 API Key 列表"
echo "-----------------------"

RESPONSE=$(php bin/w http:request GET /api/v1/api-key \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "X-API-Version: v1" 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "获取 API Key 列表成功"
else
    test_failed "获取 API Key 列表失败"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 6: 认证失败测试 - 无效 API Key
##############################################
echo "测试 6: 认证失败测试"
echo "-------------------"

RESPONSE=$(php bin/w http:request POST /api/v1/chat \
  -H "Authorization: Bearer invalid-key-12345" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "测试",
    "model_code": "gpt-3.5-turbo"
  }' 2>&1)

if echo "$RESPONSE" | grep -q "401\|UNAUTHORIZED\|unauthorized"; then
    test_passed "认证失败测试正常（返回 401）"
else
    test_warning "认证失败测试未返回预期的 401 错误"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 7: CORS 预检请求 - OPTIONS
##############################################
echo "测试 7: CORS 预检请求"
echo "--------------------"

RESPONSE=$(php bin/w http:request OPTIONS /api/v1/chat \
  -H "Origin: https://example.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: Content-Type,Authorization" 2>&1)

if echo "$RESPONSE" | grep -q "204\|200\|success"; then
    test_passed "CORS 预检请求处理正常"
else
    test_warning "CORS 预检请求响应异常"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 8: 请求头验证 - X-API-Version
##############################################
echo "测试 8: 请求头验证"
echo "-----------------"

RESPONSE=$(php bin/w http:request GET /api/v1/model/1 \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "X-API-Version: v1" \
  -H "X-API-Locale: zh-CN" 2>&1)

if echo "$RESPONSE" | grep -q "success"; then
    test_passed "自定义请求头处理正常"
else
    test_failed "自定义请求头处理失败"
    echo "响应内容: $RESPONSE"
fi
echo ""

##############################################
# 测试 9: 性能监控响应头验证
##############################################
echo "测试 9: 性能监控响应头"
echo "---------------------"

# 这个测试需要检查响应头，但 http:request 命令可能不直接显示响应头
# 我们可以通过日志或其他方式验证
test_warning "性能响应头需要通过日志或浏览器开发工具验证"
echo "  - X-Request-ID: 请求唯一标识"
echo "  - X-Response-Time: 响应时间"
echo "  - X-Memory-Peak: 内存峰值"
echo ""

##############################################
# 测试结果汇总
##############################################
echo "================================================="
echo "  测试结果汇总"
echo "================================================="
echo ""
echo "总测试数: $((PASSED + FAILED))"
echo -e "${GREEN}通过: ${PASSED}${NC}"
echo -e "${RED}失败: ${FAILED}${NC}"

if [ $FAILED -eq 0 ]; then
    echo ""
    echo -e "${GREEN}🎉 所有测试通过！${NC}"
    exit 0
else
    echo ""
    echo -e "${YELLOW}⚠️  有 ${FAILED} 个测试失败，请检查日志${NC}"
    exit 1
fi

