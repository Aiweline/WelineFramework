<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Console\AiSite;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeManifestPolicy;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

final class MemoryProfile implements CommandInterface
{
    public const ALIASES = [
        'ai-site:memory:profile',
    ];

    public function execute(array $args = [], array $data = []): string
    {
        $sessionId = (int)($data['session_id'] ?? $data['session-id'] ?? $args['session_id'] ?? $args['session-id'] ?? 0);
        if ($sessionId <= 0) {
            return 'Usage: php bin/w ai-site:memory:profile --session_id=123';
        }

        /** @var AiSiteAgentSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(AiSiteAgentSession::class);
        $session = $sessionModel->clearData()->clearQuery()->load($sessionId);
        if (!$session->getId()) {
            return 'Session not found: ' . $sessionId;
        }

        $raw = (string)($session->getData(AiSiteAgentSession::schema_fields_SCOPE_JSON) ?? '');
        $decoded = [];
        if ($raw !== '' && \json_validate($raw)) {
            $decoded = \json_decode($raw, true) ?: [];
        }

        $policy = new AiSiteScopeManifestPolicy();
        $lines = [
            'session_id=' . $sessionId,
            'scope_json_bytes=' . \strlen($raw),
            'manifest_estimate_bytes=' . $policy->estimateJsonBytes($decoded),
        ];

        foreach (AiSiteScopeManifestPolicy::INLINE_ARTIFACT_KEYS as $key) {
            if ($policy->hasInlinePayload($decoded, $key)) {
                $lines[] = 'inline_artifact_violation=' . $key;
            }
        }

        try {
            $policy->assertManifestClean($decoded, true);
            $lines[] = 'manifest_clean=yes';
        } catch (\InvalidArgumentException $exception) {
            $lines[] = 'manifest_clean=no';
            $lines[] = 'manifest_error=' . $exception->getMessage();
        }

        return \implode("\n", $lines);
    }

    public function tip(): string
    {
        return 'Profile PageBuilder AI site session scope_json size and manifest cleanliness.';
    }

    public function help(): array|string
    {
        return [
            'Usage:',
            '  php bin/w ai-site:memory:profile --session_id=123',
            '',
            'Options:',
            '  --session_id=N   AI site agent session primary key.',
        ];
    }
}
