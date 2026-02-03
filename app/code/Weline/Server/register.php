<?php
declare(strict_types=1);

/**
 * Weline Server - 高性能异步常驻内存服务器
 * 
 * Weline 高性能异步事件驱动服务器模块
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 * @website aiweline.com
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Server',
    __DIR__,
    '1.3.0',
    __('高性能异步常驻内存服务器，支持 HTTP/WebSocket/TCP/UDP 协议，支持多实例管理')
);
