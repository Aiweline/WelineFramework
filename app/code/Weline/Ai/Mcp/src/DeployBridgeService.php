<?php

declare(strict_types=1);

namespace LearningMcp;

/** Dependency-free adapter to Weline_Deploy's public, read-only CLI contract. */
final class DeployBridgeService
{
    public function __construct(
        private readonly ProcessRunner $runner,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function resolve(string $repository, array $input): array
    {
        $operation = strtolower(trim((string)($input['operation'] ?? 'preflight')));
        if (!in_array($operation, ['config', 'preflight', 'release'], true)) {
            throw new ToolException('VALIDATION_FAILED', 'operation must be config, preflight, or release');
        }
        $target = strtolower(trim((string)($input['target'] ?? 'local')));
        if (!in_array($target, ['local', 'staging', 'production'], true)) {
            throw new ToolException('VALIDATION_FAILED', 'target must be local, staging, or production');
        }

        $refType = strtolower(trim((string)($input['ref_type'] ?? '')));
        if ($refType !== '' && !in_array($refType, ['commit', 'tag'], true)) {
            throw new ToolException('VALIDATION_FAILED', 'ref_type must be commit or tag');
        }
        $ref = $this->boundedText((string)($input['ref'] ?? ''), 'ref', 256);
        $baseUrl = rtrim($this->boundedText((string)($input['base_url'] ?? ''), 'base_url', 2_048), '/');
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (!is_array($parts)
                || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || trim((string)($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                throw new ToolException(
                    'VALIDATION_FAILED',
                    'base_url must be an HTTPS origin without embedded credentials',
                );
            }
        }

        $cli = $repository . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'w';
        if (!is_file($cli)) {
            throw new ToolException(
                'DEPLOY_BRIDGE_UNAVAILABLE',
                'Weline public CLI bin/w is unavailable in this project.',
            );
        }

        $argv = [
            PHP_BINARY,
            $cli,
            'deploy:plan',
            '--json',
            '--operation=' . $operation,
            '--target=' . $target,
        ];
        if ($refType !== '') {
            $argv[] = '--ref-type=' . $refType;
        }
        if ($ref !== '') {
            $argv[] = '--ref=' . $ref;
        }
        if ($baseUrl !== '') {
            $argv[] = '--base-url=' . $baseUrl;
        }

        $process = $this->runner->run($argv, $repository, '', 30);
        if (($process['timed_out'] ?? false) === true) {
            throw new ToolException('DEPLOY_BRIDGE_TIMEOUT', 'deploy:plan timed out before returning a plan.', true);
        }

        $stdout = trim((string)($process['stdout'] ?? ''));
        try {
            $plan = json_decode($stdout, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            [$safeStdout] = Redactor::string($stdout);
            [$safeStderr] = Redactor::string(trim((string)($process['stderr'] ?? '')));
            throw new ToolException(
                'DEPLOY_BRIDGE_PROTOCOL_ERROR',
                'deploy:plan did not return valid JSON.',
                false,
                [
                    'exit_code' => (int)($process['exit_code'] ?? 1),
                    'stdout' => Text::truncate($safeStdout, 1_000),
                    'stderr' => Text::truncate($safeStderr, 1_000),
                    'json_error' => $exception->getMessage(),
                ],
            );
        }
        if (!is_array($plan) || ($plan['schema_version'] ?? '') !== 'deploy-machine-plan.v1') {
            throw new ToolException(
                'DEPLOY_BRIDGE_PROTOCOL_ERROR',
                'deploy:plan returned an unsupported schema.',
                false,
                ['exit_code' => (int)($process['exit_code'] ?? 1)],
            );
        }
        if (($plan['release_executed'] ?? null) !== false || ($plan['orchestrator_called'] ?? null) !== false) {
            throw new ToolException(
                'DEPLOY_BRIDGE_SAFETY_VIOLATION',
                'The deployment bridge refused a response that does not prove non-execution.',
            );
        }

        $plan['bridge'] = [
            'adapter' => 'weline_deploy_public_cli',
            'command' => 'deploy:plan',
            'read_only' => true,
            'exit_code' => (int)($process['exit_code'] ?? 1),
            'duration_ms' => max(0, (int)($process['duration_ms'] ?? 0)),
        ];

        return $plan;
    }

    private function boundedText(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);
        if (str_contains($value, "\0") || mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new ToolException('VALIDATION_FAILED', $field . ' is invalid or too long');
        }

        return $value;
    }
}
