<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Taglib\Plugin;

/**
 * Setup 升级执行前插件（占位）
 *
 * 标签注册表改在 setup:upgrade 的 collectFrameworkRegistries 中、于 Extends 等框架注册表之后
 * 通过事件 Weline_Framework_Setup::collect_taglib_registry 统一收集，避免一进命令就先扫标签、顺序错乱。
 */
class SetupUpgradeBeforeExecutePlugin
{
    public function beforeExecute($subject, ...$args): void
    {
        // 有意留空：标签收集见 Framework Setup\Upgrade::collectFrameworkRegistries
    }
}
