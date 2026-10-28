<?php
declare(strict_types=1);

namespace Weline\App\Console\App\Artifact;

use Weline\App\Model\AppArtifact;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandResult;
use Weline\Framework\Manager\ObjectManager;

/**
 * 登记「本机编译 → 上传到本站」的 App 产物。
 *
 * 本机协助编译时线上不需要编译环境：产物目录连同 FlutterBuilder 写出的 build.json
 * 一起上传到 {应用根}/pub/static/weline-app/<build_id>/，再在目标机跑本命令登记。
 * 登记后 /app/download、version 与 download 接口立即按最新 build_ts 下发。
 */
class Import extends CommandAbstract
{
    public const ALIASES = ['app:artifact:import'];

    public function execute(array $args = [], array $data = [])
    {
        $pathArg = \trim((string)($args['path'] ?? ''));
        $changelog = \trim((string)($args['changelog'] ?? ''));
        $dry = isset($args['dry-run']) || isset($args['d']);
        if ($pathArg === '') {
            $this->printer->error('需要 --path=static/weline-app/<build_id>（产物目录，含 build.json）');
            return CommandResult::shortCircuit(1);
        }

        $rel = \trim(\str_replace('\\', '/', $pathArg), '/');
        if (\preg_match('#^static/weline-app/[A-Za-z0-9._-]+$#', $rel) !== 1) {
            $this->printer->error('非法产物路径：' . $pathArg . '（只接受 static/weline-app/<build_id>）');
            return CommandResult::shortCircuit(1);
        }
        $dir = \rtrim(PUB, '/\\') . '/' . $rel;
        $stampFile = $dir . '/build.json';
        if (!\is_file($stampFile)) {
            $this->printer->error('缺 build.json：' . $stampFile . '（上传时整目录带上，别只传安装包）');
            return CommandResult::shortCircuit(1);
        }
        $stamp = \json_decode((string)\file_get_contents($stampFile), true);
        if (!\is_array($stamp)) {
            $this->printer->error('build.json 解析失败：' . $stampFile);
            return CommandResult::shortCircuit(1);
        }

        $buildId = \trim((string)($stamp['build_id'] ?? ''));
        $platform = \trim((string)($stamp['platform'] ?? ''));
        $version = \trim((string)($stamp['version'] ?? ''));
        $buildTs = (int)($stamp['build_ts'] ?? 0);
        $sha256 = \trim((string)($stamp['sha256'] ?? ''));
        $sizeBytes = (int)($stamp['size_bytes'] ?? 0);
        $errors = [];
        if ($buildId === '' || \basename($rel) !== $buildId) {
            $errors[] = 'build.json 的 build_id 与目录名不一致（' . $buildId . ' vs ' . \basename($rel) . '）';
        }
        if (!\in_array($platform, ['android', 'ios', 'web'], true)) {
            $errors[] = 'build.json 的 platform 非法：' . $platform;
        }
        if ($version === '') {
            $errors[] = 'build.json 缺 version';
        }
        if ($buildTs <= 0) {
            $errors[] = 'build.json 缺构建期发布戳 build_ts（缺戳产物不允许登记）';
        }
        if ($sizeBytes < 0) {
            $errors[] = 'build.json 的 size_bytes 非法';
        }
        $artifactFile = match ($platform) {
            'android' => $dir . '/app.apk',
            'ios' => $dir . '/app.ipa',
            default => '',
        };
        if ($artifactFile !== '') {
            if (!\is_file($artifactFile)) {
                $errors[] = '产物文件缺失：' . $artifactFile;
            } else {
                $realSha = (string)\hash_file('sha256', $artifactFile);
                $realSize = \filesize($artifactFile) ?: 0;
                if ($sha256 === '' || $realSha !== $sha256) {
                    $errors[] = 'sha256 不一致（build.json=' . $sha256 . ' 实际=' . $realSha . '）';
                }
                if ($realSize !== $sizeBytes) {
                    $errors[] = '字节数不一致（build.json=' . $sizeBytes . ' 实际=' . $realSize . '）';
                }
            }
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->printer->error($error);
            }
            return CommandResult::shortCircuit(1);
        }

        $summary = [
            'build_id' => $buildId,
            'platform' => $platform,
            'version' => $version,
            'build_ts' => $buildTs,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
            'path' => $rel,
            'base_url' => (string)($stamp['base_url'] ?? ''),
            'site_hosts' => (string)($stamp['site_hosts'] ?? ''),
            'app_name' => (string)($stamp['app_name'] ?? ''),
            'version_api' => '/api/weline_app/rest/v1/version/version?platform=' . $platform,
            'download_api' => '/api/weline_app/rest/v1/download/download?platform=' . $platform,
        ];
        if ($dry) {
            $summary['result'] = 'dry-run';
            echo \json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
            return 0;
        }

        try {
            /** @var AppArtifact $model */
            $model = ObjectManager::getInstance(AppArtifact::class);
            $row = $model->clear()
                ->where(AppArtifact::schema_fields_build_id, $buildId)
                ->find()
                ->fetch();
            $now = \date('Y-m-d H:i:s');
            $existing = $row && $row->getData('id');
            $action = $existing ? 'updated' : 'created';
            if ($existing
                && $row->getData(AppArtifact::schema_fields_status) === AppArtifact::STATUS_RELEASED
                && (string)$row->getData(AppArtifact::schema_fields_sha256) === $sha256
                && ($changelog === '' || $changelog === (string)$row->getData(AppArtifact::schema_fields_changelog))
            ) {
                $action = 'unchanged';
            }
            if ($action !== 'unchanged') {
                $target = $existing ? $row : $model->clear();
                $data = [
                    AppArtifact::schema_fields_build_id => $buildId,
                    AppArtifact::schema_fields_platform => $platform,
                    AppArtifact::schema_fields_version => $version,
                    AppArtifact::schema_fields_build_ts => $buildTs,
                    AppArtifact::schema_fields_sha256 => $sha256,
                    AppArtifact::schema_fields_size_bytes => $sizeBytes,
                    AppArtifact::schema_fields_status => AppArtifact::STATUS_RELEASED,
                    AppArtifact::schema_fields_path => $rel,
                    AppArtifact::schema_fields_changelog => $changelog !== ''
                        ? $changelog
                        : (string)($existing ? ($row->getData(AppArtifact::schema_fields_changelog) ?? '') : ''),
                    AppArtifact::schema_fields_updated_at => $now,
                ];
                if (!$existing) {
                    $data[AppArtifact::schema_fields_created_at] = $now;
                }
                $target->setData($data);
                $target->save();
            }
        } catch (\Throwable $e) {
            $this->printer->error('登记失败：' . $e->getMessage());
            return CommandResult::shortCircuit(1);
        }

        $summary['result'] = $action;
        echo \json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        $this->printer->success($action === 'unchanged' ? '产物已登记（无变化）：' . $buildId : '产物已登记并发布：' . $buildId . '（' . $platform . ' ' . $version . '）');

        return 0;
    }

    public function tip(): string
    {
        return __('登记本机编译后上传的 App 产物：--path=static/weline-app/<build_id>（整目录含 build.json）；校验 sha256/字节数后按 build_ts 发布；--dry-run 只校验不登记');
    }
}
