<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Http\Security\SecurityPolicyLkgRepositoryInterface;
use Weline\SystemConfig\Model\SecurityPolicyLkg;

final class OrmSecurityPolicyLkgRepository implements SecurityPolicyLkgRepositoryInterface
{
    public function __construct(
        private readonly SecurityPolicyLkg $model = new SecurityPolicyLkg(),
    ) {
    }

    public function find(string $schemaVersion, string $scopeKey): ?array
    {
        $hit = $this->findModel($schemaVersion, $scopeKey);
        if (!$hit->getId()) {
            return null;
        }

        return [
            'schema_version' => (string)$hit->getData(SecurityPolicyLkg::schema_fields_SCHEMA_VERSION),
            'scope_key' => (string)$hit->getData(SecurityPolicyLkg::schema_fields_SCOPE_KEY),
            'digest' => (string)$hit->getData(SecurityPolicyLkg::schema_fields_DIGEST),
            'verified_at' => (string)$hit->getData(SecurityPolicyLkg::schema_fields_VERIFIED_AT),
        ];
    }

    public function save(
        string $schemaVersion,
        string $scopeKey,
        string $digest,
        string $verifiedAt,
    ): array {
        if (\preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \InvalidArgumentException('security_policy_lkg_digest_invalid');
        }
        $timestamp = \strtotime($verifiedAt);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('security_policy_lkg_time_invalid');
        }

        $model = $this->findModel($schemaVersion, $scopeKey);
        $model->setData([
            SecurityPolicyLkg::schema_fields_SCHEMA_VERSION => $schemaVersion,
            SecurityPolicyLkg::schema_fields_SCOPE_KEY => $scopeKey,
            SecurityPolicyLkg::schema_fields_DIGEST => $digest,
            SecurityPolicyLkg::schema_fields_VERIFIED_AT => \gmdate('Y-m-d H:i:s', $timestamp),
        ])->save();

        return $this->find($schemaVersion, $scopeKey)
            ?? throw new \RuntimeException('security_policy_lkg_persist_failed');
    }

    public function delete(string $schemaVersion, string $scopeKey): void
    {
        $model = $this->findModel($schemaVersion, $scopeKey);
        if ($model->getId()) {
            $model->delete();
        }
    }

    private function findModel(string $schemaVersion, string $scopeKey): SecurityPolicyLkg
    {
        $model = clone $this->model;
        $model->clear();
        $hit = $model
            ->where(SecurityPolicyLkg::schema_fields_SCHEMA_VERSION, $schemaVersion)
            ->where(SecurityPolicyLkg::schema_fields_SCOPE_KEY, $scopeKey)
            ->find()
            ->fetch();

        return $hit instanceof SecurityPolicyLkg ? $hit : $model;
    }
}
