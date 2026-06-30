<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

class DeployProjectCommandPolicyService
{
    /**
     * @var array<string, true>
     */
    private const ALLOWED_COMPOSER_FLAGS = [
        '--no-dev' => true,
        '--prefer-dist' => true,
        '--optimize-autoloader' => true,
        '--classmap-authoritative' => true,
        '--no-interaction' => true,
        '--no-progress' => true,
        '--no-scripts' => true,
        '--ansi' => true,
        '--no-ansi' => true,
    ];

    /**
     * @var array<string, array<string, true>>
     */
    private const ALLOWED_POST_COMMANDS = [
        'setup:upgrade' => [
            '--route' => true,
            '--model' => true,
            '--sync' => true,
            '--skip-env-check' => true,
            '--skip-reflection-compile' => true,
            '--stage=route_update' => true,
        ],
        'cache:clear' => [],
        'server:reload' => [
            '-r' => true,
        ],
    ];

    public function normalizeComposerCommand(string $command): string
    {
        $command = $this->normalizeSingleLine($command);
        if ($command === '') {
            return '';
        }

        $this->assertNoShellControlTokens($command, false);
        $tokens = $this->splitPlainTokens($command);
        if (($tokens[0] ?? '') !== 'composer' || ($tokens[1] ?? '') !== 'install') {
            throw new \InvalidArgumentException((string)__('Composer 命令只允许 composer install。'));
        }

        foreach (\array_slice($tokens, 2) as $token) {
            if (!isset(self::ALLOWED_COMPOSER_FLAGS[$token])) {
                throw new \InvalidArgumentException((string)__('Composer 命令包含未允许的参数：%{1}', [$token]));
            }
        }

        return \implode(' ', $tokens);
    }

    public function normalizePostDeployCommand(string $command): string
    {
        $command = $this->normalizeSingleLine($command);
        if ($command === '') {
            return '';
        }

        $this->assertNoShellControlTokens($command, true);
        $segments = \preg_split('/\s*&&\s*/', $command) ?: [];
        $normalized = [];
        foreach ($segments as $segment) {
            $segment = \trim($segment);
            if ($segment === '') {
                throw new \InvalidArgumentException((string)__('部署后命令包含空命令片段。'));
            }
            $normalized[] = $this->normalizePostDeployCommandSegment($segment);
        }

        return \implode(' && ', $normalized);
    }

    public function normalizeRollbackRef(string $ref): string
    {
        $ref = $this->normalizeSingleLine($ref);
        if ($ref === '') {
            return '';
        }

        if (\preg_match('/[\s`|;<>"\'\\\\]/', $ref) === 1) {
            throw new \InvalidArgumentException((string)__('回滚参考包含不允许的 shell 控制字符。'));
        }

        if (\str_starts_with($ref, '-') || \str_contains($ref, '..') || \str_contains($ref, '//') || \str_contains($ref, '@{')) {
            throw new \InvalidArgumentException((string)__('回滚参考不能以短横线开头，也不能包含 ..、// 或 @{。'));
        }

        if (\str_ends_with($ref, '.') || \str_ends_with($ref, '/')) {
            throw new \InvalidArgumentException((string)__('回滚参考不能以点号或斜杠结尾。'));
        }

        if (\preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/:@-]{0,190}$/', $ref) !== 1) {
            throw new \InvalidArgumentException((string)__('回滚参考格式不在 WLS 白名单内。'));
        }

        return \mb_substr($ref, 0, 190);
    }

    public function rollbackRefKind(string $ref): string
    {
        $ref = $this->normalizeRollbackRef($ref);
        if ($ref === '') {
            return '';
        }

        if (\preg_match('/^[a-f0-9]{7,40}$/i', $ref) === 1) {
            return 'commit';
        }

        if (\str_starts_with($ref, 'refs/heads/')) {
            return 'branch';
        }

        return 'tag';
    }

    /**
     * @return array{composer_examples:string[],post_deploy_examples:string[],rollback_examples:string[],blocked_hint:string,rollback_hint:string}
     */
    public function getPanelSummary(): array
    {
        return [
            'composer_examples' => [
                'composer install --no-dev --prefer-dist --optimize-autoloader',
                'composer install --no-dev --prefer-dist --no-interaction --no-progress',
            ],
            'post_deploy_examples' => [
                'php bin/w setup:upgrade --route',
                'php bin/w server:reload -r',
                'php bin/w setup:upgrade --route && php bin/w server:reload -r',
            ],
            'rollback_examples' => [
                'last-stable',
                'refs/tags/v1.2.3',
                'refs/heads/release-rollback',
                'a1b2c3d',
            ],
            'rollback_hint' => (string)__('回滚参考仅支持 tag、refs/tags/name、refs/heads/name 或 7-40 位 commit SHA；禁止 shell 控制字符。'),
            'blocked_hint' => (string)__('禁止管道、重定向、分号、单独 &、引号、命令替换和任意脚本路径。'),
        ];
    }

    private function normalizePostDeployCommandSegment(string $segment): string
    {
        $tokens = $this->splitPlainTokens($segment);
        if (($tokens[0] ?? '') !== 'php' || ($tokens[1] ?? '') !== 'bin/w') {
            throw new \InvalidArgumentException((string)__('部署后命令必须以 php bin/w 开头。'));
        }

        $command = (string)($tokens[2] ?? '');
        if (!isset(self::ALLOWED_POST_COMMANDS[$command])) {
            throw new \InvalidArgumentException((string)__('部署后命令未在 WLS 维护命令白名单内：%{1}', [$command !== '' ? $command : '-']));
        }

        $allowedFlags = self::ALLOWED_POST_COMMANDS[$command];
        foreach (\array_slice($tokens, 3) as $token) {
            if (!isset($allowedFlags[$token])) {
                throw new \InvalidArgumentException((string)__('部署后命令包含未允许的参数：%{1}', [$token]));
            }
        }

        return \implode(' ', $tokens);
    }

    private function normalizeSingleLine(string $command): string
    {
        $command = \str_replace(["\r", "\n"], ' ', $command);
        $command = \preg_replace('/\s+/', ' ', $command) ?? $command;
        return \trim($command);
    }

    private function assertNoShellControlTokens(string $command, bool $allowAndChain): void
    {
        if (\preg_match('/[`|;<>"\']/', $command) === 1) {
            throw new \InvalidArgumentException((string)__('命令包含不允许的 shell 控制字符。'));
        }
        if (\str_contains($command, '$(') || \str_contains($command, '${')) {
            throw new \InvalidArgumentException((string)__('命令包含不允许的命令替换表达式。'));
        }

        $withoutAllowedChain = $allowAndChain ? \str_replace('&&', '', $command) : $command;
        if (\str_contains($withoutAllowedChain, '&')) {
            throw new \InvalidArgumentException((string)__('命令包含不允许的 & 控制符。'));
        }
    }

    /**
     * @return string[]
     */
    private function splitPlainTokens(string $command): array
    {
        $tokens = \preg_split('/\s+/', \trim($command)) ?: [];
        return \array_values(\array_filter($tokens, static fn(string $token): bool => $token !== ''));
    }
}
