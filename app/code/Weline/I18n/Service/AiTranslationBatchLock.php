<?php
declare(strict_types=1);

namespace Weline\I18n\Service;

/**
 * Non-blocking exclusive lock per target locale for AI dictionary batches.
 *
 * Prevents cron, queue workers, and manual runs from selecting the same
 * untranslated word set concurrently.
 */
final class AiTranslationBatchLock
{
    private const LOCK_SUBDIR = 'i18n-ai-translation';

    /**
     * @return resource|null Lock handle; null when another worker holds the locale lock.
     */
    public function tryAcquire(string $localeCode, string $owner = ''): mixed
    {
        $localeCode = trim(str_replace('-', '_', $localeCode));
        if ($localeCode === '') {
            return null;
        }

        $directory = $this->lockDirectory();
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return null;
        }

        $handle = @fopen($this->lockPath($localeCode), 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'locale' => $localeCode,
            'owner' => $owner,
            'pid' => getmypid(),
            'acquired_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        fflush($handle);

        return $handle;
    }

    public function release(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function lockDirectory(): string
    {
        return rtrim(BP, "\\/") . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'lock'
            . DIRECTORY_SEPARATOR . self::LOCK_SUBDIR;
    }

    private function lockPath(string $localeCode): string
    {
        $safeLocale = preg_replace('/[^A-Za-z0-9_]+/', '_', $localeCode) ?: 'unknown';

        return $this->lockDirectory() . DIRECTORY_SEPARATOR . $safeLocale . '.lock';
    }
}
