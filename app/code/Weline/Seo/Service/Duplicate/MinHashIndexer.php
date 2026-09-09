<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Duplicate;

/**
 * Near-duplicate detection via character shingles + MinHash + Jaccard.
 */
final class MinHashIndexer
{
    public const DEFAULT_SHINGLE_SIZE = 3;
    public const DEFAULT_SIGNATURE_SIZE = 128;
    public const DEFAULT_BANDS = 16;
    public const DEFAULT_ROWS_PER_BAND = 8;
    public const GRADE_DUPLICATE = 'duplicate';
    public const GRADE_SUSPECT = 'suspect';
    public const GRADE_OK = 'ok';

    public function __construct(
        private readonly int $shingleSize = self::DEFAULT_SHINGLE_SIZE,
        private readonly int $signatureSize = self::DEFAULT_SIGNATURE_SIZE,
        private readonly int $bands = self::DEFAULT_BANDS,
        private readonly int $rowsPerBand = self::DEFAULT_ROWS_PER_BAND,
    ) {
        if ($this->shingleSize < 2) {
            throw new \InvalidArgumentException('shingleSize must be >= 2');
        }
        if ($this->signatureSize < 8) {
            throw new \InvalidArgumentException('signatureSize must be >= 8');
        }
        if ($this->bands * $this->rowsPerBand !== $this->signatureSize) {
            throw new \InvalidArgumentException('bands * rowsPerBand must equal signatureSize');
        }
    }

    public function normalize(string $text): string
    {
        $text = \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = \mb_strtolower($text, 'UTF-8');
        $text = \preg_replace('/\s+/u', ' ', $text) ?? $text;

        return \trim($text);
    }

    /**
     * @return list<string>
     */
    public function shingles(string $normalizedText): array
    {
        $len = \mb_strlen($normalizedText, 'UTF-8');
        if ($len < $this->shingleSize) {
            return $len > 0 ? [$normalizedText] : [];
        }

        $out = [];
        $seen = [];
        $max = $len - $this->shingleSize;
        for ($i = 0; $i <= $max; $i++) {
            $s = \mb_substr($normalizedText, $i, $this->shingleSize, 'UTF-8');
            if (isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @param list<string> $shingles
     * @return list<int>
     */
    public function signature(array $shingles): array
    {
        $sig = \array_fill(0, $this->signatureSize, \PHP_INT_MAX);
        if ($shingles === []) {
            return \array_fill(0, $this->signatureSize, 0);
        }

        foreach ($shingles as $shingle) {
            for ($i = 0; $i < $this->signatureSize; $i++) {
                $h = $this->hash32($shingle, $i);
                if ($h < $sig[$i]) {
                    $sig[$i] = $h;
                }
            }
        }

        return $sig;
    }

    /**
     * @param list<int> $signature
     * @return list<string> band keys
     */
    public function bandKeys(array $signature): array
    {
        $keys = [];
        for ($b = 0; $b < $this->bands; $b++) {
            $offset = $b * $this->rowsPerBand;
            $slice = \array_slice($signature, $offset, $this->rowsPerBand);
            $keys[] = $b . ':' . \implode(',', $slice);
        }

        return $keys;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    public function jaccard(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = \array_fill_keys($a, true);
        $setB = \array_fill_keys($b, true);
        $intersection = 0;
        foreach ($setA as $k => $_) {
            if (isset($setB[$k])) {
                $intersection++;
            }
        }
        $union = \count($setA) + \count($setB) - $intersection;
        if ($union <= 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    public function grade(float $jaccard, float $duplicateThreshold = 0.85, float $suspectThreshold = 0.70): string
    {
        if ($jaccard >= $duplicateThreshold) {
            return self::GRADE_DUPLICATE;
        }
        if ($jaccard >= $suspectThreshold) {
            return self::GRADE_SUSPECT;
        }

        return self::GRADE_OK;
    }

    /**
     * Build fingerprints and return candidate pairs within the same bucketKey, graded by Jaccard.
     *
     * @param list<array{id:string|int,text:string,bucket_key:string}> $docs
     * @return list<array{id_a:string|int,id_b:string|int,jaccard:float,grade:string}>
     */
    public function findNearDuplicates(
        array $docs,
        float $duplicateThreshold = 0.85,
        float $suspectThreshold = 0.70,
        int $minChars = 200
    ): array {
        /** @var array<string, list<array{id:string|int,shingles:list<string>,bands:list<string>}>> $byBucket */
        $byBucket = [];
        foreach ($docs as $doc) {
            $id = $doc['id'];
            $bucket = (string)($doc['bucket_key'] ?? '');
            $normalized = $this->normalize((string)($doc['text'] ?? ''));
            if (\mb_strlen($normalized, 'UTF-8') < $minChars) {
                continue;
            }
            $shingles = $this->shingles($normalized);
            if ($shingles === []) {
                continue;
            }
            $sig = $this->signature($shingles);
            $byBucket[$bucket][] = [
                'id' => $id,
                'shingles' => $shingles,
                'bands' => $this->bandKeys($sig),
            ];
        }

        $pairs = [];
        $seenPair = [];
        foreach ($byBucket as $items) {
            /** @var array<string, list<int>> $lsh */
            $lsh = [];
            foreach ($items as $idx => $item) {
                foreach ($item['bands'] as $bandKey) {
                    $lsh[$bandKey][] = $idx;
                }
            }

            $candidate = [];
            foreach ($lsh as $indexes) {
                $n = \count($indexes);
                if ($n < 2) {
                    continue;
                }
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $a = $indexes[$i];
                        $b = $indexes[$j];
                        if ($a > $b) {
                            [$a, $b] = [$b, $a];
                        }
                        $candidate[$a . ':' . $b] = [$a, $b];
                    }
                }
            }

            foreach ($candidate as [$a, $b]) {
                $idA = $items[$a]['id'];
                $idB = $items[$b]['id'];
                $left = (string)$idA;
                $right = (string)$idB;
                if ($left > $right) {
                    [$idA, $idB, $left, $right] = [$idB, $idA, $right, $left];
                }
                $pairKey = $left . "\0" . $right;
                if (isset($seenPair[$pairKey])) {
                    continue;
                }
                $seenPair[$pairKey] = true;

                $score = $this->jaccard($items[$a]['shingles'], $items[$b]['shingles']);
                $grade = $this->grade($score, $duplicateThreshold, $suspectThreshold);
                if ($grade === self::GRADE_OK) {
                    continue;
                }
                $pairs[] = [
                    'id_a' => $idA,
                    'id_b' => $idB,
                    'jaccard' => \round($score, 6),
                    'grade' => $grade,
                ];
            }
        }

        \usort($pairs, static function (array $x, array $y): int {
            return $y['jaccard'] <=> $x['jaccard'];
        });

        return $pairs;
    }

    /**
     * @param list<int> $signature
     */
    public function encodeSignature(array $signature): string
    {
        return \implode(',', \array_map('strval', $signature));
    }

    /**
     * @return list<int>
     */
    public function decodeSignature(string $encoded): array
    {
        if ($encoded === '') {
            return \array_fill(0, $this->signatureSize, 0);
        }
        $parts = \explode(',', $encoded);
        $out = [];
        foreach ($parts as $p) {
            $out[] = (int)$p;
        }
        while (\count($out) < $this->signatureSize) {
            $out[] = 0;
        }

        return \array_slice($out, 0, $this->signatureSize);
    }

    private function hash32(string $value, int $seed): int
    {
        // FNV-1a variant with seed mixing — deterministic across PHP versions.
        $h = 2166136261 ^ ($seed * 16777619);
        $len = \strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $h ^= \ord($value[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }

        return $h;
    }
}
