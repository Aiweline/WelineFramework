<?php
/**
 * WelineFramework Database模块注册文件
 * 
 * @author WelineFramework
 * @package Weline\Database
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Database',
    __DIR__,
    '1.0.0',
    '企业级数据库迁移管理系统，支持版本控制、数据备份、安全回滚'
);
