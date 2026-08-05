<?php

declare(strict_types=1);

namespace Weline\Server\Protocol\Http3;

use Weline\Server\Service\Edge\Gateway\GatewayBoundedCommandRunner;

/**
 * Bounded control-plane process runner for native dependency builds.
 */
final class NativeBuildProcessRunner
{
    private const INJECTION_ENVIRONMENT = [
        'BASH_ENV',
        'ENV',
        'GCONV_PATH',
        'LD_AUDIT',
        'LD_LIBRARY_PATH',
        'LD_PRELOAD',
        'PERL5OPT',
        'PYTHONINSPECT',
        'PYTHONPATH',
        'RUBYOPT',
        'DYLD_FRAMEWORK_PATH',
        'DYLD_FALLBACK_FRAMEWORK_PATH',
        'DYLD_LIBRARY_PATH',
        'DYLD_FALLBACK_LIBRARY_PATH',
        'DYLD_INSERT_LIBRARIES',
        'DYLD_ROOT_PATH',
        'DYLD_IMAGE_SUFFIX',
    ];

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     * @return array{success:bool,exit_code:int,output:string}
     */
    public function run(
        array $command,
        int $timeout,
        ?string $workingDirectory = null,
        array $environment = [],
        bool $inheritEnvironment = true,
    ): array {
        if ($command === [] || $timeout < 1) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'invalid native build command'];
        }
        if ($workingDirectory !== null && !\is_dir($workingDirectory)) {
            return ['success' => false, 'exit_code' => 127, 'output' => 'native build working directory is missing'];
        }

        try {
            $command[0] = $this->canonicalExecutable(
                (string)$command[0],
                $workingDirectory,
            );
            $effectiveEnvironment = $inheritEnvironment
                ? $this->mergedEnvironment($environment)
                : $this->sanitizedEnvironment($environment);
            $result = GatewayBoundedCommandRunner::run(
                $command,
                (float)$timeout,
                $workingDirectory,
                true,
                null,
                $effectiveEnvironment,
            );
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'exit_code' => 126,
                'output' => $throwable->getMessage(),
            ];
        }

        $exitCode = (int)$result['code'];
        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => (string)$result['output'],
        ];
    }

    /**
     * @param list<string> $names
     * @param array<string,string> $environment
     */
    public function findExecutable(array $names, array $environment = []): ?string
    {
        $path = $environment['PATH'] ?? (string)\getenv('PATH');
        $directories = \array_filter(\explode(\PATH_SEPARATOR, $path));
        $directories = \array_values(\array_unique(\array_merge(
            $directories,
            ['/opt/homebrew/bin', '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin']
        )));
        foreach ($names as $name) {
            if (\str_contains($name, \DIRECTORY_SEPARATOR)
                && \is_file($name) && \is_executable($name)
            ) {
                $canonical = @\realpath($name);
                return \is_string($canonical) ? $canonical : null;
            }
            foreach ($directories as $directory) {
                $candidate = \rtrim($directory, '\\/') . \DIRECTORY_SEPARATOR . $name;
                if (\is_file($candidate) && \is_executable($candidate)) {
                    $canonical = @\realpath($candidate);
                    if (\is_string($canonical)) {
                        return $canonical;
                    }
                }
            }
        }
        return null;
    }

    /** @param array<string,string> $overrides @return array<string,string> */
    private function mergedEnvironment(array $overrides): array
    {
        $current = \getenv();
        $environment = [];
        foreach (\is_array($current) ? $current : [] as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $environment[$name] = $value;
            }
        }
        foreach ($overrides as $name => $value) {
            $environment[$name] = $value;
        }
        return $this->sanitizedEnvironment($environment);
    }

    /** @param array<string,string> $environment @return array<string,string> */
    private function sanitizedEnvironment(array $environment): array
    {
        foreach (self::INJECTION_ENVIRONMENT as $name) {
            unset($environment[$name]);
        }
        return $environment;
    }

    private function canonicalExecutable(string $executable, ?string $workingDirectory): string
    {
        if (!\str_starts_with($executable, '/')) {
            if ($workingDirectory === null) {
                throw new \RuntimeException(
                    'Native build commands require an absolute executable.'
                );
            }
            $executable = \rtrim($workingDirectory, '/\\')
                . \DIRECTORY_SEPARATOR . $executable;
        }
        $canonical = @\realpath($executable);
        if (!\is_string($canonical)
            || !\is_file($canonical)
            || !\is_executable($canonical)
        ) {
            throw new \RuntimeException(
                'Native build executable is missing or not executable.'
            );
        }

        return $canonical;
    }
}
