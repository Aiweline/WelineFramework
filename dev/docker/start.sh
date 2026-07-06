#!/bin/bash

# WelineFramework Docker 启动脚本
set -e

echo "🚀 启动 WelineFramework Docker 容器..."

# 等待数据库准备就绪
echo "⏳ 等待服务准备就绪..."
sleep 5

# 设置文件权限
echo "🔧 设置文件权限..."
chown -R www-data:www-data /var/www/html/var
chown -R www-data:www-data /var/www/html/generated
chown -R www-data:www-data /var/www/html/pub
chmod -R 755 /var/www/html/var
chmod -R 755 /var/www/html/generated
chmod -R 755 /var/www/html/pub

# 检查是否需要初始化
if [ ! -f "/var/www/html/app/etc/env.php" ] || [ ! -f "/var/www/html/app/etc/db.sqlite" ]; then
    echo "📦 初始化 WelineFramework..."
    
    # 安装 Composer 依赖
    echo "📥 安装 Composer 依赖..."
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # 运行系统升级
    echo "⬆️ 运行系统升级..."
    php bin/w setup:upgrade
    
    # 安装定时任务
    echo "⏰ 安装定时任务..."
    php bin/w cron:install
    
    # 设置生产模式
    echo "🏭 设置生产模式..."
    php bin/w deploy:mode:set prod
    
    echo "✅ 初始化完成！"
else
    echo "✅ 检测到已初始化的系统，跳过初始化步骤"
fi

# 显示访问信息
echo ""
echo "🎉 WelineFramework 启动完成！"
echo ""
echo "📱 访问信息："
echo "   后台管理: http://localhost/admin/{admin_key}/dashboard"
echo "   API接口: http://localhost/api/{api_key}/rest/v1/"
echo ""
echo "🔑 获取密钥："
echo "   docker exec -it <container_name> php bin/w admin:key:show"
echo ""

# 启动 Supervisor
echo "🔄 启动服务..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
