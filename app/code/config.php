<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
# 全局DEBUG模式：生产勿强制开启。强行 DEBUG=1 会让 WLS Master 在无 RequestContext
# 时仍累积 RequestLifecycleTrace spans，直至 memory_limit / OOM。
# 需要调试时用 debug_key / ?debug= / Cookie w_debug，或临时取消下一行注释。
# defined('DEBUG') || define('DEBUG', 1);
# 全局沙盒模式 注释可使用url控制运行环境，设置可以强行控制SANDBOX
defined('SANDBOX') || define('SANDBOX', 0);
