<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 15:34:45
 */

namespace Weline\Queue\Console\Queue;

use Weline\Cron\Helper\Process;
use Weline\Framework\App\System;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;
    private Queue $queue;

    public function __construct(Printing $printing, Queue $queue)
    {
        $this->printing = $printing;
        $this->queue    = $queue;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $id = $args['id'] ?? 0;
        if ($id == 0) {
            $this->printing->error(__('请输入队列ID。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=1'));
            exit();
        }
        $queue = $this->queue->load($id);
        if (empty($queue->getId())) {
            $this->printing->error(__('队列不存在。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=%1', $id));
            exit();
        }

        # 获取执行者
        $type = $queue->getType();
        /**@var QueueInterface $queue_execute */
        $queue_execute   = ObjectManager::getInstance($type->getData('class'));
        $validate_result = $queue_execute->vaLidate($queue);
        if (is_bool($validate_result) and $validate_result) {
            $queue->setStatus($queue::status_running)
                ->setResult($queue->getResult() . PHP_EOL . __('正在执行...'))
                ->save();
            try {
                $result = $queue_execute->execute($queue);
                $queue->setStatus($queue::status_done)
                    ->setResult($queue->getResult() . PHP_EOL . $result)
                    ->save();
            } catch (\Throwable $e) {
                $result = $e->getMessage();
                $queue->setStatus($queue::status_error)
                    ->setResult($queue->getResult() . PHP_EOL . $result)
                    ->save();
                throw $e;
            }
        } else {
            $result = __('队列消息内容验证不通过。') . ($validate_result ? __('验证结果：') : '');
            $this->printing->error($result);
            $queue->setStatus($queue::status_error)
                ->setResult($result . PHP_EOL . $queue->getResult())
                ->save();
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('运行队列. ') . 'php bin/w queue:run --id=1';
    }
}
