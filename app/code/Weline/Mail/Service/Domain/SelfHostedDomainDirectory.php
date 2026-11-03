<?php

declare(strict_types=1);

namespace Weline\Mail\Service\Domain;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Api\Domain\SelfHostedDomainDirectoryInterface;
use Weline\Mail\Model\MailDomain;

/**
 * Adapter exposing locally-managed mail domains through the public Mail API.
 */
final class SelfHostedDomainDirectory implements SelfHostedDomainDirectoryInterface
{
    public function listSelfHostedDomains(): array
    {
        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);
        $collection = $domainModel->clear()->order(MailDomain::schema_fields_ID, 'ASC')->select()->fetch();
        $rows = \is_object($collection) && \method_exists($collection, 'toModels')
            ? $collection->toModels()
            : (\is_iterable($collection) ? $collection : []);

        $out = [];
        foreach ($rows as $row) {
            if (!$row instanceof MailDomain) {
                continue;
            }
            $domain = \strtolower(\trim((string)$row->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            if ($domain === '') {
                continue;
            }
            $hostname = \strtolower(\trim((string)$row->getData(MailDomain::schema_fields_HOSTNAME)));
            if ($hostname === '') {
                $hostname = 'mail.' . $domain;
            }
            $out[] = ['domain' => $domain, 'hostname' => $hostname];
        }

        return $out;
    }
}
