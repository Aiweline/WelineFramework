<?php
declare(strict_types=1);

namespace Weline\App\Queue;

use Weline\App\Model\AppArtifact;
use Weline\App\Service\Build\ConfigRenderer;
use Weline\App\Service\Build\FlutterBuilder;
use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\Queue\AbstractQueue;

class AppBuildQueue extends AbstractQueue
{
    public function name(): string
    {
        return 'Weline App 打包任务';
    }

    public function tip(): string
    {
        return __('按站点渲染配置并执行 flutter build（android→APK / ios→未签名 IPA / web→在线商店入口），产物登记 AppArtifact');
    }

    public function attributes(): array
    {
        return [];
    }

    public function validate(Queue &$queue): bool
    {
        $content = json_decode((string)$queue->getContent(), true);
        if (is_array($content) && isset($content['website_id'], $content['platform'])) {
            return true;
        }
        $queue->setResult('内容无效：需要 {"website_id":int,"platform":string}');
        $queue->setFinished(true);
        $queue->save();
        return false;
    }

    public function execute(Queue &$queue): string
    {
        $content = json_decode((string)$queue->getContent(), true);
        $websiteId = (int)($content['website_id'] ?? 0);
        $platform = (string)($content['platform'] ?? '');
        $baseUrl = \trim((string)($content['base_url'] ?? ''));

        try {
            $cfg = (new ConfigRenderer())->render($websiteId, $platform, $baseUrl !== '' ? $baseUrl : null);
        } catch (\Throwable $e) {
            $queue->setResult('配置失败：' . $e->getMessage());
            $queue->setFinished(true);
            $queue->save();
            return '配置失败';
        }

        $buildId = substr(hash('sha256', $cfg['build_id_prefix'] . '-' . microtime(true)), 0, 32);

        /** @var AppArtifact $artifact */
        $artifact = ObjectManager::getInstance(AppArtifact::class);
        $now = date('Y-m-d H:i:s');
        $artifact->clear();
        $artifact->setData([
            AppArtifact::schema_fields_build_id => $buildId,
            AppArtifact::schema_fields_platform => $platform,
            AppArtifact::schema_fields_version => (string)$cfg['version'],
            AppArtifact::schema_fields_build_ts => 0,
            AppArtifact::schema_fields_sha256 => '',
            AppArtifact::schema_fields_size_bytes => 0,
            AppArtifact::schema_fields_status => AppArtifact::STATUS_BUILDING,
            AppArtifact::schema_fields_path => 'static/weline-app/' . $buildId,
            AppArtifact::schema_fields_created_at => $now,
            AppArtifact::schema_fields_updated_at => $now,
        ]);
        $artifact->save();
        $artifactId = (int)$artifact->getId();

        try {
            // 按平台分派：android→APK；ios→未签名 IPA（自签侧载）；web→在线商店入口
            if ($platform === 'android') {
                (new FlutterBuilder())->buildAndroid($cfg, $buildId, $artifact);
            } elseif ($platform === 'ios') {
                (new FlutterBuilder())->buildIos($cfg, $buildId, $artifact);
            } else {
                (new FlutterBuilder())->buildWeb($cfg, $buildId, $artifact);
            }
            $queue->setResult('released:' . $buildId);
            $queue->setFinished(true);
            $queue->save();
            return '打包成功:' . $buildId;
        } catch (\Throwable $e) {
            $artifact->clear();
            $artifact->load($artifactId);
            $artifact->setData(AppArtifact::schema_fields_status, AppArtifact::STATUS_FAILED);
            $artifact->setData(AppArtifact::schema_fields_updated_at, date('Y-m-d H:i:s'));
            $artifact->save();
            $queue->setResult('失败：' . $e->getMessage());
            $queue->setFinished(true);
            $queue->save();
            return '打包失败';
        }
    }
}
