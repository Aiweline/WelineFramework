<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Exception;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 升级期合并 BypassProvider 规则（可选事件追加）写入侧车。
 */
final class FpcBypassCollector
{
    public function __construct(
        private readonly FpcBypassRuleRegistry $registry,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collectAndPersist(): array
    {
        $byId = [];
        foreach ($this->registry->all() as $provider) {
            foreach ($provider->rules() as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }
                $normalized = $this->normalizeRule($rule);
                if ($normalized === null) {
                    continue;
                }
                $byId[$normalized['id']] = $normalized;
            }
        }

        try {
            $events = ObjectManager::getInstance(EventsManager::class);
            $data = ['rules' => \array_values($byId)];
            $events->dispatch('Weline_Framework::fpc.bypass.collect', $data);
            $extra = $data['rules'] ?? [];
            if (\is_array($extra)) {
                foreach ($extra as $rule) {
                    if (!\is_array($rule)) {
                        continue;
                    }
                    $normalized = $this->normalizeRule($rule);
                    if ($normalized === null) {
                        continue;
                    }
                    $byId[$normalized['id']] = $normalized;
                }
            }
        } catch (\Throwable) {
        }

        $rules = \array_values($byId);
        $path = BP . FpcBypassEvaluator::SIDECAR_RELATIVE;
        $dir = \dirname($path);
        if (!\is_dir($dir) && !\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            throw new Exception(__('无法创建 FPC bypass 侧车目录'));
        }
        $export = \var_export([
            'schema_version' => FpcBypassEvaluator::SCHEMA,
            'rules' => $rules,
        ], true);
        if (\file_put_contents($path, "<?php\nreturn " . $export . ";\n") === false) {
            throw new Exception(__('无法写入 FPC bypass 侧车'));
        }
        FpcBypassEvaluator::clearCache();

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private function normalizeRule(array $rule): ?array
    {
        $id = \trim((string)($rule['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $matchIn = \is_array($rule['match'] ?? null) ? $rule['match'] : [];
        $match = [];
        if (isset($matchIn['query_keys']) && \is_array($matchIn['query_keys'])) {
            $keys = [];
            foreach ($matchIn['query_keys'] as $key) {
                $key = \trim((string)$key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
            if ($keys !== []) {
                $match['query_keys'] = \array_values(\array_unique($keys));
            }
        }
        $cookieRegex = \trim((string)($matchIn['cookie_name_regex'] ?? ''));
        if ($cookieRegex !== '') {
            $match['cookie_name_regex'] = $cookieRegex;
        }
        if (isset($matchIn['request_headers']) && \is_array($matchIn['request_headers'])) {
            $headers = [];
            foreach ($matchIn['request_headers'] as $header) {
                $header = \strtolower(\trim((string)$header));
                if ($header !== '') {
                    $headers[] = $header;
                }
            }
            if ($headers !== []) {
                $match['request_headers'] = \array_values(\array_unique($headers));
            }
        }
        if (isset($matchIn['env_flags']) && \is_array($matchIn['env_flags'])) {
            $flags = [];
            foreach ($matchIn['env_flags'] as $flag) {
                $flag = \trim((string)$flag);
                if ($flag !== '') {
                    $flags[] = $flag;
                }
            }
            if ($flags !== []) {
                $match['env_flags'] = \array_values(\array_unique($flags));
            }
        }
        if ($match === []) {
            return null;
        }

        return [
            'id' => $id,
            'match' => $match,
            'effect' => 'bypass_serve_and_publish',
        ];
    }
}
