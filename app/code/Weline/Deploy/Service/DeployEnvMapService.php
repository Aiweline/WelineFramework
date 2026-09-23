<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * 可选项目根映射文件驱动的部署期 SystemConfig 扭转。
 * 无 deploy.env-map.php → 跳过；文件不入库，机制在本模块。
 */
class DeployEnvMapService
{
    public const SCHEMA = 'deploy-env-map.v1';
    public const RESULT_SCHEMA = 'deploy-env-map-result.v1';
    public const MAP_FILENAME = 'deploy.env-map.php';

    /** @var callable(string):mixed|null */
    private $mapLoader;

    /** @var callable():string|null */
    private $levelResolver;

    /** @var callable(string,mixed,string,string,string):bool|null */
    private $configWriter;

    /**
     * @param callable(string):mixed|null $mapLoader
     * @param callable():string|null $levelResolver
     * @param callable(string,mixed,string,string,string):bool|null $configWriter
     */
    public function __construct(
        private readonly ?ConfigStore $configStore = null,
        ?callable $mapLoader = null,
        ?callable $levelResolver = null,
        ?callable $configWriter = null,
    ) {
        $this->mapLoader = $mapLoader;
        $this->levelResolver = $levelResolver;
        $this->configWriter = $configWriter;
    }

    /**
     * @param array{deploy_root?:string,dry_run?:bool,target_level?:string|null} $options
     * @return array<string, mixed>
     */
    public function apply(array $options = []): array
    {
        $deployRoot = $this->normalizeRoot((string)($options['deploy_root'] ?? ''));
        $dryRun = !empty($options['dry_run']);
        $mapPath = $deployRoot . DIRECTORY_SEPARATOR . self::MAP_FILENAME;

        $base = [
            'schema' => self::RESULT_SCHEMA,
            'status' => 'skipped',
            'reason' => 'map_file_missing',
            'map_path' => $mapPath,
            'target_level' => '',
            'dry_run' => $dryRun,
            'changes' => [],
            'errors' => [],
        ];

        if (!is_file($mapPath) || !is_readable($mapPath)) {
            return $base;
        }

        try {
            $map = $this->loadMap($mapPath);
        } catch (\Throwable $e) {
            return array_merge($base, [
                'status' => 'failed',
                'reason' => 'map_load_failed',
                'errors' => [$e->getMessage()],
            ]);
        }

        if (!is_array($map)) {
            return array_merge($base, [
                'status' => 'failed',
                'reason' => 'invalid_map',
                'errors' => ['部署环境映射文件必须返回数组。'],
            ]);
        }

        $schema = (string)($map['schema'] ?? '');
        if ($schema !== self::SCHEMA) {
            return array_merge($base, [
                'status' => 'failed',
                'reason' => 'invalid_schema',
                'errors' => ['部署环境映射 schema 必须为 ' . self::SCHEMA . '。'],
            ]);
        }

        $explicitLevel = isset($options['target_level']) && is_string($options['target_level'])
            ? trim($options['target_level'])
            : '';
        if ($explicitLevel === '' && isset($map['target_level']) && is_string($map['target_level'])) {
            $explicitLevel = trim($map['target_level']);
        }
        $targetLevel = $explicitLevel !== ''
            ? $this->normalizeLevel($explicitLevel)
            : $this->resolveDeployLevel();

        $rules = $map['rules'] ?? null;
        if (!is_array($rules)) {
            return array_merge($base, [
                'status' => 'failed',
                'reason' => 'invalid_rules',
                'target_level' => $targetLevel,
                'errors' => ['部署环境映射 rules 必须为数组。'],
            ]);
        }

        $changes = [];
        $errors = [];

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) {
                $errors[] = '规则 #' . $index . ' 不是数组。';
                continue;
            }

            $module = trim((string)($rule['module'] ?? ''));
            $area = strtolower(trim((string)($rule['area'] ?? ConfigReader::area_BACKEND)));
            $key = trim((string)($rule['key'] ?? ''));
            $scopesRaw = $rule['scopes'] ?? null;
            $valuesByLevel = $rule['values_by_level'] ?? null;

            if ($module === '' || $key === '' || !is_array($scopesRaw) || $scopesRaw === [] || !is_array($valuesByLevel)) {
                $errors[] = '规则 #' . $index . ' 缺少 module/key/scopes/values_by_level。';
                continue;
            }

            if ($area !== ConfigReader::area_BACKEND && $area !== ConfigReader::area_FRONTEND) {
                $errors[] = '规则 #' . $index . ' area 无效。';
                continue;
            }

            if (!array_key_exists($targetLevel, $valuesByLevel)) {
                continue;
            }

            $value = $valuesByLevel[$targetLevel];
            if (!is_scalar($value) && $value !== null) {
                $errors[] = '规则 #' . $index . ' 档位 ' . $targetLevel . ' 的值必须是标量。';
                continue;
            }
            $valueString = $value === null ? '' : (string)$value;

            foreach ($scopesRaw as $scopeItem) {
                if (!is_string($scopeItem) && !is_int($scopeItem)) {
                    $errors[] = '规则 #' . $index . ' 含非字符串 scope。';
                    continue;
                }
                try {
                    $scope = $this->normalizeScope((string)$scopeItem);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $e->getMessage();
                    continue;
                }

                $written = false;
                if (!$dryRun) {
                    try {
                        $written = $this->writeScopedConfig($key, $valueString, $module, $area, $scope);
                    } catch (\Throwable $e) {
                        $errors[] = '写入 ' . $key . ' @ ' . $scope . ' 失败：' . $e->getMessage();
                        continue;
                    }
                    if (!$written) {
                        $errors[] = '写入 ' . $key . ' @ ' . $scope . ' 返回失败。';
                        continue;
                    }
                }

                $changes[] = [
                    'module' => $module,
                    'area' => $area,
                    'key' => $key,
                    'scope' => $scope,
                    'value' => $valueString,
                    'written' => $dryRun ? false : $written,
                ];
            }
        }

        if ($errors !== [] && $changes === []) {
            return [
                'schema' => self::RESULT_SCHEMA,
                'status' => 'failed',
                'reason' => 'apply_failed',
                'map_path' => $mapPath,
                'target_level' => $targetLevel,
                'dry_run' => $dryRun,
                'changes' => [],
                'errors' => $errors,
            ];
        }

        return [
            'schema' => self::RESULT_SCHEMA,
            'status' => 'applied',
            'reason' => $dryRun ? 'dry_run' : 'ok',
            'map_path' => $mapPath,
            'target_level' => $targetLevel,
            'dry_run' => $dryRun,
            'changes' => $changes,
            'errors' => $errors,
        ];
    }

    public function normalizeLevel(string $raw): string
    {
        $mode = strtolower(trim($raw));
        return match ($mode) {
            'dev', 'local', 'test' => 'dev',
            'pre', 'staging' => 'staging',
            'prod', 'production' => 'prod',
            default => in_array($mode, ['dev', 'staging', 'prod'], true) ? $mode : 'prod',
        };
    }

    public function resolveDeployLevel(): string
    {
        if ($this->levelResolver !== null) {
            return $this->normalizeLevel((string)($this->levelResolver)());
        }

        $fromEnvFile = $this->readDeployModeFromEnvFile();
        if ($fromEnvFile !== '') {
            return $this->normalizeLevel($fromEnvFile);
        }

        $effective = strtolower(trim((string)Env::system('deploy', '')));
        if ($effective !== '') {
            return $this->normalizeLevel($effective);
        }

        return 'prod';
    }

    public function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'global') {
            return ConfigReader::SCOPE_GLOBAL;
        }
        if ($scope === '*') {
            throw new \InvalidArgumentException('deploy-env-map 不支持 scopes=*，请显式列出 storage scope。');
        }
        if (!preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/', $scope)) {
            throw new \InvalidArgumentException('非法 scope：' . $scope . '（需 global 或三段 storage scope）。');
        }

        return $scope;
    }

    private function normalizeRoot(string $deployRoot): string
    {
        $root = trim($deployRoot);
        if ($root === '') {
            $root = rtrim((string)BP, "\\/");
        } else {
            $root = rtrim($root, "\\/");
        }

        return $root !== '' ? $root : (string)BP;
    }

    private function loadMap(string $mapPath): mixed
    {
        if ($this->mapLoader !== null) {
            return ($this->mapLoader)($mapPath);
        }

        /** @noinspection PhpIncludeInspection */
        return include $mapPath;
    }

    private function writeScopedConfig(
        string $key,
        string $value,
        string $module,
        string $area,
        string $scope,
    ): bool {
        if ($this->configWriter !== null) {
            return (bool)($this->configWriter)($key, $value, $module, $area, $scope);
        }

        $store = $this->configStore ?? new ConfigStore();

        // 必须显式 default：normalizeLocale(null) 会落到当前用户语种，
        // 而 ConfigReader / Smtp Helper 按 LOCALE_DEFAULT 读取，会读不到。
        return $store->setScopedConfig(
            $key,
            $value,
            $module,
            $area,
            $scope,
            ConfigReader::LOCALE_DEFAULT,
        );
    }

    private function readDeployModeFromEnvFile(): string
    {
        $envFile = Env::path_ENV_FILE;
        if (!is_file($envFile)) {
            return '';
        }

        try {
            $config = include $envFile;
        } catch (\Throwable) {
            return '';
        }

        if (!is_array($config)) {
            return '';
        }

        $system = is_array($config['system'] ?? null) ? $config['system'] : [];
        foreach ([$system['deploy'] ?? null, $config['deploy'] ?? null] as $mode) {
            $mode = strtolower(trim((string)$mode));
            if ($mode !== '') {
                return $mode;
            }
        }

        return '';
    }
}
