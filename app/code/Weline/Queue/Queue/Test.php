<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:31:23
 */

namespace Weline\Queue\Queue;

use Weline\Queue\Model\Queue;

class Test extends AbstractQueue
{
    public function name(): string
    {
        return __('测试队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return __('测试队列提示');
    }

    public function execute(Queue &$queue): string
    {
        return '执行测试队列操作';
    }

    public function validate(Queue &$queue): bool
    {
        $content = $queue->getContent();
        if ($content) {
            $queue->setResult('验证队列消息内容通过');
            $queue->save();
            return true;
        }
        $queue->setResult('验证队列消息内容失败');
        $queue->setFinished(true);
        $queue->save();
        return false;
    }
}
