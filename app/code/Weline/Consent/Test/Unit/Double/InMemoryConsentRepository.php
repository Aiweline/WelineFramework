<?php

declare(strict_types=1);

namespace Weline\Consent\Test\Unit\Double;

use Weline\Consent\Api\ConsentRepositoryInterface;

final class InMemoryConsentRepository implements ConsentRepositoryInterface
{
    private array $records = [];
    private array $audit = [];

    public function categories(): array
    {
        return [
            ['code' => 'necessary', 'name' => 'Necessary', 'required' => true],
            ['code' => 'analytics', 'name' => 'Analytics', 'required' => false],
            ['code' => 'marketing', 'name' => 'Marketing', 'required' => false],
        ];
    }

    public function grant(int $websiteId, string $visitorKey, string $categoryCode): void
    {
        $key = $this->key($websiteId, $visitorKey, $categoryCode);
        $this->records[$key] = [
            'website_id' => $websiteId,
            'visitor_key' => $visitorKey,
            'category_code' => $categoryCode,
            'status' => 'granted',
        ];
        $this->audit[] = $this->records[$key] + ['action' => 'granted'];
    }

    public function withdraw(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        if ($categoryCode === 'necessary') {
            throw new \RuntimeException('consent_required_cannot_withdraw');
        }
        $key = $this->key($websiteId, $visitorKey, $categoryCode);
        if (!isset($this->records[$key])) {
            return false;
        }
        $this->records[$key]['status'] = 'withdrawn';
        $this->audit[] = $this->records[$key] + ['action' => 'withdrawn'];
        return true;
    }

    public function isGranted(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        return ($this->records[$this->key($websiteId, $visitorKey, $categoryCode)]['status'] ?? '') === 'granted';
    }

    public function listForWebsite(int $websiteId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $row): bool => (int)$row['website_id'] === $websiteId,
        ));
    }

    public function auditForWebsite(int $websiteId): array
    {
        return array_values(array_filter(
            $this->audit,
            static fn(array $row): bool => (int)$row['website_id'] === $websiteId,
        ));
    }

    private function key(int $websiteId, string $visitorKey, string $categoryCode): string
    {
        return $websiteId . "\0" . $visitorKey . "\0" . $categoryCode;
    }
}
