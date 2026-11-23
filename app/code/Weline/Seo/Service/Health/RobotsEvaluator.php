<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Health;

/**
 * robots.txt 抓取与 Googlebot 规则判定。
 *
 * 规则：最长匹配优先，长度相同 Allow 胜；Disallow 空值表示全允许。
 */
final class RobotsEvaluator
{
    private const CACHE_TTL_SECONDS = 600;

    /** @var array<string, array{content:string, status:int, error:string, fetched_at:int}> */
    private array $cache = [];

    /**
     * 抓取并缓存一个站点的 robots.txt。
     *
     * @return array{ok:bool, content:string, status:int, error:string, url:string}
     */
    public function fetch(string $origin, int $timeout = 5, bool $allowInsecure = false): array
    {
        $origin = rtrim($origin, '/');
        $url = $origin . '/robots.txt';
        $cached = $this->cache[$origin] ?? null;
        if (is_array($cached) && (time() - $cached['fetched_at']) <= self::CACHE_TTL_SECONDS) {
            return [
                'ok' => $cached['error'] === '',
                'content' => $cached['content'],
                'status' => $cached['status'],
                'error' => $cached['error'],
                'url' => $url,
            ];
        }

        [$status, $content, $error] = $this->requestRobots($url, $timeout, $allowInsecure);
        $this->cache[$origin] = [
            'content' => $content,
            'status' => $status,
            'error' => $error,
            'fetched_at' => time(),
        ];

        return [
            'ok' => $error === '',
            'content' => $content,
            'status' => $status,
            'error' => $error,
            'url' => $url,
        ];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{state:string, rule:string, line:int, directive:string, reason:string}
     */
    public function evaluate(string $robotsTxt, string $url, string $userAgent = 'Googlebot'): array
    {
        $path = $this->pathWithQuery($url);
        if ($path === '') {
            return $this->result('unknown', '', 0, '', 'invalid url');
        }

        $groups = $this->parse($robotsTxt);
        if ($groups === []) {
            return $this->result('allowed', '', 0, '', 'robots.txt empty');
        }

        $token = $this->agentToken($userAgent);
        $specificGroups = [];
        $wildcardGroups = [];
        foreach ($groups as $group) {
            if ($this->groupMatches($group['agents'], $token)) {
                $specificGroups[] = $group;
                continue;
            }
            if (in_array('*', $group['agents'], true)) {
                $wildcardGroups[] = $group;
            }
        }

        // RFC 9309：有具体匹配组时只看具体组；* 组只在没有具体组时生效。
        $chosenGroups = $specificGroups !== [] ? $specificGroups : $wildcardGroups;
        $rules = [];
        foreach ($chosenGroups as $group) {
            foreach ($group['rules'] as $rule) {
                $rules[] = $rule;
            }
        }

        if ($rules === []) {
            return $this->result('allowed', '', 0, '', 'no matching group');
        }

        $best = null;
        foreach ($rules as $rule) {
            $pattern = (string)$rule['path'];
            if ($pattern === '') {
                continue;
            }
            if (!$this->pathMatches($pattern, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($best === null
                || $length > $best['length']
                || ($length === $best['length'] && $rule['directive'] === 'allow')
            ) {
                $best = [
                    'length' => $length,
                    'directive' => (string)$rule['directive'],
                    'path' => $pattern,
                    'line' => (int)$rule['line'],
                ];
            }
        }

        if ($best === null) {
            return $this->result('allowed', '', 0, '', 'no rule matched');
        }

        $allowed = $best['directive'] === 'allow';

        return $this->result(
            $allowed ? 'allowed' : 'blocked',
            (string)$best['path'],
            (int)$best['line'],
            (string)$best['directive'],
            $allowed ? 'allow rule matched' : 'disallow rule matched'
        );
    }

    /**
     * @return list<array{agents:list<string>, rules:list<array{directive:string,path:string,line:int}>}>
     */
    private function parse(string $robotsTxt): array
    {
        $groups = [];
        $agents = [];
        $rules = [];
        $hasRules = false;
        $lineNumber = 0;

        $flush = static function () use (&$groups, &$agents, &$rules, &$hasRules): void {
            if ($agents !== [] && $hasRules) {
                $groups[] = ['agents' => $agents, 'rules' => $rules];
            }
            $agents = [];
            $rules = [];
            $hasRules = false;
        };

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) ?: [] as $rawLine) {
            $lineNumber++;
            $line = trim((string)preg_replace('/#.*$/', '', $rawLine));
            if ($line === '') {
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            if ($key === 'user-agent') {
                if ($hasRules) {
                    $flush();
                }
                $agents[] = strtolower($value);
                continue;
            }
            if ($key === 'allow' || $key === 'disallow') {
                if ($agents === []) {
                    continue;
                }
                $hasRules = true;
                $rules[] = [
                    'directive' => $key,
                    'path' => $value,
                    'line' => $lineNumber,
                ];
            }
        }
        $flush();

        return $groups;
    }

    /** @param list<string> $agents */
    private function groupMatches(array $agents, string $token): bool
    {
        foreach ($agents as $agent) {
            $agent = trim($agent);
            if ($agent === '') {
                continue;
            }
            if ($agent === $token) {
                return true;
            }
            // 产品 token 前缀匹配（Googlebot-Image 之类），不做宽泛子串匹配，避免误封。
            if (str_starts_with($token, $agent . '-')) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return true;
        }
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $quoted = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $quoted);
        $regex = '#^' . $regex . ($anchored ? '$' : '') . '#';

        return (bool)preg_match($regex, $path);
    }

    private function pathWithQuery(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $path . $query;
    }

    private function agentToken(string $userAgent): string
    {
        $userAgent = strtolower(trim($userAgent));
        if ($userAgent === '') {
            return 'googlebot';
        }

        return (string)preg_split('/[\s\/]/', $userAgent)[0];
    }

    /** @return array{state:string, rule:string, line:int, directive:string, reason:string} */
    private function result(string $state, string $rule, int $line, string $directive, string $reason): array
    {
        return [
            'state' => $state,
            'rule' => $rule,
            'line' => $line,
            'directive' => $directive,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{0:int, 1:string, 2:string}
     */
    private function requestRobots(string $url, int $timeout, bool $allowInsecure): array
    {
        $timeout = max(1, $timeout);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [0, '', 'curl init failed'];
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => HttpProbe::DEFAULT_USER_AGENT,
                CURLOPT_SSL_VERIFYPEER => !$allowInsecure,
                CURLOPT_SSL_VERIFYHOST => $allowInsecure ? 0 : 2,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            return [$status, is_string($body) ? $body : '', $error];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => 'User-Agent: ' . HttpProbe::DEFAULT_USER_AGENT . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => !$allowInsecure,
                'verify_peer_name' => !$allowInsecure,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $match)) {
                $status = (int)$match[1];
            }
        }
        $error = is_string($body) ? '' : 'failed to fetch robots.txt';

        return [$status, is_string($body) ? $body : '', $error];
    }
}
