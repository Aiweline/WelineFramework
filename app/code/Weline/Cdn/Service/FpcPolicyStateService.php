<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Cdn\Model\FpcPolicyState;
use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;

/** 只在控制面使用，任何前台请求都不读取此表。 */
final class FpcPolicyStateService
{
    public function __construct(private readonly FpcPolicyState $model, private readonly WriteIntentTransactionCoordinatorInterface $transactions) {}

    public static function initial(): array
    {
        return ['desired_version'=>0,'origin_version'=>0,'origin_revision'=>'','declarations'=>[],'overrides'=>[],'scope_chains'=>[],'jobs'=>[],'next_sync_id'=>1];
    }

    public function read(): array
    {
        $model = clone $this->model;
        $rows = $model->clear()->where('state_id',1)->select()->fetchArray();
        return isset($rows[0]['state_json']) ? json_decode($rows[0]['state_json'],true,512,JSON_THROW_ON_ERROR) : self::initial();
    }

    public function mutate(callable $callback): mixed
    {
        // 首次建行也在既有编译器锁内；后续读写同时受数据库行锁保护。
        $directory = BP . '/var/locks/cdn-fpc-state';
        $publisher = new AtomicCompiledFilePublisher();
        $locked = $publisher->acquireDirectoryLock($directory);
        try {
            return $this->transactions->runWrite($this->model->getConnection(), function () use ($callback) {
                $model = clone $this->model;
                $model->clear()->where('state_id',1)->additional('FOR UPDATE')->find()->fetch();
                $raw = $model->getData('state_json');
                $state = is_string($raw) && $raw !== '' ? json_decode($raw,true,512,JSON_THROW_ON_ERROR) : self::initial();
                $before = $state;
                $result = $callback($state);
                if ($state !== $before || !$model->getId()) {
                    $model->clear()->setData('state_id',1,true)->setData('state_json',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))->setData('updated_at',gmdate('Y-m-d H:i:s'))->save();
                }
                return $result;
            });
        } finally { if ($locked) { AtomicCompiledFilePublisher::releaseDirectoryLock($directory); } }
    }

    public function afterCommit(string $key, callable $callback): void
    {
        $this->transactions->afterCommit($this->model->getConnection(), $key, $callback);
    }
}
