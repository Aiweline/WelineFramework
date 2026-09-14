<?php
declare(strict_types=1);

namespace Weline\Shipping\Service\AddressCatalog;

/**
 * 流式读取 .tsv 或 .tsv.gz（首行 header）。
 */
final class TsvGzReader
{
    /**
     * @return \Generator<int, array<string, string>>
     */
    public static function rows(string $path): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $handle = str_ends_with(strtolower($path), '.gz')
            ? @gzopen($path, 'rb')
            : @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open catalog file: ' . $path);
        }

        try {
            $headerLine = self::readLine($handle, str_ends_with(strtolower($path), '.gz'));
            if ($headerLine === null || $headerLine === '') {
                return;
            }
            $headers = str_getcsv($headerLine, "\t");
            $headers = array_map(static fn($h) => trim((string)$h), $headers);
            $lineNo = 1;
            while (($line = self::readLine($handle, str_ends_with(strtolower($path), '.gz'))) !== null) {
                $lineNo++;
                if (trim($line) === '') {
                    continue;
                }
                $cols = str_getcsv($line, "\t");
                $row = [];
                foreach ($headers as $i => $name) {
                    if ($name === '') {
                        continue;
                    }
                    $row[$name] = isset($cols[$i]) ? (string)$cols[$i] : '';
                }
                yield $lineNo => $row;
            }
        } finally {
            if (str_ends_with(strtolower($path), '.gz')) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }
    }

    /** @param resource $handle */
    private static function readLine($handle, bool $gz): ?string
    {
        $line = $gz ? gzgets($handle) : fgets($handle);
        if ($line === false) {
            return null;
        }

        return rtrim($line, "\r\n");
    }

    public static function normalizePostal(string $postal): string
    {
        $postal = trim($postal);
        // Strip ZWSP/ZWNJ/BOM/soft-hyphen and other format chars pasted from chat/docs.
        $postal = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00AD}\p{Cf}\s]+/u', '', $postal) ?? $postal;
        $postal = strtoupper($postal);

        return $postal;
    }
}
