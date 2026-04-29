<?php

declare(strict_types=1);

namespace Weline\Database\Service\Admin;

use Weline\Framework\Database\ConnectionFactory;

class SqlConsoleService
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly SqlGuardService $sqlGuardService
    ) {
    }

    public function execute(string $sql, bool $confirmed = false, string $confirmPhrase = ''): array
    {
        $analysis = $this->sqlGuardService->analyze($sql);
        $this->sqlGuardService->assertWriteConfirmation($sql, $confirmed, $confirmPhrase);

        $query = $this->connectionFactory->query($sql);
        $start = microtime(true);
        $rows = [];
        $affectedRows = 0;

        if ($analysis['is_write']) {
            $affectedRows = $query->execute();
        } else {
            $rows = $query->fetchArray();
            $affectedRows = count($rows);
        }

        return [
            'analysis' => $analysis,
            'rows' => $rows,
            'affected_rows' => $affectedRows,
            'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }
}
