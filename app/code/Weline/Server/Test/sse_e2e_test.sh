#!/bin/bash

echo "=== SSE 短轮询 E2E 测试 ==="
echo ""

# 使用测试端点（无需认证）
SSE_URL="https://weline-p11005ce4.local/server/test/sse-test/test"
STATIC_URL="https://weline-p11005ce4.local/"

# 测试 1：验证 SSE 短轮询机制
echo "测试 1：验证 SSE 短轮询机制"
echo "----------------------------------------"
SSE_START=$(date +%s)
curl -k "$SSE_URL" \
  -H "Accept: text/event-stream" \
  -N -s --max-time 5 > /dev/null 2>&1
SSE_END=$(date +%s)
SSE_TIME=$((SSE_END - SSE_START))

echo "  - SSE 连接耗时: ${SSE_TIME} 秒"
echo "  - 预期: < 5 秒"
if [ $SSE_TIME -lt 5 ]; then
  echo "  - 结果: ✅ 通过"
  TEST1_PASSED=1
else
  echo "  - 结果: ❌ 失败"
  TEST1_PASSED=0
fi
echo ""

# 测试 2：验证 SSE 不阻塞其他请求（只测试 2 个并发，因为只有 2 个 Worker）
echo "测试 2：验证 SSE 不阻塞其他请求"
echo "----------------------------------------"
echo "  启动 SSE 连接（后台）..."
curl -k "$SSE_URL" \
  -H "Accept: text/event-stream" \
  -N -s --max-time 5 > /dev/null 2>&1 &
SSE_PID=$!

# 等待 SSE 连接建立
sleep 1

echo "  同时加载 2 个静态资源（2 Worker 限制）..."
SUCCESS_COUNT=0
FAIL_COUNT=0

for i in {1..2}; do
  HTTP_CODE=$(curl -k "$STATIC_URL" -o /dev/null -s -w "%{http_code}" --max-time 3 2>&1)
  if [ "$HTTP_CODE" = "200" ]; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
    echo "    资源 $i: ✅ 成功"
  else
    FAIL_COUNT=$((FAIL_COUNT + 1))
    echo "    资源 $i: ❌ 失败 (HTTP $HTTP_CODE)"
  fi
done

# 等待 SSE 完成
wait $SSE_PID 2>/dev/null || true

echo "  - 成功: $SUCCESS_COUNT / 2"
echo "  - 失败: $FAIL_COUNT / 2"
echo "  - 预期: 至少 1 个成功（证明 SSE 不完全阻塞）"
if [ $SUCCESS_COUNT -ge 1 ]; then
  echo "  - 结果: ✅ 通过"
  TEST2_PASSED=1
else
  echo "  - 结果: ❌ 失败"
  TEST2_PASSED=0
fi
echo ""

# 测试 3：验证静态资源加载性能
echo "测试 3：验证静态资源加载性能"
echo "----------------------------------------"
SUCCESS_COUNT=0
TOTAL_TIME=0

for i in {1..3}; do
  START=$(date +%s.%N 2>/dev/null || date +%s)
  HTTP_CODE=$(curl -k "$STATIC_URL" -o /dev/null -s -w "%{http_code}" --max-time 3 2>&1)
  END=$(date +%s.%N 2>/dev/null || date +%s)

  if [ "$HTTP_CODE" = "200" ]; then
    SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
    TIME=$(echo "$END - $START" | bc 2>/dev/null || echo "1")
    TOTAL_TIME=$(echo "$TOTAL_TIME + $TIME" | bc 2>/dev/null || echo "$TOTAL_TIME")
  fi
done

if [ $SUCCESS_COUNT -gt 0 ]; then
  AVG_TIME=$(echo "scale=2; $TOTAL_TIME / $SUCCESS_COUNT" | bc 2>/dev/null || echo "0")
else
  AVG_TIME=0
fi

echo "  - 成功: $SUCCESS_COUNT / 3"
echo "  - 平均耗时: ${AVG_TIME} 秒"
echo "  - 预期: 成功率 100%, 平均耗时 < 2 秒"

if [ $SUCCESS_COUNT -eq 3 ]; then
  if [ "$AVG_TIME" = "0" ] || [ $(echo "$AVG_TIME < 2" | bc 2>/dev/null || echo "0") -eq 1 ]; then
    echo "  - 结果: ✅ 通过"
    TEST3_PASSED=1
  else
    echo "  - 结果: ❌ 失败（耗时过长）"
    TEST3_PASSED=0
  fi
else
  echo "  - 结果: ❌ 失败（成功率不足）"
  TEST3_PASSED=0
fi
echo ""

# 总体结论
echo "========================================"
echo "总体结论"
echo "========================================"
if [ $TEST1_PASSED -eq 1 ] && [ $TEST2_PASSED -eq 1 ] && [ $TEST3_PASSED -eq 1 ]; then
  echo "✅ 所有测试通过"
  echo ""
  echo "SSE 短轮询工作正常："
  echo "  - SSE 连接在 ${SSE_TIME} 秒内完成"
  echo "  - SSE 不阻塞其他请求"
  echo "  - 静态资源加载性能良好（平均 ${AVG_TIME} 秒）"
  echo ""
  exit 0
else
  echo "❌ 部分测试失败"
  echo ""
  echo "失败的测试："
  [ $TEST1_PASSED -eq 0 ] && echo "  - 测试 1: SSE 短轮询机制"
  [ $TEST2_PASSED -eq 0 ] && echo "  - 测试 2: SSE 不阻塞其他请求"
  [ $TEST3_PASSED -eq 0 ] && echo "  - 测试 3: 静态资源加载性能"
  echo ""
  exit 1
fi
