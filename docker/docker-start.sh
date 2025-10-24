#!/bin/bash

# WelineFramework Docker 启动脚本
# 自动检测和启动 Docker 服务

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m'

print_message() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

print_success() {
    print_message $GREEN "✅ $1"
}

print_error() {
    print_message $RED "❌ $1"
}

print_warning() {
    print_message $YELLOW "⚠️  $1"
}

print_info() {
    print_message $BLUE "ℹ️  $1"
}

print_step() {
    print_message $PURPLE "🔧 $1"
}

# 检查 Docker 是否安装
check_docker_installed() {
    if ! command -v docker &> /dev/null; then
        print_error "Docker 未安装"
        print_info "请先安装 Docker Desktop: https://www.docker.com/products/docker-desktop"
        exit 1
    fi
    print_success "Docker 已安装"
}

# 检查 Docker 守护进程状态
check_docker_daemon() {
    if docker info &> /dev/null; then
        print_success "Docker 守护进程正在运行"
        return 0
    else
        print_warning "Docker 守护进程未运行"
        return 1
    fi
}

# 启动 Docker Desktop (macOS)
start_docker_desktop_macos() {
    print_step "启动 Docker Desktop..."
    
    if [ -d "/Applications/Docker.app" ]; then
        print_info "找到 Docker Desktop，正在启动..."
        open -a Docker
        
        print_info "等待 Docker Desktop 启动..."
        local count=0
        while [ $count -lt 60 ]; do
            if docker info &> /dev/null; then
                print_success "Docker Desktop 启动成功"
                return 0
            fi
            sleep 2
            count=$((count + 1))
            echo -n "."
        done
        echo
        print_error "Docker Desktop 启动超时"
        return 1
    else
        print_error "未找到 Docker Desktop"
        print_info "请从以下地址下载安装: https://www.docker.com/products/docker-desktop"
        return 1
    fi
}

# 启动 Docker 服务 (Linux)
start_docker_service_linux() {
    print_step "启动 Docker 服务..."
    
    if command -v systemctl &> /dev/null; then
        sudo systemctl start docker
        sudo systemctl enable docker
        print_success "Docker 服务启动成功"
    elif command -v service &> /dev/null; then
        sudo service docker start
        print_success "Docker 服务启动成功"
    else
        print_error "无法启动 Docker 服务"
        print_info "请手动启动 Docker 服务"
        return 1
    fi
}

# 检测操作系统并启动 Docker
start_docker() {
    if [[ "$OSTYPE" == "darwin"* ]]; then
        start_docker_desktop_macos
    elif [[ "$OSTYPE" == "linux-gnu"* ]]; then
        start_docker_service_linux
    else
        print_error "不支持的操作系统: $OSTYPE"
        print_info "请手动启动 Docker 服务"
        exit 1
    fi
}

# 检查 Docker Compose
check_docker_compose() {
    if command -v docker-compose &> /dev/null; then
        print_success "Docker Compose 已安装"
    elif docker compose version &> /dev/null; then
        print_success "Docker Compose (插件) 已安装"
    else
        print_error "Docker Compose 未安装"
        print_info "请安装 Docker Compose"
        exit 1
    fi
}

# 启动 WelineFramework
start_welineframework() {
    print_step "启动 WelineFramework..."
    
    # 检查必要文件
    if [ ! -f "docker-compose.yml" ]; then
        print_error "docker-compose.yml 文件不存在"
        exit 1
    fi
    
    if [ ! -f "Dockerfile" ]; then
        print_error "Dockerfile 文件不存在"
        exit 1
    fi
    
    # 启动服务
    print_info "构建并启动容器..."
    docker-compose up -d --build
    
    print_info "等待服务启动..."
    sleep 10
    
    # 检查服务状态
    print_info "检查服务状态..."
    docker-compose ps
    
    print_success "WelineFramework 启动完成！"
    
    # 显示访问信息
    show_access_info
}

# 显示访问信息
show_access_info() {
    echo
    print_info "🎉 WelineFramework Docker 部署完成！"
    echo
    print_info "📱 访问信息："
    echo "   后台管理: http://localhost/admin/{admin_key}/dashboard"
    echo "   API接口: http://localhost/api/{api_key}/rest/v1/"
    echo
    print_info "🔑 获取密钥："
    echo "   docker exec -it welineframework php bin/w admin:key:show"
    echo
    print_info "📋 常用命令："
    echo "   查看日志: docker-compose logs -f"
    echo "   重启服务: docker-compose restart"
    echo "   停止服务: docker-compose down"
    echo "   进入容器: docker exec -it welineframework bash"
    echo
}

# 主程序
main() {
    print_info "🚀 WelineFramework Docker 启动脚本"
    echo
    
    # 检查是否在正确的目录
    if [ ! -f "composer.json" ]; then
        print_error "请在 WelineFramework 项目根目录下运行此脚本"
        exit 1
    fi
    
    # 检查 Docker 安装
    check_docker_installed
    
    # 检查 Docker 守护进程
    if ! check_docker_daemon; then
        print_warning "Docker 守护进程未运行，尝试启动..."
        start_docker
        
        # 再次检查
        if ! check_docker_daemon; then
            print_error "无法启动 Docker 守护进程"
            print_info "请手动启动 Docker Desktop 或 Docker 服务"
            exit 1
        fi
    fi
    
    # 检查 Docker Compose
    check_docker_compose
    
    # 启动 WelineFramework
    start_welineframework
}

# 运行主程序
main "$@"
