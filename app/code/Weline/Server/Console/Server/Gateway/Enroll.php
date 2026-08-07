<?php

declare(strict_types=1);

namespace Weline\Server\Console\Server\Gateway;

use Weline\Framework\Console\CommandHelper;
use Weline\Server\Service\Edge\Gateway\GatewayBoundedText;
use Weline\Server\Service\Edge\Gateway\GatewayCredentialStore;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayProjectIdentityRotator;
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
        try {
            return (new ProjectIdentityStore())->withEnrollmentTransitionLock(
                fn (): int => $this->executeConfirmed($args, $data, $json),
            );
        } catch (\Throwable $throwable) {
            return $this->failure(
                $throwable->getMessage(),
                $json,
                'enrollment_transition_failed',
                ['retryable' => true],
            );
        }
    }

    private function executeConfirmed(array $args, array $data, bool $json): int
    {
        $rotate = isset($args['rotate-project-id']) || isset($args['rotate_project_id']);
        $abortRotation = isset($args['abort-rotation']) || isset($args['abort_rotation']);
        $freshIdentity = [];
        if ($rotate || $abortRotation) {
            try {
                $rotator = new GatewayProjectIdentityRotator();
                if ($abortRotation) {
                    $rotator->abort();
                    $result = ['state' => 'ABORTED'];
                } else {
                    $result = $rotator->rotate(
                        isset($args['same-root-transfer'])
                            || isset($args['same_root_transfer']),
                    );
                }
                if (!$abortRotation
                    && \hash_equals(
                        'FRESH_ENROLLMENT_REQUIRED',
                        (string)($result['state'] ?? ''),
                    )
                ) {
                    // A live same-host clone is a new tenant. Keep the source
                    // enrollment untouched and continue this same serialized
                    // command through the ordinary administrator enrollment.
                    $freshIdentity = $result;
                } else {
                    if (!$json) {
                        $this->printer->success($abortRotation
                            ? __('项目 UUID 轮换已在宿主提交前安全中止。')
                            : __('项目 UUID、宿主授权与本地凭据已完成可恢复原子轮换。'));
                    }
                    $this->output($result, $json);
                    return 0;
                }
            } catch (\Throwable $throwable) {
                return $this->failure(
                    $throwable->getMessage(),
                    $json,
                    'project_identity_rotation_pending',
                    ['retryable' => !$abortRotation],
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
        $additionalRoots = [];
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
            if (isset($additionalRoots[$alias]) || $alias === 'project_ssl') {
                return $this->failure(
                    __('证书根别名重复：%{1}', [$alias]),
                    $json,
                    'certificate_root_alias_conflict',
                );
            }
            $additionalRoots[$alias] = $path;
        }
        try {
            $roots = $builder->enrollmentCertificateRoots($root, $additionalRoots);
        } catch (\Throwable $throwable) {
            return $this->failure(
                $throwable->getMessage(),
                $json,
                'certificate_root_invalid',
            );
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
        $capabilities = [
            'acme_http_01' => !isset($args['no-acme-http-01'])
                && !isset($args['no_acme_http_01']),
            'acme_dns_01' => isset($args['acme-dns-01'])
                || isset($args['acme_dns_01']),
            'stateless' => isset($args['stateless']),
            'shared_session' => isset($args['shared-session'])
                || isset($args['shared_session']),
        ];
        $enrollment = GatewayHostManager::enrollmentRequestEnvelope([
            'project_uuid' => $projectUuid,
            'project_root' => $root,
            'certificate_roots' => $roots,
            'allowed_domains' => $domains,
            'capabilities' => $capabilities,
            ...$ownerProof,
        ]);
        try {
            $response = $this->gateway()->request('enroll', $enrollment);
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
            $receipt = GatewayHostManager::validateCredentialReceipt(
                $credential,
                \is_array($payload['credential_receipt'] ?? null)
                    ? $payload['credential_receipt']
                    : [],
                $enrollment,
            );
            try {
                (new GatewayCredentialStore())->install($credential, $projectUuid);
            } catch (\Throwable $throwable) {
                return $this->failure(
                    __('宿主 enrollment 已提交，但本地项目凭据未持久化；修复 var 目录权限后重试相同命令即可幂等续传。'),
                    $json,
                    'enrollment_local_commit_pending',
                    [
                        'host_committed' => true,
                        'retryable' => true,
                        'transaction_id' => (string)$receipt['tx_id'],
                        'security_generation' => (int)$receipt['security_generation'],
                        'credential_generation' => (int)$receipt['credential_generation'],
                        'local_error' => GatewayBoundedText::singleLine(
                            $throwable->getMessage(),
                            512,
                            'Credential persistence failed.',
                        ),
                    ],
                );
            }
            $identityStore = new ProjectIdentityStore();
            $pendingFreshIdentity = $identityStore->freshEnrollmentState();
            if ($pendingFreshIdentity !== []) {
                try {
                    $completedFreshIdentity = $identityStore->completeFreshEnrollment(
                        $projectUuid,
                    );
                } catch (\Throwable $throwable) {
                    return $this->failure(
                        __('宿主 enrollment 与新项目凭据均已提交，但克隆身份恢复标记尚未清理；重试相同命令将幂等完成。'),
                        $json,
                        'fresh_enrollment_local_finalize_pending',
                        [
                            'host_committed' => true,
                            'credential_installed' => true,
                            'retryable' => true,
                            'project_uuid' => $projectUuid,
                            'local_error' => GatewayBoundedText::singleLine(
                                $throwable->getMessage(),
                                512,
                                'Fresh enrollment marker cleanup failed.',
                            ),
                        ],
                    );
                }
                $freshIdentity = $freshIdentity !== []
                    ? $freshIdentity
                    : [
                        'state' => 'FRESH_ENROLLMENT_REQUIRED',
                        'previous_uuid' => (string)(
                            $completedFreshIdentity['previous_project_uuid'] ?? ''
                        ),
                        'project_uuid' => $projectUuid,
                    ];
            }
            unset($payload['credential']);
            $payload['project_uuid'] = $projectUuid;
            $payload['allowed_domains'] = $domains;
            $payload['credential_installed'] = true;
            $payload['certificate_access'] = 'broker_auth_snap';
            if ($freshIdentity !== []) {
                $payload['state'] = 'FRESH_ENROLLED';
                $payload['previous_uuid'] = (string)(
                    $freshIdentity['previous_uuid'] ?? ''
                );
            }
            if (!$json) {
                $this->printer->success($freshIdentity !== []
                    ? __('克隆项目已生成独立 UUID 并完成全新宿主 enrollment；原项目授权和路由保持不变。')
                    : __('当前项目已授权到 WLS 2.0 宿主网关；项目凭据已写入 var，证书仅通过 Broker AUTH/SNAP 读取。'));
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
                '--rotate-project-id' => __('存活克隆根生成新 UUID 并全新 enrollment；同根才以双凭据证明转移'),
                '--same-root-transfer' => __('允许已完成 clone enrollment 的项目再次执行同根双凭据身份转移'),
                '--abort-rotation' => __('仅在宿主 commit 前中止未完成 UUID 轮换'),
                '--confirm' => __('确认 enrollment、域名能力和项目凭据轮换'),
                '--json' => __('输出稳定 JSON 文档'),
            ],
            [],
            [],
        );
    }
}
