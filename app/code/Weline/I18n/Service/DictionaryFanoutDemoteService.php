<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;

/**
 * 将历史「每词×每语」扇出降级为稀表：删除非默认语且译=源（占位扇出）的行。
 * 真正 AI/人工覆盖（translate !== word）保留。
 */
final class DictionaryFanoutDemoteService
{
    private const DELETE_BATCH = 500;

    public function __construct(
        private readonly LocaleDictionary $localeDictionary,
    ) {
    }

    /**
     * @return array{dry_run: bool, default_locale: string, scanned: int, deleted: int, kept_overrides: int}
     */
    public function demote(bool $dryRun = false): array
    {
        $defaultLocale = Env::default_LANGUAGE_CODE;
        $pdo = $this->localeDictionary->getConnection()
            ->getConnector()
            ->getWrappedConnection()
            ->getPdo();
        $table = $this->localeDictionary->getTable();

        $scanned = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

        // 非默认语且 translate=word（或空译）视为占位扇出。
        $selectSql = "SELECT md5 FROM {$table}"
            . ' WHERE locale_code <> ?'
            . " AND (translate = '' OR translate = word)";
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute([$defaultLocale]);
        $md5s = [];
        while (($md5 = $stmt->fetchColumn()) !== false) {
            $md5 = (string)$md5;
            if ($md5 !== '') {
                $md5s[] = $md5;
            }
        }

        $toDelete = count($md5s);
        $deleted = 0;
        if (!$dryRun && $md5s !== []) {
            foreach (array_chunk($md5s, self::DELETE_BATCH) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $del = $pdo->prepare("DELETE FROM {$table} WHERE md5 IN ({$placeholders})");
                $del->execute(array_values($chunk));
                $deleted += $del->rowCount();
            }
        } elseif ($dryRun) {
            $deleted = $toDelete;
        }

        $kept = max(0, $scanned - $toDelete);

        return [
            'dry_run' => $dryRun,
            'default_locale' => $defaultLocale,
            'scanned' => $scanned,
            'deleted' => $deleted,
            'kept_overrides' => $kept,
        ];
    }
}
