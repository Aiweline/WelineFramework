<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\Database\ConnectionFactory;
use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 全量 collect 入库打完当前批次后：短语词 collect_batch != 当前批次（含 0 未标记）视为旧词，
 * 按原词删 base + 全部语种行。排除 @meta::（本轮不打标；meta 生命周期另议）。
 * 不扫 locale DISTINCT 孤儿。当前批次触达过少时拒删。
 */
final class DictionaryStaleWordPruneService
{
    public const META_PREFIX = '@meta::';

    private const DELETE_BATCH = 200;
    private const STALE_FETCH_BATCH = 500;

    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
        private readonly WordDictionary $wordDictionary,
    ) {
    }

    /**
     * @return array{
     *   applied: bool,
     *   aborted_reason: string,
     *   alive: int,
     *   phrase_base: int,
     *   stale_words: int,
     *   deleted_base: int,
     *   deleted_locale: int,
     *   current_batch: int
     * }
     */
    public function pruneNotCurrentBatch(int $currentBatch, bool $apply = true): array
    {
        $currentBatch = max(0, $currentBatch);
        if ($currentBatch < 1) {
            return $this->result(false, 'invalid_current_batch', 0, 0, 0, 0, 0, $currentBatch);
        }

        $baseConn = $this->wordDictionary->getConnection();
        $localeConn = $this->localeDictionary->getConnection();
        $baseTable = $this->wordDictionary->getTable();
        $localeTable = $this->localeDictionary->getTable();
        $batchCol = WordDictionary::schema_fields_COLLECT_BATCH;

        $phraseBaseCount = $this->countPhraseBase($baseConn, $baseTable);
        $aliveCount = $this->countPhraseWithBatch($baseConn, $baseTable, $batchCol, $currentBatch);
        $minAlive = max(500, (int)floor($phraseBaseCount * 0.5));
        if ($aliveCount < $minAlive) {
            return $this->result(
                false,
                'alive_too_small:' . $aliveCount . '<' . $minAlive,
                $aliveCount,
                $phraseBaseCount,
                0,
                0,
                0,
                $currentBatch,
            );
        }

        $stale = $this->collectStaleWordsNotBatch($baseConn, $baseTable, $batchCol, $currentBatch);
        $staleCount = count($stale);
        if ($staleCount === 0 || !$apply) {
            return $this->result(
                false,
                $apply ? '' : 'dry_run_or_disabled',
                $aliveCount,
                $phraseBaseCount,
                $staleCount,
                0,
                0,
                $currentBatch,
            );
        }

        $deletedLocale = 0;
        $deletedBase = 0;
        $localeCodes = $this->listLocaleCodes($localeConn, $localeTable);
        foreach (array_chunk($stale, self::DELETE_BATCH) as $chunk) {
            $deletedLocale += $this->deleteLocaleWordsByMd5($localeConn, $localeTable, $chunk, $localeCodes);
            $deletedBase += $this->deleteWords($baseConn, $baseTable, $chunk);
        }

        return $this->result(
            true,
            '',
            $aliveCount,
            $phraseBaseCount,
            $staleCount,
            $deletedBase,
            $deletedLocale,
            $currentBatch,
        );
    }

    /**
     * @return list<string>
     */
    private function collectStaleWordsNotBatch(
        ConnectionFactory $baseConn,
        string $baseTable,
        string $batchCol,
        int $currentBatch,
    ): array {
        $stale = [];
        $pdo = $baseConn->getConnector()->getWrappedConnection()->getPdo();
        $metaLike = $pdo->quote(self::META_PREFIX . '%');
        $sql = "SELECT word FROM {$baseTable}"
            . " WHERE {$batchCol} <> ?"
            . " AND word NOT LIKE {$metaLike}"
            . ' ORDER BY word'
            . ' LIMIT ' . self::STALE_FETCH_BATCH;
        $stmt = $pdo->prepare($sql);
        $lastWord = '';
        while (true) {
            if ($lastWord === '') {
                $stmt->execute([$currentBatch]);
            } else {
                $paged = $pdo->prepare(
                    "SELECT word FROM {$baseTable}"
                    . " WHERE {$batchCol} <> ?"
                    . " AND word NOT LIKE {$metaLike}"
                    . ' AND word > ?'
                    . ' ORDER BY word'
                    . ' LIMIT ' . self::STALE_FETCH_BATCH
                );
                $paged->execute([$currentBatch, $lastWord]);
                $stmt = $paged;
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $word = (string)($row['word'] ?? '');
                if ($word === '') {
                    continue;
                }
                $stale[] = $word;
                $lastWord = $word;
            }
            if (count($rows) < self::STALE_FETCH_BATCH) {
                break;
            }
        }

        return $stale;
    }

    private function countPhraseWithBatch(
        ConnectionFactory $conn,
        string $table,
        string $batchCol,
        int $batch,
    ): int {
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$batchCol} = ?"
            . ' AND word NOT LIKE ' . $pdo->quote(self::META_PREFIX . '%');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$batch]);

        return (int)$stmt->fetchColumn();
    }

    private function countPhraseBase(ConnectionFactory $conn, string $table): int
    {
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $sql = "SELECT COUNT(*) FROM {$table} WHERE word NOT LIKE "
            . $pdo->quote(self::META_PREFIX . '%');

        return (int)$pdo->query($sql)->fetchColumn();
    }

    /**
     * @param list<string> $words
     */
    private function deleteWords(ConnectionFactory $conn, string $table, array $words): int
    {
        if ($words === []) {
            return 0;
        }
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $placeholders = implode(',', array_fill(0, count($words), '?'));
        // base 表 word 为 varchar PK，允许 word IN。
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE word IN ({$placeholders})");
        $stmt->execute(array_values($words));

        return $stmt->rowCount();
    }

    /**
     * locale 表只按 md5 PK 删；禁止 TEXT word IN。
     *
     * @param list<string> $words
     * @param list<string> $localeCodes
     */
    private function deleteLocaleWordsByMd5(
        ConnectionFactory $conn,
        string $table,
        array $words,
        array $localeCodes,
    ): int {
        if ($words === [] || $localeCodes === []) {
            return 0;
        }
        $fingerprints = [];
        foreach ($words as $word) {
            foreach ($localeCodes as $localeCode) {
                $fingerprints[] = LocaleDictionary::generateMd5((string)$word, (string)$localeCode);
            }
        }
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $deleted = 0;
        foreach (array_chunk($fingerprints, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE md5 IN ({$placeholders})");
            $stmt->execute(array_values($chunk));
            $deleted += $stmt->rowCount();
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function listLocaleCodes(ConnectionFactory $conn, string $table): array
    {
        $pdo = $conn->getConnector()->getWrappedConnection()->getPdo();
        $rows = $pdo->query("SELECT DISTINCT locale_code FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN);
        $codes = [];
        foreach ((array)$rows as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return array{
     *   applied: bool,
     *   aborted_reason: string,
     *   alive: int,
     *   phrase_base: int,
     *   stale_words: int,
     *   deleted_base: int,
     *   deleted_locale: int,
     *   current_batch: int
     * }
     */
    private function result(
        bool $applied,
        string $abortedReason,
        int $alive,
        int $phraseBase,
        int $staleWords,
        int $deletedBase,
        int $deletedLocale,
        int $currentBatch,
    ): array {
        return [
            'applied' => $applied,
            'aborted_reason' => $abortedReason,
            'alive' => $alive,
            'phrase_base' => $phraseBase,
            'stale_words' => $staleWords,
            'deleted_base' => $deletedBase,
            'deleted_locale' => $deletedLocale,
            'current_batch' => $currentBatch,
        ];
    }
}
