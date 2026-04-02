#!/bin/bash
# 路由缓存功能测试脚本

echo "=========================================="
echo "运行路由缓存功能测试"
echo "=========================================="
echo ""

# 运行 RoutingCacheService 测试
echo "1. 测试 RoutingCacheService（路由缓存服务）"
vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Dispatcher/RoutingCacheServiceTest.php --testdox

echo ""
echo "2. 测试 CacheClear 命令"
vendor/bin/phpunit app/code/Weline/Server/Test/Unit/Console/CacheClearCommandTest.php --testdox

echo ""
echo "=========================================="
echo "测试完成"
echo "=========================================="
