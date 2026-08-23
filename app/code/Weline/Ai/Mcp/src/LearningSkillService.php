<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * 0.12.x compatibility tombstone for the retired repository projection worker.
 *
 * Experience records remain queryable, but learning is never materialized as
 * repository files. Project and module documentation are indexed directly.
 */
final class LearningSkillService
{
    public const GENERATOR_VERSION = 'retired-in-0.13.0';

    public function __construct(
        private readonly Store $store,
        private readonly Config $config,
        private readonly ?CodexInvoker $codex = null,
    ) {
    }

    public static function projectionFingerprint(string $repository, ?Config $config = null): string
    {
        return hash('sha256', self::GENERATOR_VERSION);
    }

    public static function configuredOutputDirectory(Config $config, string $repository): ?string
    {
        return null;
    }

    public static function configuredRepositoryOutputPrefix(Config $config, string $repository): ?string
    {
        return null;
    }

    public static function isGeneratedSkillPath(Config $config, string $repository, string $path): bool
    {
        return false;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function syncJob(array $job): array
    {
        return [
            'schema_version' => 'learning-projection-retired.v1',
            'decision' => 'disabled',
            'reason' => 'Repository learning projections were retired in 0.13.0; query indexed documents dynamically.',
            'repository_files_written' => false,
            'closed_loop' => [
                'status' => 'not_required',
                'mode' => 'disabled',
                'project_index_required' => false,
            ],
        ];
    }
}
