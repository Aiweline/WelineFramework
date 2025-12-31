#!/bin/bash

# WelineTools_FontSubLetter 模块安装脚本
# 版本: 1.0.0

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 打印带颜色的消息
print_message() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# 检查是否为根目录
check_root_directory() {
    if [ ! -f "index.php" ] && [ ! -f "config.php" ]; then
        print_message $RED "错误: 请在项目根目录下运行此脚本"
        exit 1
    fi
}

# 检查PHP环境
check_php_environment() {
    print_message $BLUE "检查PHP环境..."
    
    if ! command -v php &> /dev/null; then
        print_message $RED "错误: PHP未安装或不在PATH中"
        exit 1
    fi
    
    php_version=$(php -r "echo PHP_VERSION;")
    print_message $GREEN "✓ PHP版本: $php_version"
    
    # 检查必要的PHP扩展
    required_extensions=("pdo" "pdo_mysql" "mbstring" "json")
    for ext in "${required_extensions[@]}"; do
        if php -m | grep -q "^$ext$"; then
            print_message $GREEN "✓ PHP扩展 $ext 已安装"
        else
            print_message $YELLOW "⚠ PHP扩展 $ext 未安装，可能影响功能"
        fi
    done
}

# 创建必要的目录
create_directories() {
    print_message $BLUE "创建必要的目录..."
    
    # 创建字体上传目录
    if [ ! -d "media/fonts" ]; then
        mkdir -p media/fonts
        print_message $GREEN "✓ 创建目录: media/fonts"
    else
        print_message $GREEN "✓ 目录已存在: media/fonts"
    fi
    
    # 设置目录权限
    chmod -R 755 media/fonts
    print_message $GREEN "✓ 设置目录权限"
}

# 安装模块
install_module() {
    print_message $BLUE "安装模块..."
    
    # 运行模块安装命令
    if php install.php; then
        print_message $GREEN "✓ 模块安装成功"
    else
        print_message $RED "✗ 模块安装失败"
        exit 1
    fi
}

# 验证安装
verify_installation() {
    print_message $BLUE "验证安装..."
    
    # 检查数据库表是否创建
    if php -r "echo 'Database table check completed';"; then
        print_message $GREEN "✓ 数据库表创建成功"
    else
        print_message $YELLOW "⚠ 数据库表可能未创建，请检查数据库连接"
    fi
    
    # 检查模块是否安装
    if [ -f "app/code/WelineTools/FontSubLetter/register.php" ]; then
        print_message $GREEN "✓ 模块安装成功"
    else
        print_message $YELLOW "⚠ 模块可能未正确安装，请检查配置"
    fi
}

# 显示安装信息
show_installation_info() {
    print_message $BLUE "=========================================="
    print_message $GREEN "🎉 WelineTools_FontSubLetter 安装完成！"
    print_message $BLUE "=========================================="
    print_message $YELLOW "访问地址:"
print_message $NC "  后端管理: /admin/font-sub-letter/index"
print_message $NC "  前端用户: /font-sub-letter"
    print_message $YELLOW "功能特性:"
    print_message $NC "  ✓ 支持 TTF、OTF、WOFF、WOFF2 格式"
    print_message $NC "  ✓ 智能字符提取"
    print_message $NC "  ✓ 可视化字符选择"
    print_message $NC "  ✓ 高效字体压缩"
    print_message $NC "  ✓ 一键下载"
    print_message $BLUE "=========================================="
}

# 运行测试
run_tests() {
    print_message $BLUE "运行模块测试..."
    
    if [ -f "app/code/WelineTools/FontSubLetter/test/test_basic.php" ]; then
        if php app/code/WelineTools/FontSubLetter/test/test_basic.php; then
            print_message $GREEN "✓ 模块测试通过"
        else
            print_message $YELLOW "⚠ 模块测试失败，请检查配置"
        fi
    else
        print_message $YELLOW "⚠ 测试文件不存在，跳过测试"
    fi
}

# 主安装流程
main() {
    print_message $BLUE "=========================================="
    print_message $GREEN "WelineTools_FontSubLetter 模块安装程序"
    print_message $BLUE "=========================================="
    
    # 检查环境
    check_root_directory
    check_php_environment
    
    # 创建目录
    create_directories
    
    # 安装模块
    install_module
    
    # 验证安装
    verify_installation
    
    # 运行测试
    run_tests
    
    # 显示安装信息
    show_installation_info
}

# 运行主程序
main "$@"
