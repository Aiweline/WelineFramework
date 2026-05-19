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

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\Process\Processer;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

class Run implements \Weline\Framework\Console\CommandInterface
{
    private Printing $printing;
    private Queue $queue;

    public function __construct(Printing $printing, Queue $queue)
    {
        $this->printing = $printing;
        $this->queue = $queue;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): string
    {
        $this->disableCliExecutionTimeout();

        $id = $args['id'] ?? 0;
        $force = !empty($args['f']) || !empty($args['force']);
        if ($id == 0) {
            $this->printing->error(__('请输入队列ID。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=1'));
            exit();
        }
        $queue = $this->newQueueModel()->load($id);
        if (empty($queue->getId())) {
            $this->printing->error(__('队列不存在。 '));
            $this->printing->success(__('正确示例：php bin/w queue:run --id=%{1}', $id));
            exit();
        }
        $currentPid = (int)getmypid();
        $existingPid = (int)($queue->getPid() ?: 0);
        $existingStatus = \trim((string)$queue->getStatus());
        $sameQueueRunning = false;
        if ($existingStatus === $queue::status_running) {
            if ($existingPid > 0 && $existingPid !== $currentPid) {
                $sameQueueRunning = Processer::isRunningByPid($existingPid);
            } elseif ($existingPid === 0) {
                // 无 pid 但状态仍是 running，按“正在执行”处理，避免并发重复消费。
                $sameQueueRunning = true;
            }
        }
        if ($sameQueueRunning) {
            if (!$force) {
                $this->printing->error(__('队列 #%{1} 正在运行中，禁止重复运行（pid=%{2}）。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
                $this->printing->warning(__('如需接管，请使用 --force；系统会先终止当前同 ID 任务后再启动新任务。'));
                exit();
            }
            $this->printing->warning(__('强制模式：检测到队列 #%{1} 正在运行（pid=%{2}），将先终止旧任务再继续。', [$id, (string)($existingPid > 0 ? $existingPid : '-')]));
            if ($existingPid > 0 && $existingPid !== $currentPid && Processer::isRunningByPid($existingPid)) {
                $killed = (bool)Processer::killByPid($existingPid, true);
                if (!$killed) {
                    $this->printing->error(__('终止队列 #%{1} 旧任务失败（pid=%{2}），已中止本次运行。', [$id, $existingPid]));
                    exit();
                }
                $this->printing->note(__('已终止队列 #%{1} 旧任务（pid=%{2}）。', [$id, $existingPid]));
            }
            $queue = $this->newQueueModel()->load($id);
            $queue->setStatus($queue::status_pending)
                ->setPid(0)
                ->setProcess(\trim((string)$queue->getProcess() . PHP_EOL . __('强制接管：已终止同 ID 旧任务，准备重新执行。')))
                ->save();
        } elseif ($existingStatus === $queue::status_running && $existingPid > 0 && !Processer::isRunningByPid($existingPid)) {
            // 兜底：running + 僵尸 pid，自动回收，允许本次继续执行。
            $queue->setStatus($queue::status_pending)
                ->setPid(0)
                ->setProcess(\trim((string)$queue->getProcess() . PHP_EOL . __('检测到历史运行进程不存在，已自动回收为 pending。')))
                ->save();
        }

        # 获取执行者
        $type = $queue->getType();
        if ($force) {
            $content = \json_decode((string)$queue->getContent(), true);
            if (\is_array($content)) {
                $content['_force_rebuild'] = 1;
                // 强制重跑同一个队列 ID 时先清空历史 result/process，避免旧日志（含历史乱码）干扰本次观察
                $queue->setContent((string)(\json_encode($content, \JSON_UNESCAPED_UNICODE) ?: (string)$queue->getContent()))
                    ->setResult('')
                    ->setProcess('')
                    ->save();
                $this->printing->warning(__('已启用强制模式(-f)：本次执行将优先使用队列类中的强制重建逻辑。'));
                $this->printing->note(__('已清空该队列历史输出，本次仅展示最新执行过程。'));
            }
        }
        /**@var QueueInterface $queue_execute */
        $queue_execute = ObjectManager::getInstance($type->getData('class'));
        $validate_result = $queue_execute->validate($queue);
        if (is_bool($validate_result) and $validate_result) {
            $queue->setStatus($queue::status_running)
                ->setPid((int)getmypid())
                ->setResult($queue->getResult() . PHP_EOL . __('正在执行...'))
                ->save();
            try {
                $queue->setArgs($args); # 记录执行参数
                $result = $queue_execute->execute($queue);
                // execute() 内常通过 w_query 等直接更新库里的 result；此处必须重新 load，否则会用过期内存覆盖掉过程日志
                $queue = $this->newQueueModel()->load($id);
                $queue->setPid(0)
                    ->setResult(\trim($queue->getResult() . PHP_EOL . $result));
                $finalStatus = \trim((string)$queue->getStatus());
                if ($finalStatus === $queue::status_pending) {
                    $queue->setFinished(false)->save();
                } elseif (\in_array($finalStatus, [$queue::status_stop, $queue::status_error], true)) {
                    $queue->save();
                } else {
                    $queue->setStatus($queue::status_done)->save();
                }
                $this->printing->title(__('队列执行详情') . ' queue_id=' . $id);
                $this->printing->note($queue->getResult());
            } catch (\Throwable $e) {
                $result = $e->getMessage();
                $queue = $this->newQueueModel()->load($id);
                $queue->setStatus($queue::status_error)
                    ->setPid(0)
                    ->setResult(\trim($queue->getResult() . PHP_EOL . $result))
                    ->save();
                $this->printing->title(__('队列执行详情（失败）') . ' queue_id=' . $id);
                $this->printing->note($queue->getResult());
                $this->printing->error($result);
                throw $e;
            }
        } else {
            $result = __('队列消息内容验证不通过。') . ($validate_result ? __('验证结果：') : '');
            $this->printing->error($result);
            $queue->setStatus($queue::status_error)
                ->setPid(0)
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
        return __('运行队列. ') . 'php bin/w queue:run --id=1 [-f]';
    }

    private function newQueueModel(): Queue
    {
        return (clone $this->queue)->clearData()->clearQuery();
    }

    private function disableCliExecutionTimeout(): void
    {
        if (\PHP_SAPI !== 'cli') {
            return;
        }

        // Queue workers can run long AI/build tasks; do not inherit php.ini execution limits.
        @\ini_set('max_execution_time', '0');
        @\set_time_limit(0);
        @\ignore_user_abort(true);
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
                '-f, --force' => '强制模式：将 _force_rebuild 注入队列内容；PageBuilder 阶段一/二/构建队列会换新 execution_token 并清 result，避免 duplicate_stream 秒跳过',
            ],
            [],
            []
        );
    }
}
