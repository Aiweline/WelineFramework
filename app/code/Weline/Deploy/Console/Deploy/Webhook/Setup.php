<?php

declare(strict_types=1);

namespace Weline\Deploy\Console\Deploy\Webhook;

use Weline\Deploy\Service\DeployConfigService;
use Weline\Deploy\Service\DeployWebhookSetupService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\Console\Server\TablePrinter;
use Weline\Framework\Output\Cli\Printing;

class Setup extends CommandAbstract
{
    use TablePrinter;

    public function __construct(
        Printing $printer,
        private readonly DeployConfigService $deployConfigService,
        private readonly DeployWebhookSetupService $setupService
    ) {
        $this->printer = $printer;
    }

    public function tip(): string
    {
        return __('生成或更新框架 Webhook 访问密钥，并输出 curl 测试命令与部署关联指引');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'deploy:webhook:setup',
            $this->tip(),
            [
                '-f, --force' => '强制重新生成 Webhook 密钥与随机公网路径（须同步 Git 平台）',
                '--rotate-path' => '仅重新生成随机 Webhook 公网路径（不轮换密钥）',
                '--ssh-key' => '同时生成部署用 SSH 密钥对（保存在 var/deploy/ssh/）',
                '--ssh-force' => '配合 --ssh-key 强制重新生成 SSH 密钥',
                '--url=<地址>' => '完整 Webhook 公网 URL（含 ~wh~ 随机路径）',
                '--base-url=<域名>' => '站点根 URL，与后台「请求路径」拼接',
                '--ref=<ref>' => 'curl 测试用的 Git ref，默认取项目分支',
                '--no-save' => '仅输出，不写入后台部署配置',
                '-y, --yes' => '强制操作时跳过确认',
                '-h, --help' => '显示帮助信息',
            ],
            [
                '密钥用途' => 'webhook_secret 是框架 Webhook 的统一访问密码，Git 平台 Secret 字段应填写相同值',
                '鉴权方式' => '支持 Bearer、兼容请求头明文 token、?token= 查询参数',
                '安全提示' => 'SSH 私钥与 Webhook 密钥均不应提交到 Git',
            ],
            [
                '首次生成' => 'php bin/w deploy:webhook:setup',
                '指定域名' => 'php bin/w deploy:webhook:setup --base-url=https://www.example.com',
                '含 SSH 密钥' => 'php bin/w deploy:webhook:setup --ssh-key',
                '轮换密钥与路径' => 'php bin/w deploy:webhook:setup --force -y --base-url=https://www.example.com',
                '仅轮换路径' => 'php bin/w deploy:webhook:setup --rotate-path -y --base-url=https://www.example.com',
            ],
            'php bin/w deploy:webhook:setup [选项]'
        );
    }

    public function execute(array $args = [], array $data = [])
    {
        $force = isset($args['force']) || isset($args['f']);
        $rotatePath = isset($args['rotate-path']);
        $yes = isset($args['yes']) || isset($args['y']);
        $noSave = isset($args['no-save']);
        $withSsh = isset($args['ssh-key']);
        $sshForce = isset($args['ssh-force']);
        $url = $this->readOption($args, 'url');
        $baseUrl = $this->readOption($args, 'base-url');
        $ref = $this->readOption($args, 'ref');

        $allSettings = $this->deployConfigService->getSettings();
        $previewSecret = $this->setupService->resolveWebhookSecret(false);
        $previewPath = $this->setupService->resolveWebhookPath(false);

        if (($previewSecret['previous_configured'] && $force) || ($previewPath['previous_configured'] && ($force || $rotatePath))) {
            if (!$yes) {
                if ($force) {
                    $this->printer->warning(__('--force 将重新生成 Webhook 密钥与随机公网路径，须同步更新 Git 平台。'));
                } elseif ($rotatePath) {
                    $this->printer->warning(__('--rotate-path 将重新生成随机公网路径，须同步更新 Git 平台 Webhook URL。'));
                }
                $this->printer->note(__('添加 -y 跳过确认。'));
                if (!$this->confirm(__('是否继续？'))) {
                    $force = false;
                    $rotatePath = false;
                }
            }
        }

        $pathResult = $this->setupService->resolveWebhookPath($force || $rotatePath);
        $secretResult = $this->setupService->resolveWebhookSecret($force);
        $allSettings = $this->deployConfigService->getSettings();
        if ($pathResult['generated']) {
            $allSettings['webhook_path'] = $pathResult['path'];
        } elseif (trim((string) ($allSettings['webhook_path'] ?? '')) === '') {
            $allSettings['webhook_path'] = $pathResult['path'];
        }

        $baseInfo = $this->setupService->resolveSiteBaseUrlInfo($baseUrl);
        $effectiveBase = $baseInfo['url'] !== '' ? $baseInfo['url'] : null;
        $webhookUrl = $this->setupService->buildWebhookUrl($allSettings, $url, $effectiveBase);
        $versionUrl = $this->setupService->buildVersionUrl($allSettings, $effectiveBase);
        $sampleRef = $this->setupService->buildSampleRef($allSettings, $ref);

        $this->printer->note('');
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->setup(__('Weline 部署 Webhook 关联助手'));
        $this->printer->setup('═══════════════════════════════════════════════════════════════');
        $this->printer->note('');

        if ($pathResult['generated']) {
            $this->printer->success(__('已生成新的随机 Webhook 公网路径。'));
        } elseif ($pathResult['previous_configured']) {
            $this->printer->note(__('沿用后台已有 Webhook 路径（使用 --rotate-path 或 --force 可重新生成）。'));
        } else {
            $this->printer->success(__('已生成 Webhook 公网路径。'));
        }

        if ($secretResult['generated']) {
            $this->printer->success(__('已生成新的 Webhook 访问密钥。'));
        } elseif ($secretResult['previous_configured']) {
            $this->printer->note(__('沿用后台已有 Webhook 密钥（使用 --force 可重新生成）。'));
        } else {
            $this->printer->success(__('已生成 Webhook 访问密钥。'));
        }

        if (!$noSave && $pathResult['generated']) {
            if ($this->setupService->saveWebhookPath($pathResult['path'])) {
                $this->printer->success(__('随机 Webhook 公网路径已保存到后台部署配置。'));
            } else {
                $this->printer->error(__('Webhook 路径保存失败。'));
            }
        }

        if (!$noSave && $secretResult['generated']) {
            if ($this->setupService->saveWebhookSecret($secretResult['secret'])) {
                $this->printer->success(__('密钥已保存到后台「部署配置 > Webhook 密钥」。'));
            } else {
                $this->printer->error(__('密钥保存失败，请手动写入后台部署配置。'));
            }
        } elseif ($noSave) {
            $this->printer->warning(__('--no-save：未写入后台配置，请自行保存下方密钥与路径。'));
        }

        $this->printer->note('');
        $this->printer->setup(__('Webhook 公网路径（随机，~wh~ 前缀）'));
        $this->printCopyBlock($pathResult['path']);

        $this->printer->note('');
        $this->printer->setup(__('Git 平台 Webhook 部署地址（完整 URL）'));
        if ($webhookUrl !== '') {
            if ($baseInfo['source'] !== '' && $baseUrl === null) {
                $this->printer->note(__('站点根 URL 来源：%{1}', [$baseInfo['source']]));
            }
            $this->printCopyBlock($webhookUrl);
        } else {
            $this->printer->warning(__('未能拼出完整 URL。请配置网站 URL 或执行：php bin/w deploy:webhook:setup --base-url=https://你的域名'));
            $this->printer->note(__('相对路径（需自行拼接域名）：/%{1}', [$pathResult['path']]));
        }

        if ($versionUrl !== '') {
            $this->printer->note('');
            $this->printer->setup(__('版本探测 URL'));
            $this->printCopyBlock($versionUrl);
        }

        $this->printer->note('');
        $this->printer->setup(__('Webhook 访问密钥（webhook_secret）'));
        $this->printCopyBlock($secretResult['secret']);

        $sshGenerated = false;
        $sshPublicPath = $this->setupService->sshPublicKeyPath();
        if ($withSsh) {
            $this->printer->note('');
            $this->printer->setup(__('部署 SSH 密钥'));
            $sshResult = $this->setupService->ensureDeploySshKeyPair($sshForce);
            if (!$sshResult['ok']) {
                $this->printer->error($sshResult['message'] ?? (string) __('SSH 密钥生成失败。'));
            } else {
                $sshGenerated = $sshResult['generated'];
                $sshPublicPath = $sshResult['public_key'];
                if ($sshGenerated) {
                    $this->printer->success(__('SSH 密钥对已生成。'));
                } else {
                    $this->printer->note(__('沿用已有 SSH 密钥（使用 --ssh-force 可重新生成）。'));
                }
                $this->printer->note(__('私钥：%{1}', [$sshResult['private_key']]));
                $this->printer->note(__('公钥：%{1}', [$sshResult['public_key']]));
                $this->printer->note('');
                $this->printCopyBlock($sshResult['public_key_content']);
            }
        }

        $curlExamples = $this->setupService->buildCurlExamples($webhookUrl, $secretResult['secret'], $sampleRef);
        $this->printer->note('');
        $this->printer->setup(__('curl 测试命令（专用部署环境执行）'));
        if ($curlExamples === []) {
            $this->printer->warning(__('无法生成 curl 示例：缺少完整 Webhook URL。'));
            $this->printer->note(__('请执行：php bin/w deploy:webhook:setup --base-url=https://你的域名'));
        } else {
            foreach ($curlExamples as $example) {
                $this->printer->note('');
                $this->printer->setup($example['title']);
                $this->printCopyBlock($example['command']);
            }
        }

        $this->printer->note('');
        $this->printer->setup(__('配置指引'));
        foreach ($this->setupService->buildGuidanceLines(
            $webhookUrl,
            $secretResult['generated'] || $pathResult['generated'],
            $sshGenerated,
            $sshPublicPath
        ) as $line) {
            $this->printer->note($line);
        }

        $this->printer->note('');
        $this->printer->success(__('完成。'));
        $this->printer->note('');
    }

    private function printCopyBlock(string $text): void
    {
        $text = rtrim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return;
        }
        // 勿用 printList([$text]) 或按行 note：会误打数组下标 0/1/2…
        $this->printer->note($text);
    }

    private function readOption(array $args, string $name): ?string
    {
        if (isset($args[$name]) && is_string($args[$name]) && $args[$name] !== '') {
            return $args[$name];
        }
        $alt = str_replace('-', '_', $name);
        if (isset($args[$alt]) && is_string($args[$alt]) && $args[$alt] !== '') {
            return $args[$alt];
        }
        return null;
    }

    private function confirm(string $question): bool
    {
        if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            return false;
        }
        $this->printer->warning($question . ' [y/N] ');
        $answer = fgets(STDIN);
        if ($answer === false) {
            return false;
        }
        $answer = strtolower(trim($answer));
        return in_array($answer, ['y', 'yes', '是'], true);
    }
}
