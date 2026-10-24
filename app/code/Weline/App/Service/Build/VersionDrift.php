<?php
declare(strict_types=1);

namespace Weline\App\Service\Build;

use Weline\App\Model\AppArtifact;
use Weline\App\Model\AppBuild;
use Weline\Framework\Manager\ObjectManager;

class VersionDrift
{
    public function drifted(): array
    {
        $current = '0.0.0';
        $file = BP . 'app/code/Weline/App/etc/weline_app_versions.json';
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            $current = (string)($data['module'] ?? $current);
        }

        /** @var AppArtifact $artifact */
        $artifact = ObjectManager::getInstance(AppArtifact::class);
        $rows = $artifact->clear()->where(AppArtifact::schema_fields_status, AppArtifact::STATUS_RELEASED)
            ->order(AppArtifact::schema_fields_build_ts, 'DESC')
            ->limit(1)
            ->select()
            ->fetchArray();
        $row = (array)($rows[0] ?? []);
        $latestVersion = ($row[AppArtifact::schema_fields_version] ?? '') === '' ? null : (string)$row[AppArtifact::schema_fields_version];
        $latestTs = isset($row[AppArtifact::schema_fields_build_ts]) ? (int)$row[AppArtifact::schema_fields_build_ts] : null;

        return [
            'drift' => $latestVersion === null || $latestVersion !== $current,
            'current_version' => $current,
            'latest_artifact_version' => $latestVersion,
            'latest_build_ts' => $latestTs === null ? null : (int)$latestTs,
        ];
    }

    public function websitesToBuild(): array
    {
        return $this->enabledBuildTargets();
    }

    public function enabledBuildTargets(): array
    {
        // 多行枚举用 PDO 确定性读取（ORM 集合形态在 L81/L84 有形态坑）
        $env = require BP . 'app/etc/env.php';
        $db = $env['db']['master'] ?? [];
        if (!$db) {
            return [['website_id' => 0, 'platforms' => ['android', 'web']]];
        }
        try {
            $pdo = new \PDO(
                $db['type'] . ':host=' . $db['hostname'] . ';port=' . $db['hostport'] . ';dbname=' . $db['database'],
                $db['username'], (string)($db['password'] ?? '')
            );
        } catch (\Throwable $e) {
            return [['website_id' => 0, 'platforms' => ['android', 'web']]];
        }
        $rows = $pdo->query("SELECT website_id, domain, platforms FROM w_weline_app_build WHERE enabled = 1")->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return [['website_id' => 0, 'platforms' => ['android', 'web']]];
        }
        $result = [];
        foreach ($rows as $row) {
            $platforms = json_decode((string)($row['platforms'] ?? ''), true);
            if (!is_array($platforms) || !$platforms) {
                continue;
            }
            $result[] = [
                'website_id' => (int)$row['website_id'],
                'platforms' => $platforms,
            ];
        }
        return $result ?: [['website_id' => 0, 'platforms' => ['android', 'web']]];
    }
}
