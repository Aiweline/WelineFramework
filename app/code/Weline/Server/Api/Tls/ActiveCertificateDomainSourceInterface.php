<?php

declare(strict_types=1);

namespace Weline\Server\Api\Tls;

interface ActiveCertificateDomainSourceInterface
{
    /**
     * @return list<array{domain:string,website_id:int}>
     */
    public function getActiveCertificateDomains(int $limit = 2000): array;
}
