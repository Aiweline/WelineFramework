<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoDupDoc;
use Weline\Seo\Model\SeoDupPair;
use Weline\Seo\Model\SeoDupRun;
use Weline\Seo\Model\SitemapUrl;

/**
 * Orchestrates scoped fetch → fingerprint → near-duplicate grading → report URLs.
 */
class ContentDuplicateScanner
{
    public function __construct(
        private readonly ?MinHashIndexer $indexer = null,
        private readonly ?MainContentExtractor $extractor = null,
        private readonly ?DuplicateReportUrlBuilder $reportUrlBuilder = null,
        private readonly ?DuplicatePageFetcher $fetcher = null,
        private readonly ?DuplicateNotifier $notifier = null,
    ) {
    }

    /**
     * @param array<string, mixed>|DuplicateCheckScope $scope
     * @return array<string, mixed>
     */
    public function scan(array|DuplicateCheckScope $scope, ?string $backendBaseUrl = null): array
    {
        $scopeDto = $scope instanceof DuplicateCheckScope ? $scope : DuplicateCheckScope::fromArray($scope);
        $indexer = $this->indexer ?? ObjectManager::getInstance(MinHashIndexer::class);
        $extractor = $this->extractor ?? ObjectManager::getInstance(MainContentExtractor::class);
        $reportUrlBuilder = $this->reportUrlBuilder ?? ObjectManager::getInstance(DuplicateReportUrlBuilder::class);
        $fetcher = $this->fetcher ?? ObjectManager::getInstance(DuplicatePageFetcher::class);
        $notifier = $this->notifier ?? ObjectManager::getInstance(DuplicateNotifier::class);

        /** @var SeoDupRun $runModel */
        $runModel = ObjectManager::getInstance(SeoDupRun::class);
        $startedAt = date('Y-m-d H:i:s');
        $runModel->clear()->setData([
            SeoDupRun::schema_fields_WEBSITE_ID => $scopeDto->websiteId,
            SeoDupRun::schema_fields_SCOPE_JSON => \json_encode($scopeDto->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            SeoDupRun::schema_fields_STATUS => SeoDupRun::STATUS_RUNNING,
            SeoDupRun::schema_fields_STATS_JSON => '{}',
            SeoDupRun::schema_fields_ISSUE_COUNT => 0,
            SeoDupRun::schema_fields_REPORT_PATH => '',
            SeoDupRun::schema_fields_REPORT_URL => '',
            SeoDupRun::schema_fields_STARTED_AT => $startedAt,
        ])->save();
        $runId = (int)$runModel->getId();
        $reportPath = $reportUrlBuilder->pathForRun($runId);
        $reportUrl = $reportUrlBuilder->absoluteForRun($runId, $backendBaseUrl);
        $runModel->setData(SeoDupRun::schema_fields_REPORT_PATH, $reportPath)
            ->setData(SeoDupRun::schema_fields_REPORT_URL, $reportUrl)
            ->save();

        $stats = [
            'scanned' => 0,
            'compared' => 0,
            'duplicate' => 0,
            'suspect' => 0,
            'fetch_error' => 0,
            'too_short' => 0,
            'pairs' => 0,
        ];

        try {
            $urlRows = $this->loadUrlRows($scopeDto);
            $docsForIndex = [];
            $docMeta = [];

            foreach ($urlRows as $row) {
                $stats['scanned']++;
                $url = \trim((string)($row['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $fetch = $fetcher->fetch($url);
                $entityType = \trim((string)($row['entity_type'] ?? ''));
                $module = \trim((string)($row['module'] ?? ''));
                $locale = (string)($row['locale'] ?? '');
                $family = $entityType !== '' ? $entityType : $module;
                $bucket = (string)($row['_dup_bucket'] ?? $scopeDto->bucketKey($family, $locale));

                $status = SeoDupDoc::STATUS_OK;
                $text = '';
                if (!$fetch['ok']) {
                    $status = SeoDupDoc::STATUS_FETCH_ERROR;
                    $stats['fetch_error']++;
                } else {
                    $text = $extractor->extract((string)$fetch['body']);
                    if (\mb_strlen($indexer->normalize($text), 'UTF-8') < $scopeDto->minChars) {
                        $status = SeoDupDoc::STATUS_TOO_SHORT;
                        $stats['too_short']++;
                        $text = '';
                    }
                }

                $normalized = $text !== '' ? $indexer->normalize($text) : '';
                $shingles = $normalized !== '' ? $indexer->shingles($normalized) : [];
                $signature = $shingles !== [] ? $indexer->signature($shingles) : [];
                $docId = $this->upsertDoc([
                    'website_id' => $scopeDto->websiteId,
                    'url' => $url,
                    'entity_type' => $entityType,
                    'module' => $module,
                    'locale' => $locale,
                    'content_hash' => $normalized !== '' ? \hash('sha256', $normalized) : '',
                    'minhash' => $signature !== [] ? $indexer->encodeSignature($signature) : '',
                    'shingle_count' => \count($shingles),
                    'status' => $status,
                ]);

                if ($status === SeoDupDoc::STATUS_OK && $shingles !== []) {
                    $docsForIndex[] = [
                        'id' => $docId,
                        'text' => $normalized,
                        'bucket_key' => $bucket,
                    ];
                    $docMeta[$docId] = $url;
                    $stats['compared']++;
                }
            }

            $pairs = $indexer->findNearDuplicates(
                $docsForIndex,
                $scopeDto->jaccardDuplicate,
                $scopeDto->jaccardSuspect,
                $scopeDto->minChars
            );
            $this->persistPairs($runId, $pairs, $docMeta, $stats);

            $finishedAt = date('Y-m-d H:i:s');
            $issueCount = (int)$stats['duplicate'] + (int)$stats['suspect'];
            $runModel->clear()->load($runId);
            $runModel->setData([
                SeoDupRun::schema_fields_STATUS => SeoDupRun::STATUS_COMPLETED,
                SeoDupRun::schema_fields_STATS_JSON => \json_encode($stats, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                SeoDupRun::schema_fields_ISSUE_COUNT => $issueCount,
                SeoDupRun::schema_fields_REPORT_PATH => $reportPath,
                SeoDupRun::schema_fields_REPORT_URL => $reportUrl,
                SeoDupRun::schema_fields_FINISHED_AT => $finishedAt,
            ])->save();

            if ($scopeDto->notify && $issueCount > 0) {
                $notifier->notifyRun($runId, $scopeDto->websiteId, (int)$stats['duplicate'], (int)$stats['suspect'], $reportUrl);
            }

            return [
                'ok' => true,
                'run_id' => $runId,
                'report_path' => $reportPath,
                'report_url' => $reportUrl,
                'stats' => $stats,
                'issue_count' => $issueCount,
            ];
        } catch (\Throwable $e) {
            try {
                $runModel->clear()->load($runId);
                $runModel->setData([
                    SeoDupRun::schema_fields_STATUS => SeoDupRun::STATUS_FAILED,
                    SeoDupRun::schema_fields_STATS_JSON => \json_encode(
                        $stats + ['error' => $e->getMessage()],
                        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
                    ),
                    SeoDupRun::schema_fields_FINISHED_AT => date('Y-m-d H:i:s'),
                    SeoDupRun::schema_fields_REPORT_PATH => $reportPath,
                    SeoDupRun::schema_fields_REPORT_URL => $reportUrl,
                ])->save();
            } catch (\Throwable) {
                // ignore secondary failure
            }
            throw $e;
        }
    }

    /**
     * In-memory scan for tests — no DB persistence.
     *
     * @param list<array{id:string|int,url?:string,text:string,entity_type?:string,locale?:string}> $pages
     * @return array{pairs:list<array<string,mixed>>,stats:array<string,int>}
     */
    public function scanTexts(array $pages, DuplicateCheckScope $scope): array
    {
        $indexer = $this->indexer ?? new MinHashIndexer();
        $docs = [];
        $stats = ['compared' => 0, 'duplicate' => 0, 'suspect' => 0, 'pairs' => 0, 'too_short' => 0];
        foreach ($pages as $page) {
            $entity = \trim((string)($page['entity_type'] ?? 'page'));
            $locale = (string)($page['locale'] ?? '');
            $text = (string)($page['text'] ?? '');
            if (\mb_strlen($indexer->normalize($text), 'UTF-8') < $scope->minChars) {
                $stats['too_short']++;
                continue;
            }
            $docs[] = [
                'id' => $page['id'],
                'text' => $text,
                'bucket_key' => $scope->bucketKey($entity, $locale),
            ];
            $stats['compared']++;
        }
        $pairs = $indexer->findNearDuplicates($docs, $scope->jaccardDuplicate, $scope->jaccardSuspect, $scope->minChars);
        foreach ($pairs as $pair) {
            $stats['pairs']++;
            if (($pair['grade'] ?? '') === MinHashIndexer::GRADE_DUPLICATE) {
                $stats['duplicate']++;
            } elseif (($pair['grade'] ?? '') === MinHashIndexer::GRADE_SUSPECT) {
                $stats['suspect']++;
            }
        }

        return ['pairs' => $pairs, 'stats' => $stats];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUrlRows(DuplicateCheckScope $scope): array
    {
        /** @var SitemapUrl $model */
        $model = ObjectManager::getInstance(SitemapUrl::class);
        $query = $model->reset()
            ->where(SitemapUrl::schema_fields_WEBSITE_ID, $scope->websiteId)
            ->where(SitemapUrl::schema_fields_STATUS, 1);
        $rows = $query->select()->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        /** @var list<array<string, mixed>> $list */
        $list = \array_values(\array_filter($rows, 'is_array'));

        return $scope->filterUrlRows($list);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertDoc(array $data): int
    {
        $url = (string)$data['url'];
        $urlHash = \hash('sha256', $url);
        /** @var SeoDupDoc $model */
        $model = ObjectManager::getInstance(SeoDupDoc::class);
        $existing = $model->reset()
            ->where(SeoDupDoc::schema_fields_WEBSITE_ID, (int)$data['website_id'])
            ->where(SeoDupDoc::schema_fields_URL_HASH, $urlHash)
            ->find()
            ->fetch();
        $payload = [
            SeoDupDoc::schema_fields_WEBSITE_ID => (int)$data['website_id'],
            SeoDupDoc::schema_fields_URL_HASH => $urlHash,
            SeoDupDoc::schema_fields_URL => $url,
            SeoDupDoc::schema_fields_ENTITY_TYPE => (string)$data['entity_type'],
            SeoDupDoc::schema_fields_MODULE => (string)$data['module'],
            SeoDupDoc::schema_fields_LOCALE => (string)$data['locale'],
            SeoDupDoc::schema_fields_CONTENT_HASH => (string)$data['content_hash'],
            SeoDupDoc::schema_fields_MINHASH => (string)$data['minhash'],
            SeoDupDoc::schema_fields_SHINGLE_COUNT => (int)$data['shingle_count'],
            SeoDupDoc::schema_fields_STATUS => (string)$data['status'],
            SeoDupDoc::schema_fields_FETCHED_AT => date('Y-m-d H:i:s'),
        ];
        if ($existing && $existing->getId()) {
            $existing->setData($payload)->save();

            return (int)$existing->getId();
        }
        $model->clear()->setData($payload)->save();

        return (int)$model->getId();
    }

    /**
     * @param list<array{id_a:string|int,id_b:string|int,jaccard:float,grade:string}> $pairs
     * @param array<int|string, string> $docMeta
     * @param array<string, int> $stats
     */
    private function persistPairs(int $runId, array $pairs, array $docMeta, array &$stats): void
    {
        /** @var SeoDupPair $pairModel */
        $pairModel = ObjectManager::getInstance(SeoDupPair::class);
        foreach ($pairs as $pair) {
            $docA = (int)$pair['id_a'];
            $docB = (int)$pair['id_b'];
            if ($docA > $docB) {
                [$docA, $docB] = [$docB, $docA];
            }
            $grade = (string)$pair['grade'];
            $pairModel->clear()->setData([
                SeoDupPair::schema_fields_RUN_ID => $runId,
                SeoDupPair::schema_fields_DOC_A => $docA,
                SeoDupPair::schema_fields_DOC_B => $docB,
                SeoDupPair::schema_fields_URL_A => (string)($docMeta[$pair['id_a']] ?? $docMeta[$docA] ?? ''),
                SeoDupPair::schema_fields_URL_B => (string)($docMeta[$pair['id_b']] ?? $docMeta[$docB] ?? ''),
                SeoDupPair::schema_fields_JACCARD => (string)$pair['jaccard'],
                SeoDupPair::schema_fields_GRADE => $grade,
            ])->save();
            $stats['pairs']++;
            if ($grade === MinHashIndexer::GRADE_DUPLICATE) {
                $stats['duplicate']++;
            } elseif ($grade === MinHashIndexer::GRADE_SUSPECT) {
                $stats['suspect']++;
            }
        }
    }
}
