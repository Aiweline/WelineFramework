<?php

declare(strict_types=1);

/**
 * Read-only PostgreSQL probe for the AI translation pending-word keyset.
 *
 * Run through the normal app bootstrap so the real pgsql connection is used;
 * PHPUnit's bootstrap forces SANDBOX (sqlite), which cannot execute the
 * PostgreSQL Han regex used by this query.
 */

namespace {
    use Weline\Framework\Manager\ObjectManager;
    use Weline\I18n\Model\Dictionary;
    use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
    use Weline\I18n\Service\AiTranslationService;
    use Weline\I18n\Service\AiTranslationWordSkipStore;

    try {
        ob_start();
        require dirname(__DIR__, 5) . '/bootstrap.php';
        ob_end_clean();
    } catch (\Throwable $throwable) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        echo json_encode([
            'skipped' => true,
            'reason' => 'bootstrap failed: ' . $throwable->getMessage(),
        ], JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(0);
    }

    final class AiPendingKeysetNoSkipStore extends AiTranslationWordSkipStore
    {
        public function __construct()
        {
        }

        public function shouldSkip(string $locale, string $word): bool
        {
            return false;
        }
    }

    try {
        $dictionary = ObjectManager::getInstance(Dictionary::class);
        $localeDictionary = ObjectManager::getInstance(LocaleDictionary::class);
        $dbType = \strtolower((string)$dictionary->getConnection()->getConfigProvider()->getDbType());
        if (!\in_array($dbType, ['pgsql', 'postgres', 'postgresql'], true)) {
            echo json_encode(['skipped' => true, 'reason' => 'pgsql fixture required, got ' . $dbType], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $service = (new \ReflectionClass(AiTranslationService::class))->newInstanceWithoutConstructor();
        foreach ([
            'dictionary' => $dictionary,
            'localeDictionary' => $localeDictionary,
            'wordSkipStore' => new AiPendingKeysetNoSkipStore(),
        ] as $property => $value) {
            (new \ReflectionProperty($service, $property))->setValue($service, $value);
        }

        $locale = null;
        foreach (['hi_IN', 'en_US', 'fr_FR', 'de_DE', 'ar_SA'] as $candidate) {
            if ($service->countUntranslatedWords($candidate, 'zh_Hans_CN') > 0) {
                $locale = $candidate;
                break;
            }
        }
        if ($locale === null) {
            echo json_encode(['skipped' => true, 'reason' => 'no fixture locale has pending words'], JSON_UNESCAPED_UNICODE), PHP_EOL;
            exit(0);
        }

        $limit = 250;
        $started = microtime(true);
        $words = $service->getUntranslatedWords($locale, $limit, 'zh_Hans_CN');
        $elapsedMs = round((microtime(true) - $started) * 1000, 2);
        $count = $service->countUntranslatedWords($locale, 'zh_Hans_CN');

        $link = $dictionary->getConnection()->getConnector()->getLink();
        $indexStatement = $link->query(
            "SELECT indexname, indexdef FROM pg_indexes"
            . " WHERE tablename = 'w_i18n_locale_dictionary' AND indexname LIKE '%idx_locale_word'"
        );
        $indexRows = $indexStatement === false ? [] : $indexStatement->fetchAll(\PDO::FETCH_ASSOC);
        $indexPresent = $indexRows !== [];
        $indexDefinition = (string)($indexRows[0]['indexdef'] ?? '');
        $quotedLocale = $link->quote($locale);
        $antiJoin = 'NOT EXISTS (SELECT 1 FROM ' . $localeDictionary->getTable() . ' l'
            . ' WHERE l.locale_code = ' . $quotedLocale
            . ' AND l.word = d.word AND TRIM(l.translate) <> \'\' AND l.translate <> d.word)';
        $wordWhere = $antiJoin . ' AND d.word NOT LIKE \'@meta::%\' AND d.word ~ \'['
            . "\u{4e00}" . '-' . "\u{9fff}" . ']\'';
        $dictionaryTable = $dictionary->getTable();

        $statement = $link->query(
            'SELECT d.word FROM ' . $dictionaryTable . ' d WHERE ' . $wordWhere
            . ' ORDER BY d.word LIMIT ' . $limit
        );
        $direct = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        $countStatement = $link->query('SELECT COUNT(*) FROM ' . $dictionaryTable . ' d WHERE ' . $wordWhere);
        $directCount = $countStatement === false ? -1 : (int)$countStatement->fetchColumn();

        $missing = array_values(array_diff($direct, $words));
        $extra = array_values(array_diff($words, $direct));
        $translated = 0;
        $check = $link->prepare(
            'SELECT COUNT(*) FROM ' . $localeDictionary->getTable()
            . ' WHERE locale_code = ? AND word = ? AND TRIM(translate) <> \'\' AND translate <> ?'
        );
        foreach ($words as $word) {
            $check->execute([$locale, $word, $word]);
            if ((int)$check->fetchColumn() > 0) {
                $translated++;
            }
        }

        echo json_encode([
            'ok' => $missing === [] && $extra === [] && $translated === 0 && $count === $directCount && $indexPresent,
            'locale' => $locale,
            'words' => count($words),
            'unique' => count(array_unique($words)),
            'direct' => count($direct),
            'missing' => count($missing),
            'extra' => count($extra),
            'already_translated' => $translated,
            'count' => $count,
            'direct_count' => $directCount,
            'index_present' => $indexPresent,
            'index_def' => $indexDefinition,
            'pending_ms' => $elapsedMs,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
    } catch (\Throwable $throwable) {
        echo json_encode([
            'ok' => false,
            'error' => $throwable->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
        exit(1);
    }
}
