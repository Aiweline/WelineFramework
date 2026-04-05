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
    '1.5.2',
    __('高性能异步常驻内存服务器，支持 HTTP/WebSocket/TCP/UDP 协议，支持多实例管理、服务器监控、攻击日志、证书管理 Hook 集成')
);
