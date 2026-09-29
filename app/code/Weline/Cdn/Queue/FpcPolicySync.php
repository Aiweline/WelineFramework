<?php
declare(strict_types=1);
namespace Weline\Cdn\Queue;
use Weline\Cdn\Service\FpcPolicySyncService;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

final class FpcPolicySync implements QueueConsumerInterface
{
    public function __construct(private readonly FpcPolicySyncService $sync) {}
    public function name():string{return 'FPC 策略同步';}
    public function tip():string{return '发布源站策略、同步 Zone 规则并清理累计缓存目标';}
    public function attributes():array{return [];}
    public function validate(QueueTaskContextInterface $queue):bool
    {
        $job=json_decode($queue->getContent(),true);
        return is_array($job)&&($job['schema_version']??'')==='cdn-fpc-sync-job.v1'&&is_string($job['job_key']??null);
    }
    public function execute(QueueTaskContextInterface $queue):string
    {
        if(!$this->validate($queue)){throw new \InvalidArgumentException('cdn_fpc_invalid_queue_payload');}
        $job=json_decode($queue->getContent(),true,512,JSON_THROW_ON_ERROR);
        return json_encode($this->sync->run($job['job_key']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
}
