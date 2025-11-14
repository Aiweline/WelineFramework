#!/bin/bash

echo "============================================"
echo "Weline DeveloperWorkspace 模板文件验证"
echo "============================================"

TEMPLATE_FILE="app/code/Weline/DeveloperWorkspace/view/templates/Admin/Document/Catalog/index.phtml"

if [ ! -f "$TEMPLATE_FILE" ]; then
    echo "❌ 模板文件不存在: $TEMPLATE_FILE"
    exit 1
fi

echo "📁 检查模板文件: $TEMPLATE_FILE"
echo ""

echo "=== 1. 检查 jstree 原生处理函数 ==="
echo ""

# 检查 jstree 原生处理函数
functions=("handleEditNode" "handleDeleteNode" "handleAddChildNode" "performDelete" "showNotification")

for func in "${functions[@]}"; do
    if grep -q "function $func" "$TEMPLATE_FILE"; then
        echo "✅ $func 函数存在"
        # 显示函数定义的前几行
        grep -A 3 "function $func" "$TEMPLATE_FILE" | head -4
    else
        echo "❌ $func 函数不存在"
    fi
    echo ""
done

echo "=== 2. 检查右键菜单调用 ==="
echo ""

# 检查右键菜单中的 jstree 原生调用
if grep -q "handleEditNode(data\.reference)" "$TEMPLATE_FILE"; then
    echo "✅ 编辑菜单调用 handleEditNode 存在"
else
    echo "❌ 编辑菜单调用 handleEditNode 不存在"
fi

if grep -q "handleDeleteNode(data\.reference)" "$TEMPLATE_FILE"; then
    echo "✅ 删除菜单调用 handleDeleteNode 存在"
else
    echo "❌ 删除菜单调用 handleDeleteNode 不存在"
fi

if grep -q "handleAddChildNode(data\.reference)" "$TEMPLATE_FILE"; then
    echo "✅ 添加子分类菜单调用 handleAddChildNode 存在"
else
    echo "❌ 添加子分类菜单调用 handleAddChildNode 不存在"
fi

echo ""

echo "=== 3. 检查清理的旧代码 ==="
echo ""

# 检查是否清理了旧的全局函数依赖
old_functions=("window\.editCatalog" "window\.deleteCatalogByWDelete" "window\.saveCatalog" "window\.showAddCatalogModal")

for func in "${old_functions[@]}"; do
    if grep -q "$func" "$TEMPLATE_FILE"; then
        echo "⚠️  仍然存在旧的函数引用: $func"
    else
        echo "✅ 已清理旧的函数引用: $func"
    fi
done

echo ""

echo "=== 4. 检查 w-delete 组件 ==="
echo ""

# 检查 w-delete 组件
if grep -q 'w-delete="true"' "$TEMPLATE_FILE"; then
    echo "✅ w-delete 组件存在"
else
    echo "❌ w-delete 组件不存在"
fi

if grep -q '<js:part name="w-delete"/>' "$TEMPLATE_FILE"; then
    echo "✅ w-delete 组件引入存在"
else
    echo "❌ w-delete 组件引入不存在"
fi

echo ""

echo "=== 5. 检查系统分类保护 ==="
echo ""

# 检查系统分类保护逻辑
if grep -q "系统分类不允许编辑" "$TEMPLATE_FILE"; then
    echo "✅ 系统分类编辑保护存在"
else
    echo "❌ 系统分类编辑保护不存在"
fi

if grep -q "系统分类不允许删除" "$TEMPLATE_FILE"; then
    echo "✅ 系统分类删除保护存在"
else
    echo "❌ 系统分类删除保护不存在"
fi

echo ""

echo "=== 6. 检查模态框和表单 ==="
echo ""

# 检查模态框和表单
if grep -q "catalogModal" "$TEMPLATE_FILE"; then
    echo "✅ 模态框相关代码存在"
else
    echo "❌ 模态框相关代码不存在"
fi

if grep -q "catalogForm" "$TEMPLATE_FILE"; then
    echo "✅ 表单相关代码存在"
else
    echo "❌ 表单相关代码不存在"
fi

echo ""

echo "=== 验证总结 ==="
echo ""

# 统计关键指标
total_functions=0
found_functions=0

for func in "${functions[@]}"; do
    ((total_functions++))
    if grep -q "function $func" "$TEMPLATE_FILE"; then
        ((found_functions++))
    fi
done

echo "📊 jstree 原生函数: $found_functions/$total_functions 个"

if grep -q "handleEditNode(data\.reference)" "$TEMPLATE_FILE"; then
    echo "📊 右键菜单调用: ✅"
else
    echo "📊 右键菜单调用: ❌"
fi

if ! grep -q "window\.editCatalog" "$TEMPLATE_FILE"; then
    echo "📊 旧代码清理: ✅"
else
    echo "📊 旧代码清理: ⚠️"
fi

echo ""
echo "============================================"
echo "验证完成!"
echo "============================================"
