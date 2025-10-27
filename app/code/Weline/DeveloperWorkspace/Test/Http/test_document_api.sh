#!/bin/bash
# 文档API HTTP测试脚本
# 使用方法：bash app/code/Weline/DeveloperWorkspace/Test/Http/test_document_api.sh

echo "========================================="
echo "  开发者文档管理系统 API 测试"
echo "========================================="
echo ""

# 检查服务器是否运行
echo "[1/6] 检查服务器状态..."
php bin/w server:status > /dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "❌ 服务器未运行，正在启动..."
    php bin/w server:start -b
    sleep 8
    echo "✅ 服务器已启动"
else
    echo "✅ 服务器正在运行"
fi
echo ""

# 测试1：获取模块列表
echo "[2/6] 测试：获取模块列表"
echo "命令：php bin/w http:request GET /api/dev/document/modules"
php bin/w http:request GET /api/dev/document/modules
echo ""

# 测试2：搜索所有文档
echo "[3/6] 测试：搜索所有文档"
echo "命令：php bin/w http:request GET '/api/dev/document/search?page=1&size=10'"
php bin/w http:request GET "/api/dev/document/search?page=1&size=10"
echo ""

# 测试3：关键词搜索
echo "[4/6] 测试：关键词搜索"
echo "命令：php bin/w http:request GET '/api/dev/document/search?keyword=API'"
php bin/w http:request GET "/api/dev/document/search?keyword=API"
echo ""

# 测试4：按模块过滤
echo "[5/6] 测试：按模块过滤"
echo "命令：php bin/w http:request GET '/api/dev/document/search?module=Weline_Framework'"
php bin/w http:request GET "/api/dev/document/search?module=Weline_Framework"
echo ""

# 测试5：获取目录树
echo "[6/6] 测试：获取目录树"
echo "命令：php bin/w http:request GET /api/dev/document/catalogs"
php bin/w http:request GET /api/dev/document/catalogs
echo ""

echo "========================================="
echo "  ✅ 所有API测试完成"
echo "========================================="
echo ""
echo "如需测试文档详情API，请先运行文档扫描："
echo "  php bin/w doc:import"
echo "然后执行："
echo "  php bin/w http:request GET '/api/dev/document/detail?id=1'"

