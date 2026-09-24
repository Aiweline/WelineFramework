<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 部署期店面 FPC / 呈现世代失效（contracts C-HELPER / C-PURGE / C-BUMP / C-NS / C-STAMP）。
 *
 * 唯一写侧入口：Mode\Set prod、Deploy\Upgrade 完成点（及将来 release 旁路）。
 * 经 w_changed → InvalidationEffect；业务模块禁止 new 本类绕道。
 */
final class DeployFpcInvalidation
{
    public const NS_STOREFRONT_DEPLOY = 'global/storefront/deploy';

    public const TRIGGER_MODE_SET_PROD = 'mode_set_prod';
    public const TRIGGER_UPGRADE = 'upgrade';

    public const CURRENT_JSON_REL = 'var/deploy/current.json';

    /**
     * Mode\Set prod：必须 bump deploy ns + purge_fpc_all；并保证 stamp 可观测、非粘滞 dev。
     *
     * @return array{stamp:string,invalidated:bool,purged:bool}
     */
    public function afterModeSetProd(): array
    {
        $stamp = $this->ensureObservableDeployStamp('mode_set_prod');
        $this->publishInvalidation(
            self::TRIGGER_MODE_SET_PROD,
            purgeFpcAll: true,
            reason: 'deploy_mode_set_prod',
            stamp: $stamp,
        );

        return [
            'stamp' => $stamp,
            'invalidated' => true,
            'purged' => true,
        ];
    }

    /**
     * 日常 Upgrade 完成点：优先 bump；无 stamp/树变更的空跑不 thrash；不默认 purge_fpc_all。
     *
     * @return array{invalidated:bool,purged:bool,skipped:bool,stamp:string}
     */
    public function afterUpgrade(bool $treeOrStampChanged): array
    {
        $stamp = $this->readDeployStamp();
        if (!$treeOrStampChanged) {
            return [
                'invalidated' => false,
                'purged' => false,
                'skipped' => true,
                'stamp' => $stamp,
            ];
        }

        $this->publishInvalidation(
            self::TRIGGER_UPGRADE,
            purgeFpcAll: false,
            reason: 'deploy_upgrade',
            stamp: $stamp !== '' ? $stamp : 'upgrade',
        );

        return [
            'invalidated' => true,
            'purged' => false,
            'skipped' => false,
            'stamp' => $stamp,
        ];
    }

    /**
     * 切 prod / 正式路径：保证 var/deploy/current.json 的 deploy_version 可观测且非字面 dev。
     */
    public function ensureObservableDeployStamp(string $source): string
    {
        $current = $this->readCurrentJson();
        $existing = trim((string)($current['deploy_version'] ?? ''));
        if ($existing !== '' && strtolower($existing) !== 'dev') {
            $this->syncEnvDeployVersion($existing, (string)($current['worker_build_id'] ?? ''));
            return $existing;
        }

        $stamp = $this->generateModeSetStamp();
        $workerBuildId = 'wls-' . date('Ymd-His');
        $payload = is_array($current) ? $current : [];
        $payload['deploy_version'] = $stamp;
        $payload['worker_build_id'] = $workerBuildId;
        $payload['deployed_at'] = time();
        $payload['stamp_source'] = $source;
        if (!isset($payload['release_id']) || trim((string)$payload['release_id']) === '') {
            $payload['release_id'] = 'mode-set-' . $stamp;
        }
        $this->writeCurrentJson($payload);
        $this->syncEnvDeployVersion($stamp, $workerBuildId);

        return $stamp;
    }

    public function deployNamespacePath(): string
    {
        /** @var NamespacePath $namespacePath */
        $namespacePath = ObjectManager::getInstance(NamespacePath::class);

        return $namespacePath->global('storefront', ['deploy']);
    }

    public function readDeployStamp(): string
    {
        $current = $this->readCurrentJson();
        if (!is_array($current)) {
            return '';
        }

        return trim((string)($current['deploy_version'] ?? ''));
    }

    private function publishInvalidation(
        string $trigger,
        bool $purgeFpcAll,
        string $reason,
        string $stamp,
    ): void {
        $ns = $this->deployNamespacePath();
        /** @var ResourceChangeFactory $factory */
        $factory = ObjectManager::getInstance(ResourceChangeFactory::class);
        $change = $factory->create(
            resourceType: 'deploy_static',
            resourceId: 'storefront:' . $trigger,
            action: 'publish',
            revision: max(1, (int)time()),
            websiteId: 0,
            websiteCode: 'default',
            before: [
                'trigger' => $trigger,
                'deploy_version' => $this->readDeployStamp(),
            ],
            after: [
                'trigger' => $trigger,
                'deploy_version' => $stamp,
                'purge_fpc_all' => $purgeFpcAll,
                'reason' => $reason,
            ],
            changedFields: ['deploy_version', 'trigger'],
            impact: [
                'namespaces' => [$ns],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            origin: [
                'entry' => 'framework.deploy.' . $trigger,
                'area' => 'cli',
                'trigger_by' => ['type' => 'system', 'id' => null],
            ],
        );
        w_changed($change);
    }

    private function generateModeSetStamp(): string
    {
        $git = $this->tryGitShortCommit();
        if ($git !== '') {
            return 'prod-' . $git . '-' . date('YmdHis');
        }

        return 'prod-' . date('Ymd-His');
    }

    private function tryGitShortCommit(): string
    {
        try {
            if (!is_dir(BP . '.git')) {
                return '';
            }
            $out = [];
            $code = 0;
            @exec('git -C ' . escapeshellarg(BP) . ' rev-parse --short HEAD 2>/dev/null', $out, $code);
            if ($code !== 0 || $out === []) {
                return '';
            }
            $sha = preg_replace('/[^a-f0-9]/i', '', (string)$out[0]) ?? '';

            return strlen($sha) >= 7 ? substr($sha, 0, 12) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return array<string,mixed>|null */
    private function readCurrentJson(): ?array
    {
        $file = BP . self::CURRENT_JSON_REL;
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $payload */
    private function writeCurrentJson(array $payload): void
    {
        $file = BP . self::CURRENT_JSON_REL;
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(__('无法创建部署版本目录：%{1}', [$dir]));
        }
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($file, $json) === false) {
            throw new \RuntimeException(__('无法写入部署版本文件'));
        }
        @chmod($file, 0664);
    }

    private function syncEnvDeployVersion(string $deployVersion, string $workerBuildId): void
    {
        $env = Env::getInstance();
        $env->setConfig('deploy_version', $deployVersion);
        if ($workerBuildId !== '') {
            $env->setConfig('worker_build_id', $workerBuildId);
        }
        // theme_static_version 与 deploy ns 职责分离：此处只写 deploy_version，不覆盖 theme 键。
    }
}
