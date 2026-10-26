<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Crawler;

/**
 * One canonical crawler allow/block entry shared by WLS, edge and robots.
 */
final readonly class CrawlerPolicyEntry
{
    /**
     * @param list<string> $uaTokens
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $action,
        public bool $enabled,
        public bool $builtin,
        public string $category,
        public string $reason,
        public string $pattern,
        public array $uaTokens,
        public string $robotsToken,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $pattern = \trim((string)($row['pattern'] ?? ''));
        $id = \trim((string)($row['id'] ?? ''));
        if ($id === '') {
            $id = 'custom-' . \substr(\hash('sha256', $pattern), 0, 12);
        }

        $action = \strtolower(\trim((string)($row['action'] ?? 'block')));
        if ($action !== 'allow') {
            $action = 'block';
        }

        $tokens = [];
        foreach ((array)($row['ua_tokens'] ?? []) as $token) {
            if (!\is_string($token)) {
                continue;
            }
            $token = \trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        $tokens = \array_values(\array_unique($tokens));

        $robotsToken = \trim((string)($row['robots_token'] ?? ''));
        if ($robotsToken === '') {
            $robotsToken = $tokens[0] ?? '';
        }

        return new self(
            $id,
            \trim((string)($row['name'] ?? $id)),
            $action,
            !isset($row['enabled']) || !empty($row['enabled']) && (string)$row['enabled'] !== '0',
            !empty($row['builtin']),
            \trim((string)($row['category'] ?? 'custom')) ?: 'custom',
            \trim((string)($row['reason'] ?? '')),
            $pattern,
            $tokens,
            $robotsToken,
        );
    }

    /**
     * @return array{id:string,name:string,action:string,enabled:bool,builtin:bool,category:string,reason:string,pattern:string,ua_tokens:list<string>,robots_token:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'action' => $this->action,
            'enabled' => $this->enabled,
            'builtin' => $this->builtin,
            'category' => $this->category,
            'reason' => $this->reason,
            'pattern' => $this->pattern,
            'ua_tokens' => $this->uaTokens,
            'robots_token' => $this->robotsToken,
        ];
    }
}
