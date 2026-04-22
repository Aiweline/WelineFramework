<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Console\AiSite;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;

final class Audit implements CommandInterface
{
    public function execute(array $args = [], array $data = []): string
    {
        $publicId = \trim((string)($args['public_id'] ?? $args['p'] ?? $data['public_id'] ?? $data['p'] ?? ''));
        if ($publicId === '') {
            foreach ($args as $arg) {
                if (\is_string($arg) && $arg !== '' && !\str_contains($arg, ':') && !\str_starts_with($arg, '-')) {
                    $publicId = \trim($arg);
                }
            }
        }
        $adminId = (int)($args['admin_id'] ?? $args['admin'] ?? $data['admin_id'] ?? $data['admin'] ?? 2);
        if ($publicId === '' || $adminId <= 0) {
            return $this->emit("Usage: php bin/w aisite:audit --public_id=<session_public_id> [--admin_id=2]\n");
        }

        /** @var AiSiteAgentSessionService $sessionService */
        $sessionService = ObjectManager::getInstance(AiSiteAgentSessionService::class);
        /** @var AiSiteScopeCompatibilityService $scopeService */
        $scopeService = ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        /** @var AiSiteQualityGateService $qualityGate */
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);

        $session = $sessionService->loadByPublicId($publicId, $adminId);
        if (!$session instanceof AiSiteAgentSession) {
            return $this->emit(\json_encode([
                'success' => false,
                'message' => 'Session not found or not accessible.',
                'public_id' => $publicId,
                'admin_id' => $adminId,
            ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n");
        }

        $scope = $scopeService->normalizeScope($session->getScopeArray());
        $report = $qualityGate->inspectScope($scope);
        $queueRows = $this->findQueuesForPublicId($publicId);

        return $this->emit(\json_encode([
            'success' => (bool)($report['passed'] ?? false),
            'session' => [
                'id' => $session->getId(),
                'public_id' => $session->getPublicId(),
                'stage' => $session->getStage(),
                'website_id' => $session->getWebsiteId(),
                'virtual_theme_id' => $session->getVirtualThemeId(),
                'publish_status' => $session->getPublishStatus(),
            ],
            'queues' => $queueRows,
            'quality_gate' => $report,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n");
    }

    public function tip(): string
    {
        return 'Audit a PageBuilder AI site session queue/build/render quality gate.';
    }

    public function help(): array|string
    {
        return [
            'php bin/w aisite:audit --public_id=e0280d933b29695b46f6e366027140ab --admin_id=2',
        ];
    }

    private function emit(string $text): string
    {
        if ($text !== '') {
            echo $text;
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findQueuesForPublicId(string $publicId): array
    {
        $rows = [];
        try {
            $queueModel = ObjectManager::make(\Weline\Queue\Model\Queue::class);
            $queueModel->clearData()->clearQuery()->order('queue_id', 'ASC')->select();
            foreach ($queueModel->fetchArray() as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $haystack = \json_encode($row, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (!\is_string($haystack) || !\str_contains($haystack, $publicId)) {
                    continue;
                }
                unset($row['result']);
                $rows[] = $row;
            }
        } catch (\Throwable) {
        }

        return $rows;
    }
}
