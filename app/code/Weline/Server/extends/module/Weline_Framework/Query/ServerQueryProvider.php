<?php
declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Framework\App\Env;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Server\Service\SslCertificateService;

/**
 * Weline Server 统一查询器
 *
 * 暴露证书申请等能力，供其他模块通过 w_query('server', ...) 调用
 */
class ServerQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly SslCertificateService $sslCertificateService
    ) {
    }

    public function getProviderName(): string
    {
        return 'server';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'requestCertificate' => $this->requestCertificate($params),
            default => throw new \InvalidArgumentException(
                (string)__('Server 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider'    => 'server',
            'name'        => __('Server 查询'),
            'description' => __('提供 SSL 证书申请等能力'),
            'module'      => 'Weline_Server',
            'operations'  => [
                [
                    'name'        => 'requestCertificate',
                    'description' => __('申请 SSL 证书'),
                    'params'      => [
                        ['name' => 'domain',      'type' => 'string', 'required' => true,  'description' => __('域名')],
                        ['name' => 'webroot',     'type' => 'string', 'required' => false, 'description' => __('Webroot 路径')],
                        ['name' => 'email',       'type' => 'string', 'required' => false, 'description' => __('联系邮箱')],
                        ['name' => 'website_id',  'type' => 'int',    'required' => false, 'description' => __('关联网站 ID')],
                        ['name' => 'provider',    'type' => 'string', 'required' => false, 'description' => __('证书提供商 letsencrypt/litessl')],
                    ],
                ],
            ],
        ];
    }

    private function requestCertificate(array $params): array
    {
        $domain = (string) ($params['domain'] ?? '');
        if ($domain === '') {
            return ['success' => false, 'message' => __('域名不能为空')];
        }

        $webroot = (string) ($params['webroot'] ?? '');
        if ($webroot === '') {
            $webroot = \defined('PUB') ? PUB : (BP . 'pub');
        }

        $email = (string) ($params['email'] ?? '');
        if ($email === '') {
            $email = Env::getInstance()->getConfig('ssl.contact_email') ?? '';
        }
        if ($email === '') {
            $email = 'admin@' . $domain;
        }

        $websiteId = (int) ($params['website_id'] ?? 0);
        $provider = (string) ($params['provider'] ?? SslCertificateService::PROVIDER_LETS_ENCRYPT);

        $result = $this->sslCertificateService->requestCertificate(
            $domain,
            $webroot,
            $email,
            $websiteId,
            $provider
        );

        $cert = $result['cert'] ?? null;
        $certId = $cert?->getCertId();

        return [
            'success'  => $result['success'] ?? false,
            'message'  => $result['message'] ?? '',
            'cert_id'  => $certId,
            'cert'     => $cert,
            'domain'   => $domain,
        ];
    }
}
