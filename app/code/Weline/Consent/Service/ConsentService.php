<?php

declare(strict_types=1);

namespace Weline\Consent\Service;

use Weline\Consent\Api\ConsentRecordingPolicyInterface;
use Weline\Consent\Api\ConsentRepositoryInterface;

/**
 * Durable Website-isolated Consent (TASK-P1D-REV-005 / TEST-P1D-04).
 */
final class ConsentService
{
    private const VISITOR_KEY_MAX_LENGTH = 64;
    private const CATEGORY_CODE_MAX_LENGTH = 64;

    public function __construct(
        private readonly ConsentRepositoryInterface $repository,
        private readonly ConsentRecordingPolicyInterface $recordingPolicy,
    ) {
    }

    public function isRecordingEnabled(): bool
    {
        return $this->recordingPolicy->isRecordingEnabled();
    }

    public function categories(): array
    {
        return $this->repository->categories();
    }

    public function grant(int $websiteId, string $visitorKey, string $categoryCode): void
    {
        [$visitorKey, $categoryCode] = $this->assertIdentity($websiteId, $visitorKey, $categoryCode);
        if (!$this->isRecordingEnabled()) {
            throw new \RuntimeException('consent_recording_disabled');
        }
        $this->repository->grant($websiteId, $visitorKey, $categoryCode);
    }

    public function withdraw(int $websiteId, string $visitorKey, string $categoryCode): void
    {
        [$visitorKey, $categoryCode] = $this->assertIdentity($websiteId, $visitorKey, $categoryCode);
        $this->repository->withdraw($websiteId, $visitorKey, $categoryCode);
    }

    public function isGranted(int $websiteId, string $visitorKey, string $categoryCode): bool
    {
        [$visitorKey, $categoryCode] = $this->assertIdentity($websiteId, $visitorKey, $categoryCode);
        return $this->repository->isGranted($websiteId, $visitorKey, $categoryCode);
    }

    /**
     * 横幅是否应展示：任一非必要类目未授权。
     */
    public function shouldShowBanner(int $websiteId, string $visitorKey): bool
    {
        $this->assertWebsiteId($websiteId);
        $visitorKey = $this->normalizeVisitorKey($visitorKey);
        foreach ($this->categories() as $cat) {
            if ($cat['required']) {
                continue;
            }
            if (!$this->isGranted($websiteId, $visitorKey, $cat['code'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForWebsite(int $websiteId): array
    {
        $this->assertWebsiteId($websiteId);
        return $this->repository->listForWebsite($websiteId);
    }

    public function auditForWebsite(int $websiteId): array
    {
        $this->assertWebsiteId($websiteId);
        return $this->repository->auditForWebsite($websiteId);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function assertIdentity(int $websiteId, string $visitorKey, string $categoryCode): array
    {
        $this->assertWebsiteId($websiteId);
        $visitorKey = $this->normalizeVisitorKey($visitorKey);
        $categoryCode = strtolower(trim($categoryCode));
        if ($categoryCode === ''
            || strlen($categoryCode) > self::CATEGORY_CODE_MAX_LENGTH
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $categoryCode) !== 1
        ) {
            throw new \InvalidArgumentException('consent_category_invalid');
        }

        return [$visitorKey, $categoryCode];
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('consent_website_id_invalid');
        }
    }

    private function normalizeVisitorKey(string $visitorKey): string
    {
        $visitorKey = trim($visitorKey);
        if ($visitorKey === ''
            || strlen($visitorKey) > self::VISITOR_KEY_MAX_LENGTH
            || preg_match('/^v1_[A-Za-z0-9_-]{43}$/D', $visitorKey) !== 1
        ) {
            throw new \InvalidArgumentException('consent_visitor_key_invalid');
        }

        return $visitorKey;
    }
}
