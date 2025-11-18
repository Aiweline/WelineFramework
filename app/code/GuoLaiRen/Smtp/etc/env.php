<?php

/*
 * GuoLaiRen SMTP Module Configuration
 * SMTP邮件配置文件
 */

return [
    'smtp' => [
        // Namecheap SMTP服务器配置
        // Namecheap Private Email SMTP服务器地址
        'host' => 'mail.privateemail.com',
        
        // SMTP端口: 587 (TLS) 或 465 (SSL)
        'port' => 587,
        
        // 加密方式: tls 或 ssl
        'encryption' => 'tls',
        
        // 您的Namecheap邮箱账号
        // 示例: 'your-email@yourdomain.com'
        'username' => '',
        
        // 您的Namecheap邮箱密码
        'password' => '',
        
        // 发件人邮箱地址（通常与username相同）
        'from_email' => '',
        
        // 发件人显示名称
        'from_name' => 'GuoLaiRen',
        
        /*
         * Namecheap SMTP配置说明:
         * 
         * 1. 确保您已在Namecheap购买并设置了Private Email服务
         * 2. SMTP服务器地址: mail.privateemail.com
         * 3. 端口选择:
         *    - TLS: 587 (推荐)
         *    - SSL: 465
         * 4. 需要身份验证: 是
         * 5. 用户名: 您的完整邮箱地址
         * 6. 密码: 您的邮箱密码
         * 
         * 其他常见邮件服务商SMTP配置:
         * 
         * Gmail:
         * - host: smtp.gmail.com
         * - port: 587 (TLS) 或 465 (SSL)
         * - 需要开启"允许不够安全的应用访问"或使用应用专用密码
         * 
         * Outlook/Hotmail:
         * - host: smtp-mail.outlook.com
         * - port: 587
         * 
         * QQ邮箱:
         * - host: smtp.qq.com
         * - port: 587 或 465
         * - 需要使用授权码而非密码
         * 
         * 163邮箱:
         * - host: smtp.163.com
         * - port: 465
         * - 需要使用授权码而非密码
         */
    ]
];

