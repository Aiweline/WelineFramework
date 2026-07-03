<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Weline_Trash',
    __DIR__,
    '1.0.0',
    __('通用回收站模块，通过 provider 接入业务删除、恢复与原始数据查看能力。')
);
