#!/bin/bash

# 运行新增的单元测试
# 用途：验证最近改进的功能（collect() JSON响应和 AiModel 新字段）

echo "========================================"
echo "  运行 Weline AI 模块新增单元测试"
echo "========================================"
echo ""

# 颜色定义
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 测试计数器
TOTAL_TESTS=0
PASSED_TESTS=0
FAILED_TESTS=0

# 测试文件列表
TEST_FILES=(
    "app/code/Weline/Ai/tests/unit/test_model_controller_json_response.php"
    "app/code/Weline/Ai/tests/unit/test_ai_model_new_fields.php"
)

echo -e "${YELLOW}测试文件:${NC}"
for file in "${TEST_FILES[@]}"; do
    echo "  - $file"
done
echo ""

# 运行每个测试文件
for test_file in "${TEST_FILES[@]}"; do
    echo -e "${YELLOW}正在运行: $(basename $test_file)${NC}"
    echo "----------------------------------------"
    
    # 运行测试
    if php bin/w phpunit:run "$test_file"; then
        echo -e "${GREEN}✓ 测试通过${NC}"
        ((PASSED_TESTS++))
    else
        echo -e "${RED}✗ 测试失败${NC}"
        ((FAILED_TESTS++))
    fi
    
    ((TOTAL_TESTS++))
    echo ""
done

# 输出测试总结
echo "========================================"
echo "  测试总结"
echo "========================================"
echo -e "总测试文件: $TOTAL_TESTS"
echo -e "${GREEN}通过: $PASSED_TESTS${NC}"
echo -e "${RED}失败: $FAILED_TESTS${NC}"
echo ""

if [ $FAILED_TESTS -eq 0 ]; then
    echo -e "${GREEN}✓ 所有测试通过！${NC}"
    exit 0
else
    echo -e "${RED}✗ 有 $FAILED_TESTS 个测试失败${NC}"
    exit 1
fi

