<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Service\FpcPolicyPublishService;
use Weline\Cdn\Service\CompiledFpcPolicySnapshotProvider;
use Weline\Framework\Manager\ObjectManager;
try {
    $result=ObjectManager::getInstance(FpcPolicyPublishService::class)->publishLatest();
    $snapshot=(new CompiledFpcPolicySnapshotProvider())->snapshot();
    if($result['snapshot_version']!==$snapshot['source_version']||$result['snapshot_revision']!==$snapshot['revision']){throw new RuntimeException('FAIL 实际文件版本证据不一致');}
    if($result['published_at']!==$snapshot['compiled_at']){throw new RuntimeException('FAIL 无内容变化时不能把确认时间声称为文件发布时间');}
    echo "PASS 发布返回实际快照版本、摘要和原文件发布时间\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
