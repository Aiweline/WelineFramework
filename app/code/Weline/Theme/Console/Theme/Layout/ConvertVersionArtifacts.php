<?php

declare(strict_types=1);

namespace Weline\Theme\Console\Theme\Layout;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\Version\ThemeVersionArtifactConverter;

/**
 * One-shot offline converter: theme:layout:convert-version-artifacts.
 * Supports dry-run (default), apply, and resume via --receipt=.
 */
final class ConvertVersionArtifacts extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): void
    {
        $mode = $this->resolveMode($args);
        $receiptId = $this->resolveOption($args, 'receipt') ?? '';
        $inputJson = $this->resolveOption($args, 'input-json');
        $receiptPath = $this->resolveOption($args, 'receipt-file');

        /** @var ThemeVersionArtifactConverter $converter */
        $converter = ObjectManager::getInstance(ThemeVersionArtifactConverter::class);

        $schema = $converter->inspectSchemaPrerequisites();
        $this->printer->note(
            'schema ready=' . ($schema['ready'] ? 'yes' : 'no')
            . '; missing=' . ($schema['missing'] === [] ? '(none)' : \implode(',', $schema['missing']))
        );

        $legacy = null;
        if ($inputJson !== null && $inputJson !== '') {
            $legacy = $converter->loadLegacySnapshotFromJsonFile($inputJson);
        }

        if ($mode === 'apply') {
            $prior = null;
            if ($receiptPath !== null && $receiptPath !== '' && \is_file($receiptPath)) {
                $decoded = \json_decode((string)\file_get_contents($receiptPath), true);
                $prior = \is_array($decoded) ? $decoded : null;
            }
            $result = $converter->apply($prior, $legacy, $receiptId);
            $this->printer->note('转换 apply receipt：' . (string)($result['receipt_id'] ?? ''));
            $this->printer->note('applied：' . \count($result['applied'] ?? []));
            $this->printer->note('skipped：' . \count($result['skipped'] ?? []));
            $this->printer->note('pending_mappings：' . (int)($result['pending_mappings'] ?? 0));
            if (!empty($result['blocked_by_schema'])) {
                $this->printer->error((string)($result['message'] ?? 'schema_blocked'));
                $this->printer->note('blocker：本机 DB 缺少 Task 1 模型列/表，需 setup:upgrade 同步后再 apply。');

                return;
            }
            if (empty($result['ok'])) {
                $this->printer->error((string)($result['message'] ?? 'apply_failed'));

                return;
            }
            $this->printer->success('apply 完成；receipt：' . (string)($result['receipt_path'] ?? ''));

            return;
        }

        if ($legacy === null) {
            $legacy = $converter->loadLegacySnapshotFromDatabase();
        }

        $report = $converter->dryRun(
            $legacy['versions'] ?? [],
            $legacy['releases'] ?? [],
            $legacy['intents'] ?? [],
            $receiptId,
        );

        $this->printer->note('转换 dry-run receipt：' . $report['receipt_id']);
        $this->printer->note('可转换映射(pending)：' . \count($report['mappings']));
        $this->printer->note('已转换(already)：' . \count($report['already_converted'] ?? []));
        $this->printer->note('仅归档：' . \count($report['archive_only']));
        $this->printer->note('需意图映射/阻塞：' . \count($report['blocked']));
        $this->printer->note('指纹：' . $report['fingerprint']);
        $path = $converter->persistReceipt($report);
        $this->printer->success('receipt 已写入：' . $path);
    }

    public function tip(): string
    {
        return '将旧主题范围版本转换为版本独占快照（dry-run / apply / resume）';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'theme:layout:convert-version-artifacts',
            $this->tip(),
            [
                '--dry-run' => '只生成映射与 receipt，不写库（默认）',
                '--apply' => '应用已校验 mapping（幂等；缺 schema 时明确拒绝）',
                '--resume' => '使用同一 receipt 继续（等同 dry-run 再算）',
                '--receipt=' => 'receipt id',
                '--receipt-file=' => 'apply 时读取已有 receipt JSON',
                '--input-json=' => '可选：离线一致性快照 JSON（测试/重跑）',
            ],
            [],
            [],
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function resolveMode(array $args): string
    {
        if (\in_array('--apply', $args, true) || \in_array('apply', $args, true)) {
            return 'apply';
        }
        if (\in_array('--resume', $args, true) || \in_array('resume', $args, true)) {
            return 'dry-run';
        }

        return 'dry-run';
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function resolveOption(array $args, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($args as $arg) {
            if (!\is_string($arg)) {
                continue;
            }
            if (\str_starts_with($arg, $prefix)) {
                return \substr($arg, \strlen($prefix));
            }
        }

        return null;
    }
}
