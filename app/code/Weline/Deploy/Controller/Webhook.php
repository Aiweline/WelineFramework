<?php

declare(strict_types=1);

namespace Weline\Deploy\Controller;

use Weline\Deploy\Service\DeployWebhookRefResolver;
use Weline\Deploy\Service\DeployConfigService;
use Weline\Framework\App\Controller\FrontendController;

class Webhook extends FrontendController
{
    public function __construct(
        private readonly DeployConfigService    $deployConfigService,
        private readonly DeployWebhookRefResolver $refResolver
    ) {
    }

    public function deploy(): string
    {
        $config = $this->deployConfigService->getWebhookShellConfig();
        if ($config === []) {
            $config = $this->loadFileConfig(BP . 'dev/deploy/.config');
        }

        if ($this->request->isGet() && (string)$this->request->getGet('health', '') === '1') {
            $health = ['ok' => true];
            try {
                $rtFile = BP . 'var' . DS . 'deploy' . DS . 'current.json';
                if (is_file($rtFile)) {
                    $rt = json_decode((string)file_get_contents($rtFile), true);
                    if (is_array($rt)) {
                        $health['deploy_version'] = (string)($rt['deploy_version'] ?? '');
                        $health['release_id']     = (string)($rt['release_id'] ?? '');
                    }
                }
            } catch (\Throwable) {
            }
            return $this->fetchJson($health);
        }

        if (!$this->request->isPost()) {
            return $this->fetchJson(['ok' => false, 'error' => 'only POST is allowed'], 405);
        }

        $secret = (string)($config['WEBHOOK_SECRET'] ?? '');
        if ($secret === '') {
            return $this->fetchJson(['ok' => false, 'error' => 'WEBHOOK_SECRET is empty'], 500);
        }

        $rawBody = $this->rawBody();
        if (!$this->validToken($secret, $rawBody)) {
            return $this->fetchJson(['ok' => false, 'error' => 'invalid webhook token'], 403);
        }

        // --- ref 解析（支持 branch + tag）---
        $payload = json_decode($rawBody, true);
        $ref     = is_array($payload) ? $this->refResolver->extractRef($payload) : '';
        $refInfo = $this->refResolver->resolve($ref, $config);

        if ($refInfo['skipped']) {
            return $this->fetchJson([
                'ok'     => true,
                'skipped'=> true,
                'reason' => $refInfo['reason'],
                'ref'    => $ref,
            ], 202);
        }

        // 将 ref 上下文写入 runtime config
        $config['DEPLOY_REF_TYPE']        = $refInfo['type'];
        $config['DEPLOY_REF']             = $refInfo['ref'];
        $config['DEPLOY_VERSION_HINT']    = $refInfo['deploy_version_hint'] ?? '';
        $config['DEPLOY_GIT_CHECKOUT']    = $refInfo['git_checkout'] ?? '';
        $config['DEPLOY_TRIGGER']         = 'webhook';

        $script = BP . 'dev/deploy/webhook.sh';
        if (!is_file($script)) {
            return $this->fetchJson(['ok' => false, 'error' => 'webhook.sh not found'], 500);
        }

        $runtimeConfig = $this->writeRuntimeConfig($config);
        $bash          = (string)($config['WEBHOOK_BASH'] ?? 'bash');
        $command       = 'DEPLOY_CONFIG_FILE=' . escapeshellarg($runtimeConfig) . ' '
            . escapeshellarg($bash) . ' ' . escapeshellarg($script) . ' deploy --from-webhook 2>&1';
        $output    = [];
        $exitCode  = 1;
        exec($command, $output, $exitCode);
        @unlink($runtimeConfig);

        return $this->fetchJson([
            'ok'           => $exitCode === 0,
            'exit_code'    => $exitCode,
            'deploy_version_hint' => $refInfo['deploy_version_hint'],
            'git_ref_type' => $refInfo['type'],
            'output_tail'  => array_slice($output, -20),
        ], $exitCode === 0 ? 200 : 500);
    }

    private function rawBody(): string
    {
        $body = $this->request->getBodyParams();
        if (is_string($body)) {
            return $body;
        }
        if (is_array($body)) {
            return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return '';
    }

    private function validToken(string $secret, string $rawBody): bool
    {
        $giteeToken     = (string)$this->request->getHeader('X-Gitee-Token');
        $giteeTimestamp = (string)$this->request->getHeader('X-Gitee-Timestamp');
        if ($giteeToken !== '' && $giteeTimestamp !== '') {
            $computed = base64_encode(hash_hmac('sha256', $giteeTimestamp . "\n" . $secret, $secret, true));
            if (hash_equals($computed, $giteeToken)) {
                return true;
            }
        }

        if ($giteeToken !== '' && hash_equals($secret, $giteeToken)) {
            return true;
        }

        $authorization = (string)$this->request->getHeader('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1 && hash_equals($secret, $match[1])) {
            return true;
        }

        $queryToken = (string)$this->request->getGet('token', '');
        return $queryToken !== '' && hash_equals($secret, $queryToken);
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
            $key   = trim($key);
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

    private function writeRuntimeConfig(array $config): string
    {
        $file = tempnam(sys_get_temp_dir(), 'weline-deploy-webhook-');
        if ($file === false) {
            throw new \RuntimeException('cannot create runtime config');
        }

        $lines = [];
        foreach ($config as $key => $value) {
            if (is_string($key) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1) {
                $lines[] = $key . '=' . escapeshellarg((string)$value);
            }
        }
        file_put_contents($file, implode("\n", $lines) . "\n");
        @chmod($file, 0600);
        return $file;
    }
}
