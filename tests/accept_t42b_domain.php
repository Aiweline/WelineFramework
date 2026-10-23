<?php

declare(strict_types=1);

use Weline\Database\Domain\Release\ReleaseManifest;
use Weline\Database\Domain\Release\SchemaManifest;
use Weline\Database\Domain\Release\SchemaScope;

require \dirname(__DIR__) . '/app/autoload.php';

/**
 * T42b 静态验收：第二批契约 + Domain 值对象。
 */

final class T42bCheck
{
    /** @var list<string> */
    public static array $failures = [];

    public static function true(bool $condition, string $message): void
    {
        if (!$condition) {
            self::$failures[] = $message;
        }
    }

    /**
     * @param callable(): mixed $fn
     * @param class-string<Throwable> $expected
     */
    public static function throws(callable $fn, string $message, string $expected = InvalidArgumentException::class): void
    {
        try {
            $fn();
        } catch (Throwable $exception) {
            if ($exception instanceof $expected) {
                return;
            }
            self::$failures[] = $message . '（实际异常：' . $exception::class . '）';
            return;
        }
        self::$failures[] = $message . '（未抛异常）';
    }
}

function t42b_type(?ReflectionType $type): string
{
    if ($type === null) {
        return '';
    }
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        if ($type->allowsNull() && $name !== 'mixed' && $name !== 'null') {
            return '?' . $name;
        }
        return $name;
    }
    return (string)$type;
}

function t42b_default(ReflectionParameter $parameter): ?string
{
    if (!$parameter->isDefaultValueAvailable()) {
        return null;
    }
    $value = $parameter->getDefaultValue();
    if ($value === null) {
        return 'null';
    }
    if ($value === []) {
        return '[]';
    }
    if (\is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (\is_string($value)) {
        return "'" . $value . "'";
    }
    return (string)$value;
}

$interfaceNamespace = 'Weline\\Framework\\Database\\Release\\Api';

$interfaces = [
    'DatabaseReleaseCoordinatorInterface' => [
        'inspect' => ['array', [['string', 'releaseId']]],
        'plan' => ['array', [['string', 'releaseId'], ['array', 'options', '[]']]],
        'apply' => ['array', [['string', 'releaseId'], ['array', 'options', '[]']]],
        'rollback' => ['array', [
            ['string', 'releaseId'],
            ['string', 'targetReleaseId'],
            ['array', 'options', '[]'],
        ]],
    ],
    'DatabaseSnapshotInterface' => [
        'create' => ['array', [
            ['string', 'releaseId'],
            [$interfaceNamespace . '\\SchemaScopeInterface', 'scope'],
            ['array', 'options', '[]'],
        ]],
        'restore' => ['void', [['string', 'snapshotId'], ['array', 'options', '[]']]],
        'verify' => ['bool', [['string', 'snapshotId']]],
    ],
    'PhysicalReadinessInterface' => [
        'check' => ['array', [['string', 'releaseId'], ['array', 'scopeKeys', '[]']]],
        'wait' => ['array', [['string', 'releaseId'], ['int', 'timeoutSeconds', '0']]],
    ],
    'TableOwnershipRegistryInterface' => [
        'ownerOf' => ['?string', [['string', 'scopeKey'], ['string', 'tableName']]],
        'register' => ['void', [
            ['string', 'scopeKey'],
            ['string', 'tableName'],
            ['string', 'ownerModule'],
            ['string', 'source', "'model'"],
        ]],
        'assertWritable' => ['void', [
            ['string', 'scopeKey'],
            ['string', 'tableName'],
            ['string', 'moduleName'],
        ]],
    ],
];

$valueObjects = [
    'SchemaScope' => $interfaceNamespace . '\\SchemaScopeInterface',
    'ReleaseManifest' => $interfaceNamespace . '\\ReleaseManifestInterface',
    'SchemaManifest' => $interfaceNamespace . '\\SchemaManifestInterface',
];

// ---- 1. 接口反射 ----
foreach ($interfaces as $shortName => $methods) {
    $fqcn = $interfaceNamespace . '\\' . $shortName;
    if (!\interface_exists($fqcn)) {
        T42bCheck::true(false, "接口不存在：{$fqcn}");
        continue;
    }

    $reflection = new ReflectionClass($fqcn);
    T42bCheck::true($reflection->isInterface(), "不是 interface：{$fqcn}");
    T42bCheck::true(
        $reflection->getNamespaceName() === $interfaceNamespace,
        "命名空间错误：{$fqcn} => {$reflection->getNamespaceName()}"
    );
    $file = (string)$reflection->getFileName();
    T42bCheck::true(\basename($file) === $shortName . '.php', "文件位置错误：{$fqcn} => {$file}");
    $source = \is_file($file) ? (string)\file_get_contents($file) : '';
    T42bCheck::true(
        \preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $source) === 1,
        "缺少 declare(strict_types=1)：{$file}"
    );

    $actualMethods = [];
    foreach ($reflection->getMethods() as $method) {
        $actualMethods[$method->getName()] = $method;
    }

    if (\array_keys($actualMethods) !== \array_keys($methods)) {
        T42bCheck::true(false, "方法集合/顺序不一致：{$shortName} 期望 ["
            . \implode(', ', \array_keys($methods)) . '] 实际 ['
            . \implode(', ', \array_keys($actualMethods)) . ']');
        continue;
    }

    foreach ($methods as $methodName => [$expectedReturn, $expectedParams]) {
        $method = $actualMethods[$methodName];
        $actualReturn = t42b_type($method->getReturnType());
        T42bCheck::true(
            $actualReturn === $expectedReturn,
            "{$shortName}::{$methodName}() 返回类型期望 {$expectedReturn}，实际 {$actualReturn}"
        );

        $parameters = $method->getParameters();
        if (\count($parameters) !== \count($expectedParams)) {
            T42bCheck::true(false, "{$shortName}::{$methodName}() 参数数量期望 "
                . \count($expectedParams) . '，实际 ' . \count($parameters));
            continue;
        }

        foreach ($expectedParams as $index => $expectedParam) {
            [$expectedType, $expectedName] = $expectedParam;
            $expectedDefault = $expectedParam[2] ?? null;
            $parameter = $parameters[$index];

            T42bCheck::true(
                t42b_type($parameter->getType()) === $expectedType,
                "{$shortName}::{$methodName}() 参数 #{$index} 类型期望 {$expectedType}，实际 "
                . t42b_type($parameter->getType())
            );
            T42bCheck::true(
                $parameter->getName() === $expectedName,
                "{$shortName}::{$methodName}() 参数 #{$index} 名称期望 \${$expectedName}，实际 \${$parameter->getName()}"
            );
            T42bCheck::true(
                t42b_default($parameter) === $expectedDefault,
                "{$shortName}::{$methodName}() 参数 \${$expectedName} 默认值期望 "
                . \var_export($expectedDefault, true) . '，实际 ' . \var_export(t42b_default($parameter), true)
            );
        }
    }

    echo "OK interface {$shortName} (" . \count($methods) . ' methods)' . PHP_EOL;
}

// ---- 2. 值对象类型 ----
foreach ($valueObjects as $shortName => $expectedInterface) {
    $fqcn = 'Weline\\Database\\Domain\\Release\\' . $shortName;
    if (!\class_exists($fqcn)) {
        T42bCheck::true(false, "值对象不存在：{$fqcn}");
        continue;
    }
    $reflection = new ReflectionClass($fqcn);
    T42bCheck::true($reflection->isFinal(), "值对象必须是 final：{$fqcn}");
    T42bCheck::true(
        $reflection->implementsInterface($expectedInterface),
        "{$fqcn} 未实现 {$expectedInterface}"
    );
    $file = (string)$reflection->getFileName();
    T42bCheck::true(
        \str_contains($file, \DIRECTORY_SEPARATOR . 'app' . \DIRECTORY_SEPARATOR . 'code' . \DIRECTORY_SEPARATOR
            . 'Weline' . \DIRECTORY_SEPARATOR . 'Database' . \DIRECTORY_SEPARATOR . 'Domain' . \DIRECTORY_SEPARATOR . 'Release'),
        "值对象文件位置错误：{$file}"
    );
    echo "OK value object {$shortName}" . PHP_EOL;
}

// ---- 3. SchemaScope ----
$scope = new SchemaScope('default', 'weline_db', 'public', 'shard-0', 'tenant-1');
T42bCheck::true($scope->connectionName() === 'default', 'SchemaScope::connectionName 错误');
T42bCheck::true($scope->databaseName() === 'weline_db', 'SchemaScope::databaseName 错误');
T42bCheck::true($scope->schemaName() === 'public', 'SchemaScope::schemaName 错误');
T42bCheck::true($scope->shardKey() === 'shard-0', 'SchemaScope::shardKey 错误');
T42bCheck::true($scope->tenantKey() === 'tenant-1', 'SchemaScope::tenantKey 错误');

$scopeKey = $scope->key();
T42bCheck::true(
    \strlen($scopeKey) === 64 && \ctype_xdigit($scopeKey),
    'SchemaScope::key() 应为 64 位 hex'
);
T42bCheck::true(
    (new SchemaScope('default', 'weline_db', 'public', 'shard-0', 'tenant-1'))->key() === $scopeKey,
    '相同字段的 SchemaScope key 应稳定'
);
T42bCheck::true(
    (new SchemaScope('default', 'weline_db', 'public', 'shard-1', 'tenant-1'))->key() !== $scopeKey,
    'shardKey 变化应改变 SchemaScope key'
);
T42bCheck::true(
    (new SchemaScope('default', 'weline_db', 'public', 'shard-0', 'tenant-2'))->key() !== $scopeKey,
    'tenantKey 变化应改变 SchemaScope key'
);

$scopeRoundTrip = SchemaScope::fromArray($scope->toArray());
T42bCheck::true(
    $scopeRoundTrip->toArray() === $scope->toArray(),
    'SchemaScope round-trip 后 toArray 应一致'
);
T42bCheck::true($scopeRoundTrip->key() === $scopeKey, 'SchemaScope round-trip 后 key 应一致');

$emptyScope = new SchemaScope();
T42bCheck::true(
    $emptyScope->connectionName() === '' && $emptyScope->databaseName() === '',
    'SchemaScope 空 connection/database 应允许（SQLite/测试）'
);
T42bCheck::true(
    SchemaScope::fromArray([])->key() === $emptyScope->key(),
    'SchemaScope::fromArray([]) 应等价于默认 scope'
);

// ---- 4. ReleaseManifest ----
$modules = [
    'Weline_Eav' => [
        'version' => '1.2.9',
        'setup_version' => '1.2.9',
        'schema_checksum' => 'sha256:' . \str_repeat('c', 64),
    ],
];
$releaseId = 'rel-20261023-abcdef123456';
$commit = \str_repeat('a', 40);

$manifest = new ReleaseManifest(
    $releaseId,
    20261023001,
    $commit,
    'v2.5.437',
    '2.5.437',
    $modules,
    \str_repeat('b', 64),
    'snap-1',
    'backup-set-1',
    'L2',
);
T42bCheck::true($manifest->releaseId() === $releaseId, 'ReleaseManifest::releaseId 错误');
T42bCheck::true($manifest->schemaEpoch() === 20261023001, 'ReleaseManifest::schemaEpoch 错误');
T42bCheck::true($manifest->gitCommit() === $commit, 'ReleaseManifest::gitCommit 错误');
T42bCheck::true($manifest->gitTag() === 'v2.5.437', 'ReleaseManifest::gitTag 错误');
T42bCheck::true($manifest->deployVersion() === '2.5.437', 'ReleaseManifest::deployVersion 错误');
T42bCheck::true($manifest->modules() === $modules, 'ReleaseManifest::modules 错误');
T42bCheck::true(
    $manifest->schemaManifestChecksum() === \str_repeat('b', 64),
    'ReleaseManifest::schemaManifestChecksum 错误'
);
T42bCheck::true($manifest->snapshotId() === 'snap-1', 'ReleaseManifest::snapshotId 错误');
T42bCheck::true($manifest->backupSetId() === 'backup-set-1', 'ReleaseManifest::backupSetId 错误');
T42bCheck::true($manifest->rollbackPolicy() === 'L2', 'ReleaseManifest::rollbackPolicy 错误');

$manifestArray = $manifest->toArray();
T42bCheck::true(
    \array_keys($manifestArray) === [
        'release_id',
        'schema_epoch',
        'git_commit',
        'git_tag',
        'deploy_version',
        'modules',
        'schema_manifest_checksum',
        'snapshot_id',
        'backup_set_id',
        'rollback_policy',
    ],
    'ReleaseManifest::toArray 字段不符合发布清单格式'
);
T42bCheck::true(
    ReleaseManifest::fromArray($manifestArray)->toArray() === $manifestArray,
    'ReleaseManifest round-trip 后 toArray 应一致'
);

$minimalManifest = new ReleaseManifest('rel-1', 1, 'c', null, 'v1', [], \str_repeat('d', 64), null, null, 'L0');
T42bCheck::true(
    $minimalManifest->gitTag() === null
        && $minimalManifest->snapshotId() === null
        && $minimalManifest->backupSetId() === null,
    'ReleaseManifest 可空字段应允许 null'
);
T42bCheck::true(
    ReleaseManifest::fromArray($minimalManifest->toArray())->toArray() === $minimalManifest->toArray(),
    'ReleaseManifest 可空字段 round-trip 应一致'
);

T42bCheck::throws(
    static fn() => new ReleaseManifest('', 1, 'c', null, 'v1', [], \str_repeat('d', 64), null, null, 'L0'),
    'ReleaseManifest 空 release_id 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => new ReleaseManifest('rel-1', 0, 'c', null, 'v1', [], \str_repeat('d', 64), null, null, 'L0'),
    'ReleaseManifest schema_epoch=0 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => new ReleaseManifest('rel-1', 1, 'c', null, 'v1', [], \str_repeat('d', 64), null, null, 'L9'),
    'ReleaseManifest 非法 rollback_policy 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => ReleaseManifest::fromArray([
        'release_id' => 'rel-1',
        'schema_epoch' => 1,
        'rollback_policy' => 'L9',
    ]),
    'ReleaseManifest::fromArray 非法 rollback_policy 应抛 InvalidArgumentException'
);

// ---- 5. SchemaManifest ----
$schemaManifest = new SchemaManifest($releaseId, $modules, \str_repeat('e', 64));
T42bCheck::true($schemaManifest->releaseId() === $releaseId, 'SchemaManifest::releaseId 错误');
T42bCheck::true($schemaManifest->modules() === $modules, 'SchemaManifest::modules 错误');
T42bCheck::true($schemaManifest->checksum() === \str_repeat('e', 64), 'SchemaManifest::checksum 错误');

$schemaManifestArray = $schemaManifest->toArray();
T42bCheck::true(
    \array_keys($schemaManifestArray) === ['release_id', 'modules', 'checksum'],
    'SchemaManifest::toArray 字段错误'
);
T42bCheck::true(
    SchemaManifest::fromArray($schemaManifestArray)->toArray() === $schemaManifestArray,
    'SchemaManifest round-trip 后 toArray 应一致'
);

T42bCheck::throws(
    static fn() => new SchemaManifest('rel-1', [], 'abc'),
    'SchemaManifest 短 checksum 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => new SchemaManifest('rel-1', [], \str_repeat('g', 64)),
    'SchemaManifest 非 hex checksum 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => new SchemaManifest('rel-1', [], \str_repeat('e', 63)),
    'SchemaManifest 63 位 checksum 应抛 InvalidArgumentException'
);
T42bCheck::throws(
    static fn() => SchemaManifest::fromArray(['release_id' => 'rel-1', 'modules' => [], 'checksum' => 'sha256:abc']),
    'SchemaManifest::fromArray 非法 checksum 应抛 InvalidArgumentException'
);

// ---- 6. 结果 ----
if (T42bCheck::$failures !== []) {
    foreach (T42bCheck::$failures as $failure) {
        \fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    echo 'T42B DOMAIN FAIL' . PHP_EOL;
    exit(1);
}

echo 'T42B DOMAIN PASS' . PHP_EOL;
exit(0);
