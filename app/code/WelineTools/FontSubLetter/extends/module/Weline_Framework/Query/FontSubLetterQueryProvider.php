<?php
declare(strict_types=1);

namespace WelineTools\FontSubLetter\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\Session;
use WelineTools\FontSubLetter\Model\FontRecord;
use WelineTools\FontSubLetter\Service\FontProcessor;

class FontSubLetterQueryProvider implements QueryProviderInterface
{
    private const UPLOAD_TICKETS_SESSION_KEY = 'font_sub_letter_upload_tickets';
    private const UPLOAD_TICKET_TTL = 120;

    public function getProviderName(): string
    {
        return 'fontSubLetter';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'uploadTicket' => $this->createUploadTicket(),
            'extract' => $this->extract($params),
            'generate' => $this->generate($params),
            'clearSelected' => $this->clearSelected(),
            'getSelected' => $this->getSelected(),
            default => throw new \InvalidArgumentException('FontSubLetter query provider does not support operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'fontSubLetter',
            'name' => __('Font subset frontend worker API'),
            'description' => __('Frontend font subset tool operations exposed through Weline worker API.'),
            'module' => 'WelineTools_FontSubLetter',
            'operations' => [
                $this->operation('uploadTicket', 'write', false, 2, 'Issue one-time font upload ticket', []),
                $this->operation('extract', 'read', false, 3, 'Extract font characters', [
                    'record_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                ]),
                $this->operation('generate', 'write', false, 5, 'Generate subset font', [
                    'record_id' => ['type' => 'int', 'required' => true, 'min' => 1],
                    'selected_chars' => ['type' => 'list', 'required' => true, 'max_items' => 200],
                    'custom_chars' => ['type' => 'string', 'required' => false, 'max_length' => 4096],
                ]),
                $this->operation('clearSelected', 'write', false, 1, 'Clear remembered characters', []),
                $this->operation('getSelected', 'read', true, 1, 'Get remembered characters', []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extract(array $params): array
    {
        try {
            $record = $this->loadRecord((int)($params['record_id'] ?? 0));
            $characters = $this->processor()->extractCharacters($record);

            return $this->success([
                'characters' => $characters,
                'count' => \count($characters),
            ], 'Characters extracted successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function generate(array $params): array
    {
        try {
            $record = $this->loadRecord((int)($params['record_id'] ?? 0));
            $selectedChars = $this->normalizeSelectedChars($params['selected_chars'] ?? []);
            if ($selectedChars === []) {
                throw new \InvalidArgumentException('Please select at least one character.');
            }

            $outputPath = $this->processor()->generateSubsetFont($record, $selectedChars);
            $this->session()->setData('remembered_chars', [
                'selectedChars' => $selectedChars,
                'customChars' => (string)($params['custom_chars'] ?? ''),
            ]);

            return $this->success([
                'download_url' => $outputPath,
                'filename' => (string)$record->getData('processed_filename'),
            ], 'Subset font generated successfully.');
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clearSelected(): array
    {
        $this->session()->unsetData('remembered_chars');
        return $this->success([], 'Remembered characters cleared.');
    }

    /**
     * @return array<string, mixed>
     */
    private function getSelected(): array
    {
        $remembered = $this->session()->getData('remembered_chars') ?: [
            'selectedChars' => [],
            'customChars' => '',
        ];

        return $this->success($remembered, 'Remembered characters loaded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function createUploadTicket(): array
    {
        $token = \bin2hex(\random_bytes(24));
        $tickets = $this->getUploadTickets();
        $tickets[\hash('sha256', $token)] = \time() + self::UPLOAD_TICKET_TTL;
        $this->session()->setData(self::UPLOAD_TICKETS_SESSION_KEY, $tickets);

        return $this->success([
            'ticket' => $token,
            'method' => 'POST',
            'url' => $this->url()->getFrontendUrl('font-sub-letter/frontend/upload'),
            'expires_at' => \time() + self::UPLOAD_TICKET_TTL,
        ], 'Upload ticket issued.');
    }

    /**
     * @return array<string, int>
     */
    private function getUploadTickets(): array
    {
        $now = \time();
        $stored = $this->session()->getData(self::UPLOAD_TICKETS_SESSION_KEY);
        $tickets = \is_array($stored) ? $stored : [];

        return \array_filter($tickets, static fn(mixed $expiresAt): bool => \is_int($expiresAt) && $expiresAt > $now);
    }

    private function loadRecord(int $recordId): FontRecord
    {
        if ($recordId <= 0) {
            throw new \InvalidArgumentException('record_id is required.');
        }

        /** @var FontRecord $record */
        $record = ObjectManager::getInstance(FontRecord::class)->load($recordId);
        if (!$record->getId()) {
            throw new \RuntimeException('Font record does not exist.');
        }

        return $record;
    }

    /**
     * @param mixed $selectedChars
     * @return array<int, int>
     */
    private function normalizeSelectedChars(mixed $selectedChars): array
    {
        if (\is_string($selectedChars)) {
            $decoded = \json_decode($selectedChars, true);
            $selectedChars = \json_last_error() === \JSON_ERROR_NONE ? $decoded : [];
        }
        if (!\is_array($selectedChars)) {
            return [];
        }

        $normalized = [];
        foreach ($selectedChars as $charCode) {
            $code = (int)$charCode;
            if ($code > 0) {
                $normalized[] = $code;
            }
        }

        return \array_values(\array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function success(array $data, string $message): array
    {
        return [
            'success' => true,
            'error' => false,
            'code' => 200,
            'msg' => $message,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'error' => true,
            'code' => 500,
            'msg' => $message,
            'message' => $message,
            'data' => null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $params
     * @return array<string, mixed>
     */
    private function operation(string $name, string $mode, bool $graph, int $cost, string $summary, array $params): array
    {
        return [
            'name' => $name,
            'description' => __($summary),
            'frontend' => true,
            'mode' => $mode,
            'graph' => $graph,
            'cost' => $cost,
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
    }

    private function processor(): FontProcessor
    {
        return ObjectManager::getInstance(FontProcessor::class);
    }

    private function session(): Session
    {
        return ObjectManager::getInstance(Session::class);
    }

    private function url(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }
}
