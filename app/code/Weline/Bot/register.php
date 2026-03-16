<?php
/**
 * Weline_Bot 模块注册文件
 *
 * AI 智能体模块 - 实现从"对话者"到"执行者"的转变
 *
 * @author  Weline Team
 * @version 1.0.0
 */

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Bot',
    __DIR__,
    '1.0.0',
    'AI 智能体模块 - 显式角色系统、原生任务调度、模块化技能生态、持久化记忆引擎',
    ['Weline_Framework', 'Weline_Ai', 'Weline_Cron', 'Weline_Backend', 'Weline_I18n']
);
