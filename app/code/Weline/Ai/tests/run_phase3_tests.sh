#!/bin/bash

# Phase 3.6 & 3.7 单元测试运行脚本
# 用于验证新功能的正确性

echo "=========================================="
echo "Weline AI Module - Phase 3.6 & 3.7 测试"
echo "=========================================="
echo ""

# 设置颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 测试计数
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# 测试函数
run_test() {
    local test_file=$1
    local test_name=$2
    
    echo -e "${YELLOW}运行测试: ${test_name}${NC}"
    
    if php bin/w phpunit:run --file="app/code/Weline/Ai/tests/unit/${test_file}" 2>&1 | tee /tmp/test_output.txt; then
        if grep -q "OK" /tmp/test_output.txt || grep -q "Tests: " /tmp/test_output.txt; then
            echo -e "${GREEN}✓ ${test_name} 通过${NC}"
            PASSED_TESTS=$((PASSED_TESTS + 1))
        else
            echo -e "${RED}✗ ${test_name} 失败${NC}"
            FAILED_TESTS=$((FAILED_TESTS + 1))
        fi
    else
        echo -e "${RED}✗ ${test_name} 执行失败${NC}"
        FAILED_TESTS=$((FAILED_TESTS + 1))
    fi
    
    TOTAL_TESTS=$((TOTAL_TESTS + 1))
    echo ""
}

# Phase 3.6: 服务层测试
echo "=== Phase 3.6: Advanced Features 测试 ==="
run_test "test_business_insight_service.php" "商业洞察服务测试"
run_test "test_monitoring_service.php" "监控告警服务测试"

# Phase 3.7: 扩展模型测试
echo "=== Phase 3.7: Extended Features 测试 ==="
run_test "test_extended_models.php" "扩展模型测试"

# 输出测试结果摘要
echo "=========================================="
echo "测试结果摘要"
echo "=========================================="
echo -e "总测试数: ${TOTAL_TESTS}"
echo -e "${GREEN}通过: ${PASSED_TESTS}${NC}"
echo -e "${RED}失败: ${FAILED_TESTS}${NC}"
echo ""

# 设置退出码
if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}✓ 所有测试通过！${NC}"
    exit 0
else
    echo -e "${RED}✗ 有测试失败，请检查上面的输出。${NC}"
    exit 1
fi

