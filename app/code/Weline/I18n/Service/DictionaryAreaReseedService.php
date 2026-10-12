<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Service\DictionaryStaleWordPruneService;

/**
 * 全量收集后按路径 exclusive 证据重铺 i18n_dictionary.area（权威）；无证据词 → shared。
 */
final class DictionaryAreaReseedService
{
    public const MARKER_PATH = BP . '/var/i18n/area_reseed_from_path_evidence_v142.done';

    public function __construct(
        private readonly WordDictionary $dictionary,
    ) {
    }

    /**
     * @param array<string, string> $wordAreas word => frontend|backend|shared
     * @param array<string, string> $collectedWords 本轮 compile 源词集（用于 fail-closed 阈值）
     * @return array{applied: bool, reason: string, demoted: int, evidence_updates: int}
     */
    public function reseedFromPathEvidence(array $wordAreas, array $collectedWords): array
    {
        $wordAreas = $this->normalizeWordAreas($wordAreas);
        if (!self::passesFailClosedGate($wordAreas, $collectedWords)) {
            return [
                'applied' => false,
                'reason' => 'fail_closed_insufficient_path_evidence',
                'demoted' => 0,
                'evidence_updates' => 0,
            ];
        }

        $demoted = $this->demoteAllNonMetaToShared();
        $evidenceUpdates = $this->applyEvidenceAreas($wordAreas);
        $this->writeMarker();

        w_log_info('I18n dictionary area reseed from path evidence', [
            'evidence_words' => count($wordAreas),
            'demoted_to_shared' => $demoted,
            'evidence_updates' => $evidenceUpdates,
        ], 'i18n');

        return [
            'applied' => true,
            'reason' => 'ok',
            'demoted' => $demoted,
            'evidence_updates' => $evidenceUpdates,
        ];
    }

    /**
     * @param array<string, string> $wordAreas
     */
    public static function passesFailClosedGate(array $wordAreas, array $collectedWords): bool
    {
        if ($wordAreas === []) {
            return false;
        }

        $collectedCount = count($collectedWords);
        $threshold = max(50, (int)ceil($collectedCount * 0.01));
        if (count($wordAreas) < $threshold) {
            return false;
        }

        $hasFrontend = false;
        $hasBackend = false;
        foreach ($wordAreas as $area) {
            if ($area === DictionaryAreaPolicy::AREA_FRONTEND) {
                $hasFrontend = true;
            } elseif ($area === DictionaryAreaPolicy::AREA_BACKEND) {
                $hasBackend = true;
            }
        }

        return $hasFrontend || $hasBackend;
    }

    /**
     * @param array<string, string> $wordAreas
     * @return array<string, string>
     */
    private function normalizeWordAreas(array $wordAreas): array
    {
        $out = [];
        foreach ($wordAreas as $word => $area) {
            $word = trim((string)$word);
            if ($word === '' || str_starts_with($word, DictionaryStaleWordPruneService::META_PREFIX)) {
                continue;
            }
            try {
                $word = WordDictionary::assertWord($word);
            } catch (\Throwable) {
                continue;
            }
            $out[$word] = DictionaryAreaPolicy::normalize((string)$area);
        }

        return $out;
    }

    private function demoteAllNonMetaToShared(): int
    {
        $metaPrefix = DictionaryStaleWordPruneService::META_PREFIX . '%';
        $this->dictionary->reset()
            ->where($this->dictionary::schema_fields_WORD, $metaPrefix, 'NOT LIKE')
            ->update([
                $this->dictionary::schema_fields_AREA => DictionaryAreaPolicy::AREA_SHARED,
                $this->dictionary::schema_fields_IS_BACKEND => 0,
            ])
            ->fetch();

        return 0;
    }

    /**
     * @param array<string, string> $wordAreas
     */
    private function applyEvidenceAreas(array $wordAreas): int
    {
        $byArea = [
            DictionaryAreaPolicy::AREA_FRONTEND => [],
            DictionaryAreaPolicy::AREA_BACKEND => [],
            DictionaryAreaPolicy::AREA_SHARED => [],
        ];
        foreach ($wordAreas as $word => $area) {
            $byArea[$area][] = $word;
        }

        $updated = 0;
        foreach ($byArea as $area => $words) {
            if ($words === []) {
                continue;
            }
            foreach (array_chunk($words, 200) as $chunk) {
                $this->dictionary->reset()
                    ->where($this->dictionary::schema_fields_WORD, $chunk, 'IN')
                    ->update([
                        $this->dictionary::schema_fields_AREA => $area,
                        $this->dictionary::schema_fields_IS_BACKEND => $area === DictionaryAreaPolicy::AREA_BACKEND ? 1 : 0,
                    ])
                    ->fetch();
                $updated += count($chunk);
            }
        }

        return $updated;
    }

    private function writeMarker(): void
    {
        $dir = \dirname(self::MARKER_PATH);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        @\file_put_contents(self::MARKER_PATH, \gmdate('c') . "\n");
    }
}
