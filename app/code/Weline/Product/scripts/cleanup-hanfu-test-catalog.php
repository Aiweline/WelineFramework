<?php

declare(strict_types=1);

const HANFU_CLEANUP_CLI_CONTRACT = 'hanfu-test-catalog-cleanup-cli.v1';
const HANFU_CLEANUP_CLI_RESULT_CONTRACT = 'hanfu-test-catalog-cleanup-cli-result.v1';

/**
 * @param list<string> $arguments
 * @return array{help:bool,mode?:string,website_id?:int,run_id?:string,selection_digest?:string}
 */
function hanfuCleanupParseArguments(array $arguments): array
{
    $help = false;
    $modes = [];
    $website = null;
    $runId = null;

    foreach ($arguments as $argument) {
        if ($argument === '--help') {
            if ($help) {
                throw new InvalidArgumentException('hanfu_cleanup_option_duplicate');
            }
            $help = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $modes[] = ['mode' => 'dry-run', 'digest' => ''];
            continue;
        }
        if (str_starts_with($argument, '--apply=')) {
            $modes[] = ['mode' => 'apply', 'digest' => substr($argument, strlen('--apply='))];
            continue;
        }
        if (str_starts_with($argument, '--verify=')) {
            $modes[] = ['mode' => 'verify', 'digest' => substr($argument, strlen('--verify='))];
            continue;
        }
        if (str_starts_with($argument, '--website=')) {
            if ($website !== null) {
                throw new InvalidArgumentException('hanfu_cleanup_option_duplicate');
            }
            $website = substr($argument, strlen('--website='));
            continue;
        }
        if (str_starts_with($argument, '--run-id=')) {
            if ($runId !== null) {
                throw new InvalidArgumentException('hanfu_cleanup_option_duplicate');
            }
            $runId = substr($argument, strlen('--run-id='));
            continue;
        }
        throw new InvalidArgumentException('hanfu_cleanup_option_unknown');
    }

    if ($help) {
        if (count($arguments) !== 1) {
            throw new InvalidArgumentException('hanfu_cleanup_help_conflict');
        }
        return ['help' => true];
    }

    if (count($modes) === 0) {
        throw new InvalidArgumentException('hanfu_cleanup_mode_required');
    }
    if (count($modes) !== 1) {
        throw new InvalidArgumentException('hanfu_cleanup_mode_conflict');
    }
    if ($website !== '0') {
        throw new InvalidArgumentException('website_id_zero_required');
    }
    if (!is_string($runId) || preg_match('/\A[a-z0-9][a-z0-9-]{2,63}\z/D', $runId) !== 1) {
        throw new InvalidArgumentException('hanfu_cleanup_run_id_invalid');
    }

    $mode = (string)$modes[0]['mode'];
    $digest = strtolower(trim((string)$modes[0]['digest']));
    if ($mode !== 'dry-run' && (strlen($digest) !== 64 || !ctype_xdigit($digest))) {
        throw new InvalidArgumentException('hanfu_cleanup_digest_invalid');
    }

    return [
        'help' => false,
        'mode' => $mode,
        'website_id' => 0,
        'run_id' => $runId,
        'selection_digest' => $digest,
    ];
}

/** @return array<string,mixed> */
function hanfuCleanupHelp(): array
{
    return [
        'contract' => HANFU_CLEANUP_CLI_CONTRACT,
        'usage' => 'cleanup-hanfu-test-catalog.php --website=0 --run-id=<id> (--dry-run|--apply=<digest>|--verify=<digest>)',
        'modes' => ['--dry-run', '--apply=<digest>', '--verify=<digest>'],
        'exit_codes' => (object)[
            '0' => 'success',
            '1' => 'runtime_error',
            '2' => 'usage_error',
        ],
    ];
}

function hanfuCleanupWriteJson($stream, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    fwrite($stream, $json . PHP_EOL);
}

function hanfuCleanupSafeRuntimeCode(Throwable $exception): string
{
    $message = trim($exception->getMessage());
    if (preg_match('/\A[a-z][a-z0-9_]{2,127}\z/D', $message) === 1) {
        return $message;
    }
    return 'hanfu_cleanup_runtime_error';
}

try {
    /** @var list<string> $arguments */
    $arguments = array_values(array_slice($argv, 1));
    $options = hanfuCleanupParseArguments($arguments);
} catch (InvalidArgumentException $exception) {
    hanfuCleanupWriteJson(STDERR, [
        'contract' => HANFU_CLEANUP_CLI_CONTRACT,
        'status' => 'usage_error',
        'code' => $exception->getMessage(),
    ]);
    exit(2);
}

if ($options['help']) {
    hanfuCleanupWriteJson(STDOUT, hanfuCleanupHelp());
    exit(0);
}

$runId = (string)$options['run_id'];
$mode = (string)$options['mode'];
$selectionDigest = (string)$options['selection_digest'];
$artifactNames = [
    'dry-run' => 'cleanup-selection.json',
    'apply' => 'cleanup-report.json',
    'verify' => 'verification.json',
];
$reportPath = dirname(__DIR__, 5) . '/var/hanfu-1688/' . $runId . '/' . $artifactNames[$mode];

try {
    require dirname(__DIR__, 5) . '/app/bootstrap.php';

    /** @var \Weline\Product\Service\HanfuCleanup\HanfuCatalogCleanupService $service */
    $service = \Weline\Framework\Manager\ObjectManager::getInstance(
        \Weline\Product\Service\HanfuCleanup\HanfuCatalogCleanupService::class,
    );
    $result = match ($mode) {
        'dry-run' => $service->preview(0, $runId),
        'apply' => $service->apply(0, $runId, $selectionDigest),
        'verify' => $service->verify(0, $runId, $selectionDigest),
    };
    if (!is_file($reportPath)) {
        throw new RuntimeException('hanfu_cleanup_report_missing');
    }
    clearstatcache(true, $reportPath);
    if ((fileperms($reportPath) & 0777) !== 0600) {
        throw new RuntimeException('hanfu_cleanup_report_permission_invalid');
    }

    $selectionDigest = (string)($result['selection_digest'] ?? $selectionDigest);
    $quarantine = is_array($result['quarantine_manifest'] ?? null)
        ? $result['quarantine_manifest']
        : [];
    hanfuCleanupWriteJson(STDOUT, [
        'contract' => HANFU_CLEANUP_CLI_RESULT_CONTRACT,
        'run_id' => $runId,
        'mode' => $mode,
        'status' => (string)($result['status'] ?? 'ready'),
        'selection_digest' => $selectionDigest,
        'report_path' => $reportPath,
        'service_contract' => (string)($result['contract'] ?? ''),
        'selected_product_count' => is_array($result['product_ids'] ?? null)
            ? count($result['product_ids'])
            : null,
        'protected_reference_count' => is_array($result['protected_references'] ?? null)
            ? count($result['protected_references'])
            : null,
        'media_move_count' => is_array($quarantine['moves'] ?? null)
            ? count($quarantine['moves'])
            : null,
        'media_preserved_count' => is_array($quarantine['preserved'] ?? null)
            ? count($quarantine['preserved'])
            : null,
    ]);
    exit(0);
} catch (Throwable $exception) {
    hanfuCleanupWriteJson(STDERR, [
        'contract' => HANFU_CLEANUP_CLI_CONTRACT,
        'status' => 'runtime_error',
        'code' => hanfuCleanupSafeRuntimeCode($exception),
        'run_id' => $runId,
        'mode' => $mode,
    ]);
    exit(1);
}
