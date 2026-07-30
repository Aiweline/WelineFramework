<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;
use Weline\Server\Service\Edge\Gateway\GatewayRegistrationBuilder;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;

final class Enroll extends AbstractGatewayCommand
{
    public function execute(array $args = [], array $data = []): int
    {
        $json = $this->isJson($args);
        if (!isset($args['confirm'])) {
            return $this->failure(
                __('宿主 enrollment 会授权域名和证书目录并轮换项目凭据；请提供 --confirm。'),
                $json,
                'confirmation_required',
            );
        }
        $rotation = null;
        if (isset($args['rotate-project-id']) || isset($args['rotate_project_id'])) {
            try {
                $rotation = (new ProjectIdentityStore())->rotate();
                if (!$json) {
                    $this->printer->warning(__('项目身份已显式轮换：%{1} → %{2}', [
                        (string)$rotation['previous_uuid'],
                        (string)$rotation['project_uuid'],
                    ]));
                }
            } catch (\Throwable $throwable) {
                return $this->failure(
                    $throwable->getMessage(),
                    $json,
                    'project_identity_rotation_failed',
                );
            }
        }
        $builder = new GatewayRegistrationBuilder();
        $root = \realpath((string)BP);
        if (!\is_string($root)) {
            return $this->failure(__('无法解析项目根目录。'), $json, 'project_root_invalid');
        }
        $ownerProof = [];
        if (\PHP_OS_FAMILY !== 'Windows') {
            $rootStatus = @\lstat($root);
            if (!\is_array($rootStatus) || \is_link($root)) {
                return $this->failure(
                    __('无法验证项目根目录所有者。'),
                    $json,
                    'project_owner_invalid',
                );
            }
            $ownerProof = [
                'project_owner_uid' => (int)$rootStatus['uid'],
                'project_owner_gid' => (int)$rootStatus['gid'],
            ];
        }
        $roots = $builder->enrollmentCertificateRoots($root);
        $extra = $args['cert-root'] ?? $args['cert_root'] ?? [];
        $extraIndex = 1;
        foreach ((array)$extra as $path) {
            $path = (string)$path;
            $alias = '';
            if (\preg_match('/\A([a-z][a-z0-9_]{0,31})=(.+)\z/D', $path, $matches) === 1) {
                $alias = (string)$matches[1];
                $path = (string)$matches[2];
            }
            if (!\str_starts_with($path, '/') && \preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) !== 1) {
                $path = $root . DIRECTORY_SEPARATOR . $path;
            }
            $alias = $alias !== '' ? $alias : 'extra_cli_' . $extraIndex++;
            if (isset($roots[$alias])) {
                return $this->failure(
                    __('证书根别名重复：%{1}', [$alias]),
                    $json,
                    'certificate_root_alias_conflict',
                );
            }
            $roots[$alias] = $path;
        }
        $domains = [];
        foreach ((array)($args['domain'] ?? []) as $domain) {
            $domain = \strtolower(\rtrim(\trim((string)$domain), '.'));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        if ($domains === []) {
            $domains = $builder->desiredDomains();
        }
        $domains = \array_values(\array_unique($domains));
        if ($domains === []) {
            return $this->failure(
                __('未找到可授权域名；请先配置项目证书，或显式重复提供 --domain。'),
                $json,
                'domain_required',
            );
        }
        $projectUuid = $builder->projectUuid();
        try {
            $runtimeAccess = (new GatewayPlatformServiceInstaller())
                ->authorizeProjectRuntimeRead(
                    $root,
                    isset($ownerProof['project_owner_uid'])
                        ? (int)$ownerProof['project_owner_uid']
                        : null,
                    isset($ownerProof['project_owner_gid'])
                        ? (int)$ownerProof['project_owner_gid']
                        : null,
                );
            $response = $this->gateway()->request('enroll', [
                'project_uuid' => $projectUuid,
                'project_root' => $root,
                'certificate_roots' => $roots,
                'allowed_domains' => $domains,
                'capabilities' => [
                    'acme_http_01' => !isset($args['no-acme-http-01'])
                        && !isset($args['no_acme_http_01']),
                    'acme_dns_01' => isset($args['acme-dns-01'])
                        || isset($args['acme_dns_01']),
                    'stateless' => isset($args['stateless']),
                    'shared_session' => isset($args['shared-session'])
                        || isset($args['shared_session']),
                ],
                ...$ownerProof,
            ]);
            if (!($response['ok'] ?? false)) {
                $error = (array)($response['error'] ?? []);
                return $this->failure(
                    (string)($error['message'] ?? __('授权失败')),
                    $json,
                    (string)($error['code'] ?? 'enrollment_failed'),
                    (array)($error['details'] ?? []),
                );
            }
            $payload = \is_array($response['payload'] ?? null) ? $response['payload'] : [];
            $credential = \is_array($payload['credential'] ?? null)
                ? $payload['credential']
                : [];
            (new GatewayCredentialStore())->install($credential, $projectUuid);
            unset($payload['credential']);
            $payload['project_uuid'] = $projectUuid;
            $payload['allowed_domains'] = $domains;
            $payload['credential_installed'] = true;
            $payload['endpoint_access_prepared'] = (bool)(
                $runtimeAccess['applied'] ?? $runtimeAccess['test_mode'] ?? false
            );
            if (\is_array($rotation)) {
                $payload['project_identity_rotation'] = $rotation;
            }
            if (!$json) {
                $this->printer->success(
                    __('当前项目已授权到 WLS 2.0 宿主网关；宿主凭据已写入项目 var 目录。')
                );
                $this->printer->note(__('授权域名：%{1}', [\implode(', ', $domains)]));
            }
            $this->output($payload, $json);
            return 0;
        } catch (\Throwable $throwable) {
            return $this->failure($throwable->getMessage(), $json, 'enrollment_failed');
        }
    }

    public function tip(): string
    {
        return __('授权当前项目及其证书根目录到宿主网关');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:gateway:enroll',
            $this->tip(),
            [
                '--cert-root <alias=path>' => __('附加项目内证书目录，可重复；别名用于可迁移证书引用'),
                '--domain <domain>' => __('显式授权域名或通配符，可重复；省略时读取当前证书域名'),
                '--no-acme-http-01' => __('不授权精确 HTTP-01 challenge 短租约（默认授权）'),
                '--acme-dns-01' => __('授权项目使用 DNS-01 能力'),
                '--stateless' => __('声明项目后端可按无状态策略分流；仍需运行时证明'),
                '--shared-session' => __('声明项目后端共享 Session；仍需运行时证明'),
                '--rotate-project-id' => __('为复制项目显式生成新 UUID；必须配合 --confirm'),
                '--confirm' => __('确认 enrollment、域名能力和项目凭据轮换'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [],
            [],
        );
    }
}
