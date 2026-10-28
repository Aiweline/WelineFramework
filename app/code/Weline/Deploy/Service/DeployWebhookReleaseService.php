<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

class DeployWebhookReleaseService
{
    public function __construct(
        private readonly DeployConfigService $configService,
        private readonly DeployWebhookRefResolver $refResolver,
        private readonly DeployWebhookAsyncDispatcher $asyncDispatcher,
        private readonly DeployProjectProfileService $profileService,
        private readonly DeployReleaseRuntimeService $runtimeService
    ) {
    }

    public function isWebhookIngressEnabled(): bool
    {
        return $this->configService->isWebhookEnabled();
    }

    public function loadConfig(): array
    {
        $config = $this->configService->getWebhookShellConfig();
        if ($config !== []) {
            return $config;
        }

        return $this->loadFileConfig(BP . 'dev/deploy/.config');
    }

    public function healthPayload(array $config = [], array $requestContext = []): array
    {
        $baseConfig = $config !== [] ? $config : $this->loadConfig();
        $releaseContext = $this->emptyReleaseContext();
        $effectiveConfig = $baseConfig;
        if ($this->hasProjectContext($requestContext)) {
            $releaseContext = $this->profileService->getReleaseContext($requestContext);
            $effectiveConfig = $this->profileService->buildReleaseConfigForContext($releaseContext, $baseConfig);
        }
        $current = $this->runtimeService->getCurrent($this->runtimeRootFromConfig($effectiveConfig));
        $currentContext = is_array($current) ? $current : [];
        $health = [
            'ok' => true,
            'release_recorded' => is_array($current),
        ];
        try {
            if (is_array($current)) {
                $health['deploy_version'] = (string)($current['deploy_version'] ?? '');
                $health['release_id'] = (string)($current['release_id'] ?? '');
            }

            foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
                $value = (string)($currentContext[$key] ?? $releaseContext[$key] ?? '');
                if ($value !== '') {
                    $health[$key] = $value;
                }
            }
            foreach (['deploy_mode_source', 'appstore_environment', 'appstore_platform_url', 'appstore_platform_url_source'] as $key) {
                $value = (string)($currentContext[$key] ?? '');
                if ($value !== '') {
                    $health[$key] = $value;
                }
            }
        } catch (\Throwable) {
        }

        return $health;
    }

    public function isValidToken(
        string $secret,
        string $rawBody,
        string $giteeToken = '',
        string $giteeTimestamp = '',
        string $authorization = '',
        string $queryToken = '',
        string $githubSignature = ''
    ): bool {
        if ($giteeToken !== '' && $giteeTimestamp !== '') {
            $computed = base64_encode(hash_hmac('sha256', $giteeTimestamp . "\n" . $secret, $secret, true));
            if (hash_equals($computed, $giteeToken)) {
                return true;
            }
        }

        if ($giteeToken !== '' && hash_equals($secret, $giteeToken)) {
            return true;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1 && hash_equals($secret, $match[1])) {
            return true;
        }

        if ($githubSignature !== '' && str_starts_with($githubSignature, 'sha256=')) {
            $computed = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
            if (hash_equals($computed, $githubSignature)) {
                return true;
            }
        }

        return $queryToken !== '' && hash_equals($secret, $queryToken);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $requestContext
     * @return array{config:array<string,mixed>,context:array{profile_key:string,project_id:string,domain:string,project_type:string}}
     */
    public function resolveEffectiveConfigForWebhook(string $rawBody, array $config, array $requestContext = []): array
    {
        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $releaseContext = $this->profileService->getReleaseContext(
            $this->extractProjectContext($payload, $requestContext)
        );

        return [
            'config' => $this->profileService->buildReleaseConfigForContext($releaseContext, $config),
            'context' => $releaseContext,
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function releaseFromWebhook(string $rawBody, array $config, array $requestContext = []): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [
                'status' => 400,
                'payload' => ['ok' => false, 'error' => 'invalid webhook payload'],
            ];
        }

        $resolved = $this->resolveEffectiveConfigForWebhook($rawBody, $config, $requestContext);
        $releaseContext = $resolved['context'];
        $effectiveConfig = $resolved['config'];
        $ref = $this->resolveWebhookRef($payload);
        $refInfo = $this->refResolver->resolve($ref, $effectiveConfig);
        if ($refInfo['skipped']) {
            return [
                'status' => 202,
                'payload' => [
                    'ok' => true,
                    'skipped' => true,
                    'reason' => $refInfo['reason'],
                    'ref' => $ref,
                    'profile_key' => $releaseContext['profile_key'],
                    'project_id' => $releaseContext['project_id'],
                    'domain' => $releaseContext['domain'],
                ],
            ];
        }

        $force = (string)($effectiveConfig['DEPLOY_FORCE_RESET'] ?? '0') === '1';
        // HTTP must ACK within platform read timeouts (e.g. Gitee ~15s). Full release
        // (prod site backup + git + setup:upgrade) runs detached via deploy:release.
        $dispatched = $this->asyncDispatcher->dispatchRelease(
            (string)$refInfo['ref'],
            $force,
            true
        );
        if (empty($dispatched['ok'])) {
            return [
                'status' => 500,
                'payload' => [
                    'ok' => false,
                    'accepted' => false,
                    'async' => true,
                    'error' => 'async_dispatch_failed',
                    'message' => (string)($dispatched['error'] ?? 'spawn_failed'),
                    'deploy_version_hint' => $refInfo['deploy_version_hint'],
                    'git_ref_type' => $refInfo['type'],
                    'git_ref' => $refInfo['ref'],
                    'profile_key' => $releaseContext['profile_key'],
                    'project_id' => $releaseContext['project_id'],
                    'domain' => $releaseContext['domain'],
                    'log_path' => (string)($dispatched['log_path'] ?? ''),
                ],
            ];
        }

        return [
            'status' => 202,
            'payload' => [
                'ok' => true,
                'accepted' => true,
                'async' => true,
                'pid' => $dispatched['pid'],
                'job_id' => (string)($dispatched['job_id'] ?? ''),
                'transport' => (string)($dispatched['transport'] ?? 'queue'),
                'log_path' => (string)($dispatched['log_path'] ?? ''),
                'deploy_version_hint' => $refInfo['deploy_version_hint'],
                'git_ref_type' => $refInfo['type'],
                'git_ref' => $refInfo['ref'],
                'profile_key' => $releaseContext['profile_key'],
                'project_id' => $releaseContext['project_id'],
                'domain' => $releaseContext['domain'],
                'message' => 'release_queued',
            ],
        ];
    }

    /**
     * Webhook body → release ref. Gitee/GitHub send `ref`; some senders only carry an explicit
     * tag (`tag` / `tag_name`). Such a tag is normalized to refs/tags/<tag> so the dispatched
     * release task receives the same ref a tag push would give — no polling involved.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveWebhookRef(array $payload): string
    {
        $ref = trim($this->refResolver->extractRef($payload));
        if ($ref !== '') {
            return $ref;
        }

        foreach (['tag', 'tag_name'] as $key) {
            $value = $payload[$key] ?? '';
            $tag = is_scalar($value) ? trim((string)$value) : '';
            if ($tag !== '') {
                return str_starts_with($tag, 'refs/tags/') ? $tag : 'refs/tags/' . $tag;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $requestContext
     * @return array<string, mixed>
     */
    private function extractProjectContext(array $payload, array $requestContext): array
    {
        $nested = [];
        foreach (['wls', 'wls_project', 'project'] as $key) {
            if (is_array($payload[$key] ?? null)) {
                $nested[] = $payload[$key];
            }
        }

        $context = [];
        foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
            $value = $this->scalarContextValue($requestContext, $key);
            if ($value === '') {
                $value = $this->scalarContextValue($payload, $key);
            }
            if ($value === '') {
                foreach ($nested as $candidate) {
                    $value = $this->scalarContextValue($candidate, $key);
                    if ($value !== '') {
                        break;
                    }
                }
            }
            if ($value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalarContextValue(array $source, string $key): string
    {
        $upperKey = strtoupper($key);
        $value = $source[$key] ?? $source[$upperKey] ?? '';
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function runtimeRootFromConfig(array $config): ?string
    {
        $deployRoot = trim((string)($config['DEPLOY_ROOT'] ?? ''));
        return $deployRoot !== '' ? $deployRoot : null;
    }

    /**
     * @return array{profile_key:string,project_id:string,domain:string,project_type:string}
     */
    private function emptyReleaseContext(): array
    {
        return [
            'profile_key' => '',
            'project_id' => '',
            'domain' => '',
            'project_type' => '',
        ];
    }

    private function hasProjectContext(array $context): bool
    {
        foreach (['profile_key', 'project_id', 'domain', 'project_type'] as $key) {
            $value = $context[$key] ?? '';
            if (is_scalar($value) && trim((string)$value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function loadFileConfig(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $config = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $value = trim($value, "'\"");
            }
            if ($key !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }
}
