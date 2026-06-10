<?php

declare(strict_types=1);

namespace Weline\Deploy\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;

/**
 * 协助生成框架 Webhook 访问密钥、部署 SSH 密钥，并输出 curl 测试命令与配置指引。
 */
class DeployWebhookSetupService
{
    public const SSH_KEY_BASENAME = 'weline_deploy_ed25519';

    public function __construct(
        private readonly DeployConfigService $deployConfigService,
        private readonly DeployWebhookRouteService $routeService
    ) {
    }

    public function sshKeyDirectory(): string
    {
        return Env::VAR_DIR . 'deploy' . DS . 'ssh' . DS;
    }

    public function sshPrivateKeyPath(): string
    {
        return $this->sshKeyDirectory() . self::SSH_KEY_BASENAME;
    }

    public function sshPublicKeyPath(): string
    {
        return $this->sshPrivateKeyPath() . '.pub';
    }

    /**
     * @return array{secret:string,generated:bool,previous_configured:bool}
     */
    public function resolveWebhookSecret(bool $force): array
    {
        $settings = $this->deployConfigService->getSettings();
        $existing = trim((string) ($settings['webhook_secret'] ?? ''));
        $configured = $existing !== '';

        if ($configured && !$force) {
            return [
                'secret' => $existing,
                'generated' => false,
                'previous_configured' => true,
            ];
        }

        return [
            'secret' => $this->generateSecret(),
            'generated' => true,
            'previous_configured' => $configured,
        ];
    }

    public function generateSecret(): string
    {
        return bin2hex(random_bytes(24));
    }

    public function saveWebhookSecret(string $secret): bool
    {
        $settings = $this->deployConfigService->getSettings();
        $settings['webhook_secret'] = trim($secret);
        return $this->deployConfigService->saveSettings($settings);
    }

    /**
     * @return array{path:string,generated:bool,previous_configured:bool}
     */
    public function resolveWebhookPath(bool $force): array
    {
        $settings = $this->deployConfigService->getSettings();
        $existing = $this->routeService->normalizePath((string) ($settings['webhook_path'] ?? ''));
        $configured = $existing !== '' && !$this->routeService->isLegacyOrEmptyPath($existing);

        if ($configured && !$force) {
            return [
                'path' => $existing,
                'generated' => false,
                'previous_configured' => true,
            ];
        }

        return [
            'path' => $this->routeService->generatePath(),
            'generated' => true,
            'previous_configured' => $configured,
        ];
    }

    public function saveWebhookPath(string $path): bool
    {
        $settings = $this->deployConfigService->getSettings();
        $settings['webhook_path'] = $this->routeService->normalizePath($path);
        return $this->deployConfigService->saveSettings($settings);
    }

    public function buildVersionUrl(array $settings, ?string $baseUrlOverride): string
    {
        $webhookUrl = $this->buildWebhookUrl($settings, null, $baseUrlOverride);
        if ($webhookUrl === '') {
            return '';
        }

        return $webhookUrl . '/version';
    }

    /**
     * @return array{
     *     ok:bool,
     *     generated:bool,
     *     private_key:string,
     *     public_key:string,
     *     public_key_content:string,
     *     message?:string
     * }
     */
    public function ensureDeploySshKeyPair(bool $force): array
    {
        $private = $this->sshPrivateKeyPath();
        $public = $this->sshPublicKeyPath();
        $exists = is_file($private) && is_file($public);

        if ($exists && !$force) {
            return [
                'ok' => true,
                'generated' => false,
                'private_key' => $private,
                'public_key' => $public,
                'public_key_content' => trim((string) file_get_contents($public)),
            ];
        }

        if ($exists && $force) {
            @unlink($private);
            @unlink($public);
        }

        $dir = $this->sshKeyDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return [
                'ok' => false,
                'generated' => false,
                'private_key' => $private,
                'public_key' => $public,
                'public_key_content' => '',
                'message' => (string) __('无法创建目录：%{1}', [$dir]),
            ];
        }

        if ($this->generateSshKeyPairWithSshKeygen($private, $public)) {
            return [
                'ok' => true,
                'generated' => true,
                'private_key' => $private,
                'public_key' => $public,
                'public_key_content' => trim((string) file_get_contents($public)),
            ];
        }

        return [
            'ok' => false,
            'generated' => false,
            'private_key' => $private,
            'public_key' => $public,
            'public_key_content' => '',
            'message' => (string) __('无法生成 SSH 密钥，请确认服务器已安装 ssh-keygen。'),
        ];
    }

    /**
     * @return array{url:string,source:string}
     */
    public function resolveSiteBaseUrlInfo(?string $cliOverride = null): array
    {
        if ($cliOverride !== null && trim($cliOverride) !== '') {
            return [
                'url' => rtrim(trim($cliOverride), '/'),
                'source' => (string) __('命令行 --base-url'),
            ];
        }

        foreach ($this->collectSiteBaseUrlCandidates() as $candidate) {
            return $candidate;
        }

        return ['url' => '', 'source' => ''];
    }

    /**
     * @return list<array{url:string,source:string}>
     */
    private function collectSiteBaseUrlCandidates(): array
    {
        $candidates = [];

        $push = static function (string $url, string $source) use (&$candidates): void {
            $url = rtrim(trim($url), '/');
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                return;
            }
            foreach ($candidates as $existing) {
                if (strcasecmp($existing['url'], $url) === 0) {
                    return;
                }
            }
            $candidates[] = ['url' => $url, 'source' => $source];
        };

        try {
            $push(WelineEnv::getWebsiteUrl(), (string) __('运行环境 website_url'));
        } catch (\Throwable) {
        }

        foreach (['website_url', 'website.url', 'route.website_url'] as $key) {
            try {
                $push((string) w_env($key, ''), (string) __('环境配置 %{1}', [$key]));
            } catch (\Throwable) {
            }
        }

        try {
            $push((string) Env::get('website_url', ''), (string) __('app/etc/env.php website_url'));
            $push((string) Env::get('website.url', ''), (string) __('app/etc/env.php website.url'));
        } catch (\Throwable) {
        }

        $push($this->guessWebsiteUrlFromStore(), (string) __('网站管理默认站点 URL'));

        return $candidates;
    }

    private function guessWebsiteUrlFromStore(): string
    {
        if (!class_exists(\Weline\Websites\Model\Website::class)) {
            return '';
        }

        try {
            /** @var \Weline\Websites\Model\Website $website */
            $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $row = $website->clear()
                ->where(\Weline\Websites\Model\Website::schema_fields_URL, '', '!=')
                ->order(\Weline\Websites\Model\Website::schema_fields_ID, 'ASC')
                ->find()
                ->fetch();
            if ($row && $row->getId()) {
                return (string) $row->getUrl();
            }
        } catch (\Throwable) {
        }

        return '';
    }

    public function buildWebhookUrl(array $settings, ?string $urlOverride, ?string $baseUrlOverride): string
    {
        if ($urlOverride !== null && trim($urlOverride) !== '') {
            return rtrim(trim($urlOverride), '/');
        }

        $base = $baseUrlOverride !== null && trim($baseUrlOverride) !== ''
            ? rtrim(trim($baseUrlOverride), '/')
            : $this->resolveSiteBaseUrlInfo(null)['url'];

        if ($base === '') {
            return '';
        }

        $path = $this->routeService->normalizePath((string) ($settings['webhook_path'] ?? ''));
        if ($path === '') {
            return '';
        }

        return $base . '/' . $path;
    }

    public function buildSampleRef(array $settings, ?string $refOverride): string
    {
        if ($refOverride !== null && trim($refOverride) !== '') {
            return trim($refOverride);
        }

        $branch = trim((string) ($settings['project_branch'] ?? ''));
        if ($branch !== '') {
            return 'refs/heads/' . $branch;
        }

        return 'refs/heads/master';
    }

    /**
     * @return list<array{title:string,command:string}>
     */
    public function buildCurlExamples(string $webhookUrl, string $secret, string $ref): array
    {
        if ($webhookUrl === '') {
            return [];
        }

        $payload = json_encode(['ref' => $ref], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ref":"' . $ref . '"}';
        $escapedPayload = str_replace("'", "'\\''", $payload);

        return [
            [
                'title' => (string) __('推荐：Bearer 鉴权（GitHub / 通用 HTTP 客户端）'),
                'command' => "curl -s -X POST '{$webhookUrl}' \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -H 'Authorization: Bearer {$secret}' \\\n"
                    . "  --data '{$escapedPayload}'",
            ],
            [
                'title' => (string) __('兼容：X-Webhook-Token 请求头（与部分平台密码字段等价）'),
                'command' => "curl -s -X POST '{$webhookUrl}' \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -H 'X-Gitee-Token: {$secret}' \\\n"
                    . "  --data '{$escapedPayload}'",
            ],
            [
                'title' => (string) __('兼容：URL 查询参数 token'),
                'command' => "curl -s -X POST '{$webhookUrl}?token={$secret}' \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  --data '{$escapedPayload}'",
            ],
            [
                'title' => (string) __('健康检查（GET，无需密钥）'),
                'command' => "curl -s '{$webhookUrl}?health=1'",
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function buildGuidanceLines(
        string $webhookUrl,
        bool $secretGenerated,
        bool $sshGenerated,
        string $sshPublicKeyPath
    ): array {
        $lines = [
            (string) __('【1】Webhook 公网路径为随机生成（~wh~ 前缀），仅 deploy:webhook:setup 可轮换；Git 平台 URL 须与命令输出完全一致。'),
            (string) __('【2】Git 平台 Secret / 密码须与下方 webhook_secret 一致；推荐使用 Authorization: Bearer。'),
            (string) __('【3】版本探测地址为 Webhook 路径后加 /version（可配合后台「发布探测 Token」）。'),
        ];

        if ($webhookUrl !== '') {
            $lines[] = (string) __('【4】Git 平台 Webhook URL（复制到仓库配置）：%{1}', [$webhookUrl]);
        } else {
            $lines[] = (string) __('【4】未能自动推断站点域名，请追加 --base-url=https://你的域名 后重新运行以输出完整部署地址与 curl。');
        }

        $lines[] = (string) __('【5】仅在专用部署目录测试 curl，避免误触发开发机上的未提交代码。');

        if ($secretGenerated) {
            $lines[] = (string) __('【6】本次已生成新的 Webhook 密钥并写入后台配置，请同步更新 Git 平台 Webhook 中的密钥。');
        }

        if ($sshGenerated || is_file($sshPublicKeyPath)) {
            $lines[] = (string) __('【7】部署 SSH 公钥：将公钥添加到 Git 平台「部署公钥 / Deploy Key」（只读即可），服务器私钥位于 %{1}（勿提交 Git）。', [$sshPublicKeyPath]);
            $lines[] = (string) __('【8】使用 SSH 拉取时，将后台「项目仓库地址」改为 git@ 形式，并确保部署用户默认使用该私钥（如配置 GIT_SSH_COMMAND 或 ~/.ssh/config）。');
        }

        return $lines;
    }

    private function generateSshKeyPairWithSshKeygen(string $private, string $public): bool
    {
        if (!$this->commandExists('ssh-keygen')) {
            return false;
        }

        $comment = 'weline-deploy@' . (gethostname() ?: 'server');
        $cmd = 'ssh-keygen -t ed25519 -f ' . escapeshellarg($private)
            . ' -N ' . escapeshellarg('')
            . ' -C ' . escapeshellarg($comment)
            . ' 2>&1';
        exec($cmd, $output, $code);

        if ($code !== 0 || !is_file($private) || !is_file($public)) {
            return false;
        }

        @chmod($private, 0600);
        @chmod($public, 0644);
        return true;
    }

    private function commandExists(string $command): bool
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $check = $isWin ? 'where ' . escapeshellarg($command) . ' 2>nul' : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';
        exec($check, $output, $code);
        return $code === 0 && $output !== [];
    }
}
