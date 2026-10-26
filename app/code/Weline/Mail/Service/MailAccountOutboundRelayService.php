<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\PostResponseTaskQueue;
use Weline\Mail\Model\MailAccount;

/**
 * 账号外投 smarthost（弹层 BinQuery / Controller 共用）。
 */
final class MailAccountOutboundRelayService
{
    /**
     * @param array<string, mixed> $params
     * @return array{success:bool,message:mixed,code?:int}
     */
    public function setRelay(array $params): array
    {
        $accountId = (int)($params['account_id'] ?? 0);
        $enabled = (int)($params['outbound_relay_enabled'] ?? 0) === 1 ? 1 : 0;
        $host = strtolower(trim((string)($params['outbound_relay_host'] ?? '')));
        $port = (int)($params['outbound_relay_port'] ?? 587);
        $user = trim((string)($params['outbound_relay_username'] ?? ''));
        $password = (string)($params['outbound_relay_password'] ?? '');
        $tls = strtolower(trim((string)($params['outbound_relay_tls'] ?? 'starttls')));
        $pickKey = trim((string)($params['outbound_smtp_pick'] ?? ''));
        $shareToPool = (int)($params['outbound_share_to_pool'] ?? 1) === 1;
        $bindDomain = (int)($params['outbound_bind_domain'] ?? 1) === 1;

        if ($pickKey !== '') {
            /** @var \Weline\Smtp\Service\SmtpServiceCatalogService $catalog */
            $catalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpServiceCatalogService::class);
            $picked = $catalog->resolveOutboundPick($pickKey);
            if (empty($picked['success'])) {
                return $this->fail((string)($picked['message'] ?? __('Smtp 选择无效')), 400);
            }
            $host = strtolower(trim((string)($picked['host'] ?? '')));
            $port = (int)($picked['port'] ?? 587);
            $user = trim((string)($picked['username'] ?? ''));
            $password = (string)($picked['password'] ?? '');
            $tls = strtolower(trim((string)($picked['tls'] ?? 'starttls')));
            $enabled = 1;
        }
        if ($host !== '' && $enabled !== 1) {
            $enabled = 1;
        }
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        /** @var \Weline\Smtp\Service\SmtpExternalProviderCatalog $providerCatalog */
        $providerCatalog = ObjectManager::getInstance(\Weline\Smtp\Service\SmtpExternalProviderCatalog::class);
        $user = $providerCatalog->normalizeUsernameForHost($host, $user);

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            return $this->fail(__('邮箱账号不存在'), 404);
        }
        if ($enabled === 1 && $host === '') {
            return $this->fail(__('开启外投时请选择 Smtp 账户或填写 smarthost 主机'), 400);
        }

        $account->setData(MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED, $enabled)
            ->setData(MailAccount::schema_fields_OUTBOUND_RELAY_HOST, $host !== '' ? $host : null)
            ->setData(MailAccount::schema_fields_OUTBOUND_RELAY_PORT, $host !== '' ? $port : null)
            ->setData(MailAccount::schema_fields_OUTBOUND_RELAY_USERNAME, $user !== '' ? $user : null)
            ->setData(MailAccount::schema_fields_OUTBOUND_RELAY_TLS, $tls)
            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));

        if (trim($password) !== '') {
            try {
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                $account->setData(
                    MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED,
                    $secrets->seal($password)
                );
            } catch (\Throwable $e) {
                return $this->fail(__('外投密码密封失败：%{1}', [$e->getMessage()]), 500);
            }
        }
        if ($host === '') {
            $account->setData(MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED, null);
        }
        $account->save();

        $notes = [];
        if ($enabled === 1 && $host !== '') {
            $sealed = trim((string)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED));
            $plainForShare = $password;
            if ($plainForShare === '' && $sealed !== '') {
                try {
                    /** @var MailOutboundRelaySecretService $secrets */
                    $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                    $plainForShare = $secrets->open($sealed);
                } catch (\Throwable) {
                    $plainForShare = '';
                }
            }
            if ($pickKey === '' && $shareToPool) {
                try {
                    /** @var MailOutboundRelayBindingService $binding */
                    $binding = ObjectManager::getInstance(MailOutboundRelayBindingService::class);
                    $shared = $binding->ensureSharedSmtpService($host, $port, $user, $plainForShare, $tls);
                    if (!empty($shared['success'])) {
                        $notes[] = (string)($shared['message'] ?? __('已共享到 Smtp 账户池'));
                    } else {
                        $notes[] = (string)($shared['message'] ?? __('共享 Smtp 账户登记失败'));
                    }
                } catch (\Throwable $e) {
                    $notes[] = (string)__('共享 Smtp 账户登记失败：%{1}', [$e->getMessage()]);
                }
            }
            if ($bindDomain && !str_starts_with($pickKey, 'acct:')) {
                try {
                    /** @var MailOutboundRelayBindingService $binding */
                    $binding = ObjectManager::getInstance(MailOutboundRelayBindingService::class);
                    $bound = $binding->syncDomainBinding($account, [
                        'host' => $host,
                        'port' => $port,
                        'username' => $user,
                        'tls' => $tls,
                        'sealed' => $sealed,
                    ]);
                    if (!empty($bound['success'])) {
                        $notes[] = (string)($bound['message'] ?? __('已绑定为域名默认外投'));
                    } else {
                        $notes[] = (string)($bound['message'] ?? __('域名默认外投绑定失败'));
                    }
                } catch (\Throwable $e) {
                    $notes[] = (string)__('域名默认外投绑定失败：%{1}', [$e->getMessage()]);
                }
            }
        }

        if (class_exists(PostResponseTaskQueue::class)) {
            PostResponseTaskQueue::enqueue(
                'mail-outbound-relay-sync',
                static function (): void {
                    try {
                        ObjectManager::getInstance(MailOutboundRelaySyncService::class)->syncAll();
                    } catch (\Throwable) {
                    }
                }
            );
        } else {
            try {
                ObjectManager::getInstance(MailOutboundRelaySyncService::class)->syncAll();
            } catch (\Throwable) {
            }
        }

        $msg = $enabled
            ? (string)__('已保存该账号外投 smarthost（路由异步下发）')
            : (string)__('已关闭该账号外投（路由异步下发）');
        if ($notes !== []) {
            $msg .= '；' . implode('；', $notes);
        }

        return ['success' => true, 'message' => $msg];
    }

    /** @return array{success:bool,message:mixed,code:int} */
    private function fail(mixed $message, int $code = 422): array
    {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }
}
