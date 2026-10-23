<?php

declare(strict_types=1);

/**
 * T42a 静态验收：Framework RSSE 契约（第一批）。
 *
 * 只做反射校验：接口存在、命名空间正确、方法名/顺序/签名与 brief 一致。
 */

require \dirname(__DIR__) . '/app/autoload.php';

const T42A_NAMESPACE = 'Weline\\Framework\\Database\\Release\\Api';

/**
 * 期望签名：接口 => [方法名 => [返回类型, [[参数类型, 参数名, 默认值表示(可选)]]]]
 */
$expectations = [
    'SchemaScopeInterface' => [
        'connectionName' => ['string', []],
        'databaseName' => ['string', []],
        'schemaName' => ['string', []],
        'shardKey' => ['string', []],
        'tenantKey' => ['string', []],
        'key' => ['string', []],
    ],
    'ReleaseManifestInterface' => [
        'releaseId' => ['string', []],
        'schemaEpoch' => ['int', []],
        'gitCommit' => ['string', []],
        'gitTag' => ['?string', []],
        'deployVersion' => ['string', []],
        'modules' => ['array', []],
        'schemaManifestChecksum' => ['string', []],
        'snapshotId' => ['?string', []],
        'backupSetId' => ['?string', []],
        'rollbackPolicy' => ['string', []],
        'toArray' => ['array', []],
    ],
    'SchemaManifestInterface' => [
        'releaseId' => ['string', []],
        'modules' => ['array', []],
        'checksum' => ['string', []],
        'toArray' => ['array', []],
    ],
    'SchemaJournalInterface' => [
        'append' => ['int', [['array', 'event']]],
        'events' => ['array', [
            ['string', 'releaseId'],
            ['?string', 'operationId', 'null'],
            ['?string', 'scopeKey', 'null'],
        ]],
        'latestState' => ['?string', [['string', 'scopeKey'], ['string', 'nodeId']]],
    ],
    'BackupCatalogInterface' => [
        'createSet' => ['int', [['array', 'set']]],
        'addObject' => ['int', [['int', 'setId'], ['array', 'object']]],
        'findSet' => ['?array', [['string', 'releaseId'], ['?string', 'scopeKey', 'null']]],
        'objects' => ['array', [['int', 'setId'], ['array', 'filter', '[]']]],
    ],
    'DataTransformInterface' => [
        'id' => ['string', []],
        'scope' => [T42A_NAMESPACE . '\\SchemaScopeInterface', []],
        'fromVersion' => ['string', []],
        'toVersion' => ['string', []],
        'table' => ['string', []],
        'estimateRows' => ['int', []],
        'plan' => ['array', []],
        'applyBatch' => ['array', [['array', 'cursor'], ['int', 'limit']]],
        'verify' => ['array', [['array', 'cursor']]],
        'inverse' => ['?self', []],
        'requiresSnapshot' => ['bool', []],
        'batchSize' => ['int', []],
        'throttleMs' => ['int', []],
    ],
];

function t42a_type(?ReflectionType $type): string
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

function t42a_default(ReflectionParameter $parameter): ?string
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

$errors = [];
$passed = [];

foreach ($expectations as $shortName => $methods) {
    $fqcn = T42A_NAMESPACE . '\\' . $shortName;
    if (!\interface_exists($fqcn)) {
        $errors[] = "接口不存在：{$fqcn}";
        continue;
    }

    $reflection = new ReflectionClass($fqcn);
    if (!$reflection->isInterface()) {
        $errors[] = "不是 interface：{$fqcn}";
        continue;
    }
    if ($reflection->getNamespaceName() !== T42A_NAMESPACE) {
        $errors[] = "命名空间错误：{$fqcn} => {$reflection->getNamespaceName()}";
        continue;
    }

    $file = (string)$reflection->getFileName();
    if (\basename($file) !== $shortName . '.php') {
        $errors[] = "文件位置错误：{$fqcn} => {$file}";
    }
    $source = \is_file($file) ? (string)\file_get_contents($file) : '';
    if (\preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)/', $source) !== 1) {
        $errors[] = "缺少 declare(strict_types=1)：{$file}";
    }

    $actualMethods = [];
    foreach ($reflection->getMethods() as $method) {
        $actualMethods[$method->getName()] = $method;
    }

    if (\array_keys($actualMethods) !== \array_keys($methods)) {
        $errors[] = "方法集合/顺序不一致：{$shortName} 期望 [" . \implode(', ', \array_keys($methods))
            . '] 实际 [' . \implode(', ', \array_keys($actualMethods)) . ']';
        continue;
    }

    foreach ($methods as $methodName => [$expectedReturn, $expectedParams]) {
        $method = $actualMethods[$methodName];
        $actualReturn = t42a_type($method->getReturnType());
        if ($actualReturn !== $expectedReturn) {
            $errors[] = "{$shortName}::{$methodName}() 返回类型期望 {$expectedReturn}，实际 {$actualReturn}";
        }

        $parameters = $method->getParameters();
        if (\count($parameters) !== \count($expectedParams)) {
            $errors[] = "{$shortName}::{$methodName}() 参数数量期望 " . \count($expectedParams)
                . '，实际 ' . \count($parameters);
            continue;
        }

        foreach ($expectedParams as $index => $expectedParam) {
            [$expectedType, $expectedName] = $expectedParam;
            $expectedDefault = $expectedParam[2] ?? null;
            $parameter = $parameters[$index];

            $actualType = t42a_type($parameter->getType());
            if ($actualType !== $expectedType) {
                $errors[] = "{$shortName}::{$methodName}() 参数 #{$index} 类型期望 {$expectedType}，实际 {$actualType}";
            }
            if ($parameter->getName() !== $expectedName) {
                $errors[] = "{$shortName}::{$methodName}() 参数 #{$index} 名称期望 \${$expectedName}，实际 \${$parameter->getName()}";
            }
            $actualDefault = t42a_default($parameter);
            if ($actualDefault !== $expectedDefault) {
                $errors[] = "{$shortName}::{$methodName}() 参数 \${$expectedName} 默认值期望 "
                    . \var_export($expectedDefault, true) . '，实际 ' . \var_export($actualDefault, true);
            }
        }
    }

    $passed[$shortName] = \count($methods);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        \fwrite(STDERR, 'FAIL ' . $error . PHP_EOL);
    }
    echo 'T42A CONTRACTS FAIL' . PHP_EOL;
    exit(1);
}

foreach ($passed as $shortName => $methodCount) {
    echo "OK {$shortName} ({$methodCount} methods)" . PHP_EOL;
}

echo 'T42A CONTRACTS PASS' . PHP_EOL;
exit(0);
