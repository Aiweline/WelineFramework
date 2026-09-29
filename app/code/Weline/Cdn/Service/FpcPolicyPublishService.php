<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;
use Weline\Framework\Compilation\AtomicCompiledFilePublisher;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;

final class FpcPolicyPublishService
{
    public function __construct(private readonly FpcPolicyStateService $states, private readonly NamespaceGenerationInterface $generations) {}

    public function publishLatest(): array
    {
        $directory = BP . '/generated/framework';
        $publisher = new AtomicCompiledFilePublisher();
        $locked = $publisher->acquireDirectoryLock($directory);
        try {
            $state = $this->states->read();
            $declarations = [];
            foreach ($state['declarations'] as $id => $entry) { if ($entry['active']) { $declarations[$id] = $entry['declaration']; } }
            $snapshot = FpcPolicyStateReducer::canonical(['schema_version'=>'fpc-policy-snapshot.v1','declarations'=>$declarations,'overrides'=>$state['overrides'],'scope_chains'=>$state['scope_chains']]);
            $revision = FpcPolicyStateReducer::hash($snapshot);
            $file = $directory . '/fpc_policy_snapshot.php';
            $existingSnapshot = [];
            try {
                $existingSnapshot = (new CompiledFpcPolicySnapshotProvider())->snapshot();
            } catch (\Throwable) {
                // 已提交控制面仍然有效时，重新发布可以修复损坏的派生文件。
            }
            $changed = ($existingSnapshot['revision'] ?? '') !== $revision || $state['origin_revision'] !== $revision;
            $snapshotVersion = $changed ? (int)$state['desired_version'] : (int)$existingSnapshot['source_version'];
            $now = gmdate(DATE_ATOM);
            $publishedAt = $changed ? $now : (string)$existingSnapshot['compiled_at'];
            if ($changed) {
                $snapshot += ['revision'=>$revision,'source_version'=>(int)$state['desired_version'],'compiled_at'=>$now];
                $publisher->publish($file, "<?php\ndeclare(strict_types=1);\nreturn " . var_export($snapshot,true) . ";\n");
                // 文件成功以后才失效；失败保留旧origin回执，重试会再次推进代次。
                $this->generations->bump('global/fpc-policy');
            }
            $this->states->mutate(static function (array &$latest) use ($state,$revision,$now,$publishedAt): void {
                $latest['origin_version'] = (int)$state['desired_version'];
                $latest['origin_revision'] = $revision;
                $latest['published_at'] = $publishedAt;
                $latest['origin_confirmed_at'] = $now;
            });
            return ['changed'=>$changed,'source_version'=>(int)$state['desired_version'],'revision'=>$revision,'published_at'=>$publishedAt,'origin_confirmed_at'=>$now,'snapshot_version'=>$snapshotVersion,'snapshot_revision'=>$revision];
        } finally { if ($locked) { AtomicCompiledFilePublisher::releaseDirectoryLock($directory); } }
    }
}
