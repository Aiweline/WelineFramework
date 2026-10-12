<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\I18n\Model\Dictionary as WordDictionary;
use Weline\SystemConfig\Api\ConfigStore as SystemConfig;

/**
 * 词典收集批次号（local / meta 共用 API；本轮仅 local 词打标）。
 *
 * 入库时把见到的词标成当前批次；全量搞完后「不是当前批次」= 旧词。
 * 成功收口才 commit；失败不抬。allocate = max(committed, DB max)+1，
 * 避免中途失败留下的未提交批次污染下一轮。
 */
final class DictionaryCollectBatchService
{
    public const MODULE = 'Weline_I18n';
    public const AREA = SystemConfig::area_BACKEND;
    public const KEY = 'dictionary_collect_batch';

    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly WordDictionary $wordDictionary,
    ) {
    }

    public function getCommittedBatch(): int
    {
        return max(0, (int)($this->readConfig()['committed'] ?? 0));
    }

    /**
     * 分配下一收集批次号（不写水位）。
     */
    public function allocateNextBatch(): int
    {
        return max($this->getCommittedBatch(), $this->maxBatchInDictionary()) + 1;
    }

    /**
     * 仅当 $batch 严格大于已提交水位时写入。
     */
    public function commitBatch(int $batch): void
    {
        $batch = max(0, $batch);
        $committed = $this->getCommittedBatch();
        if ($batch <= $committed) {
            return;
        }
        $this->systemConfig->setConfig(
            self::KEY,
            (string)json_encode(['committed' => $batch], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            self::MODULE,
            self::AREA,
        );
    }

    /**
     * @return array{committed: int}
     */
    private function readConfig(): array
    {
        $raw = $this->systemConfig->getConfig(self::KEY, self::MODULE, self::AREA);
        $config = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($config)) {
            $config = [];
        }

        return [
            'committed' => max(0, (int)($config['committed'] ?? 0)),
        ];
    }

    private function maxBatchInDictionary(): int
    {
        try {
            $table = $this->wordDictionary->getTable();
            $pdo = $this->wordDictionary->getConnection()
                ->getConnector()
                ->getWrappedConnection()
                ->getPdo();
            $max = $pdo->query(
                'SELECT COALESCE(MAX(' . WordDictionary::schema_fields_COLLECT_BATCH . '), 0) FROM ' . $table
            )->fetchColumn();

            return max(0, (int)$max);
        } catch (\Throwable) {
            return 0;
        }
    }
}
