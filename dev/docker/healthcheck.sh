#!/bin/bash

# WelineFramework Docker 健康检查脚本

# 检查 PHP-FPM 状态
if ! pgrep -f "php-fpm" > /dev/null; then
    echo "❌ PHP-FPM 未运行"
    exit 1
fi

# 检查 Nginx 状态
if ! pgrep -f "nginx" > /dev/null; then
    echo "❌ Nginx 未运行"
    exit 1
fi

# 检查 HTTP 响应
if ! curl -f -s http://localhost/ > /dev/null; then
    echo "❌ HTTP 服务无响应"
    exit 1
fi

# 检查数据库连接
if ! php -r "
try {
    \$pdo = new PDO('sqlite:/var/www/html/app/etc/db.sqlite');
    echo '✅ 数据库连接正常';
} catch (Exception \$e) {
    echo '❌ 数据库连接失败: ' . \$e->getMessage();
    exit(1);
}
" > /dev/null; then
    echo "❌ 数据库连接失败"
    exit 1
fi

echo "✅ 所有服务运行正常"
exit 0
