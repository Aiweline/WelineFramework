<?php
declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * Weline ↔ Google 事件字典（etc/event_dictionary.json）。
 */
class EventDictionaryService
{
    private const DICT_RELATIVE = 'etc/event_dictionary.json';

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function getVersion(): string
    {
        $payload = $this->load();
        return (string)($payload['version'] ?? '0.0.0');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(): array
    {
        $events = $this->load()['events'] ?? [];
        return \is_array($events) ? \array_values($events) : [];
    }

    /**
     * @param array<string, mixed> $overrides e.g. ['cta_event_name' => 'generate_lead']
     * @return array<string, mixed>|null
     */
    public function resolve(string $welineEvent, array $overrides = []): ?array
    {
        $normalized = $this->normalizeEventName($welineEvent);
        if ($normalized === '') {
            return null;
        }

        foreach ($this->getEvents() as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $name = $this->normalizeEventName((string)($entry['weline_event'] ?? ''));
            if ($name !== $normalized) {
                continue;
            }

            $resolved = $entry;
            $family = (string)($entry['event_family'] ?? '');
            $ctaOverride = $this->normalizeEventName((string)($overrides['cta_event_name'] ?? $overrides['ctaEventName'] ?? ''));
            if ($family === 'cta' && $ctaOverride !== '') {
                $resolved['ga4_event'] = $ctaOverride;
                $resolved['weline_mapping_source'] = 'site_cta_override';
            } else {
                $resolved['weline_mapping_source'] = 'dictionary';
            }
            $resolved['weline_dict_version'] = $this->getVersion();
            return $resolved;
        }

        return null;
    }

    /**
     * @return array{version: string, events: list<array<string, mixed>>}
     */
    public function listForPanel(): array
    {
        return [
            'version' => $this->getVersion(),
            'events' => $this->getEvents(),
        ];
    }

    /**
     * Runtime config fragment for front-end.
     *
     * @return array{version: string, events: list<array<string, mixed>>}
     */
    public function toRuntimeFragment(): array
    {
        return $this->listForPanel();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        $path = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . self::DICT_RELATIVE;
        if (!\is_file($path)) {
            $this->payload = ['version' => '0.0.0', 'events' => []];
            return $this->payload;
        }

        $raw = \file_get_contents($path);
        $decoded = \is_string($raw) ? \json_decode($raw, true) : null;
        $this->payload = \is_array($decoded) ? $decoded : ['version' => '0.0.0', 'events' => []];
        return $this->payload;
    }

    private function normalizeEventName(string $name): string
    {
        $name = \strtolower(\trim($name));
        $name = \str_replace('-', '_', $name);
        $name = (string)\preg_replace('/[^a-z0-9_]/', '', $name);
        return \substr($name, 0, 64);
    }
}
