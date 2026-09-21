<?php

declare(strict_types=1);

namespace Weline\Framework\Contract;

/**
 * 在不 autoload 实现类的前提下，用源码 token 探测「接口方法是否在实现文件中声明」。
 *
 * 用途：避免 `class_exists()` / Reflection 加载缺方法类时触发不可捕获 Fatal，打挂 WLS Worker。
 * 局限：父类/trait 另文件提供的方法不会被计入；对本仓 ChannelAdapter 等「实现类自声明接口方法」场景足够。
 */
final class InterfaceSourceContractProbe
{
    /**
     * @return array{
     *   ok: bool,
     *   missing: list<string>,
     *   required: list<string>,
     *   file: ?string,
     *   note: string
     * }
     */
    public static function check(string $interfaceFqcn, string $implementationFqcn, ?string $implementationFile = null): array
    {
        $empty = static fn(array $missing, ?string $file, string $note): array => [
            'ok' => $missing === [],
            'missing' => \array_values($missing),
            'required' => [],
            'file' => $file,
            'note' => $note,
        ];

        if ($interfaceFqcn === '' || $implementationFqcn === '') {
            return $empty(['*invalid_fqcn*'], null, 'invalid_fqcn');
        }

        if (!\interface_exists($interfaceFqcn, true)) {
            return $empty(['*interface_missing*'], null, 'interface_missing');
        }

        $required = [];
        foreach ((new \ReflectionClass($interfaceFqcn))->getMethods() as $method) {
            $required[$method->getName()] = true;
        }
        $requiredNames = \array_keys($required);

        $file = $implementationFile ?? self::resolveClassFile($implementationFqcn);
        if ($file === null || $file === '' || !\is_file($file)) {
            return [
                'ok' => false,
                'missing' => $requiredNames,
                'required' => $requiredNames,
                'file' => $file,
                'note' => 'implementation_file_not_found',
            ];
        }

        $source = @\file_get_contents($file);
        if (!\is_string($source) || $source === '') {
            return [
                'ok' => false,
                'missing' => $requiredNames,
                'required' => $requiredNames,
                'file' => $file,
                'note' => 'implementation_file_unreadable',
            ];
        }

        $declared = self::extractNamedFunctionNames($source);
        $missing = [];
        foreach ($requiredNames as $name) {
            if (!isset($declared[$name])) {
                $missing[] = $name;
            }
        }

        return [
            'ok' => $missing === [],
            'missing' => $missing,
            'required' => $requiredNames,
            'file' => $file,
            'note' => $missing === [] ? 'ok' : 'missing_interface_methods',
        ];
    }

    public static function resolveClassFile(string $class): ?string
    {
        foreach (\spl_autoload_functions() ?: [] as $loader) {
            if (!\is_array($loader) || !isset($loader[0]) || !\is_object($loader[0])) {
                continue;
            }
            if (!\method_exists($loader[0], 'findFile')) {
                continue;
            }
            $file = $loader[0]->findFile($class);
            if (\is_string($file) && $file !== '' && \is_file($file)) {
                return $file;
            }
        }

        if (\str_starts_with($class, 'Weline\\')) {
            $candidate = \dirname(__DIR__, 3) . '/' . \str_replace('\\', '/', $class) . '.php';
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, true>
     */
    private static function extractNamedFunctionNames(string $source): array
    {
        $tokens = @\token_get_all($source);
        if (!\is_array($tokens)) {
            return [];
        }

        $names = [];
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token) || $token[0] !== \T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (\is_array($next) && \in_array($next[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\is_array($next) && $next[0] === \T_STRING) {
                    $names[$next[1]] = true;
                }
                break;
            }
        }

        return $names;
    }
}
