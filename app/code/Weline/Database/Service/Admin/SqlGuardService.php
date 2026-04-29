<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

class SqlGuardService
{
    private const WRITE_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'ALTER', 'CREATE', 'DROP', 'TRUNCATE',
        'RENAME', 'GRANT', 'REVOKE', 'MERGE', 'CALL',
    ];

    private const HIGH_RISK_KEYWORDS = [
        'DROP', 'TRUNCATE', 'ALTER', 'RENAME', 'GRANT', 'REVOKE',
    ];

    public function analyze(string $sql): array
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $sql) ?? ''));
        $isWrite = false;
        $isHighRisk = false;
        $statementType = 'UNKNOWN';

        foreach (self::WRITE_KEYWORDS as $keyword) {
            if (str_starts_with($normalized, $keyword)) {
                $isWrite = true;
                $statementType = $keyword;
                break;
            }
        }

        foreach (self::HIGH_RISK_KEYWORDS as $keyword) {
            if (str_starts_with($normalized, $keyword)) {
                $isHighRisk = true;
                break;
            }
        }

        if (!$isWrite && str_starts_with($normalized, 'SELECT')) {
            $statementType = 'SELECT';
        }

        return [
            'statement_type' => $statementType,
            'is_write' => $isWrite,
            'is_high_risk' => $isHighRisk,
            'needs_confirmation' => $isWrite,
            'preview' => mb_substr($normalized, 0, 400),
        ];
    }

    public function assertWriteConfirmation(string $sql, bool $confirmed, string $confirmPhrase): void
    {
        $analysis = $this->analyze($sql);
        if (!$analysis['is_write']) {
            return;
        }

        if (!$confirmed) {
            throw new \RuntimeException((string) __('写操作需要二次确认后才能执行'));
        }

        if ($analysis['is_high_risk'] && $confirmPhrase !== 'I_UNDERSTAND_THE_RISK') {
            throw new \RuntimeException((string) __('高危 SQL 必须输入确认短语 I_UNDERSTAND_THE_RISK'));
        }
    }
}
