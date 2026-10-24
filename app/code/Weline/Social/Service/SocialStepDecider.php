<?php

declare(strict_types=1);

namespace Weline\Social\Service;

final class SocialStepDecider
{
    public const STEP_BUDGET = 25;

    public const ALLOWED_ACTIONS = [
        'navigate',
        'click',
        'type',
        'upload',
        'scroll',
        'wait',
        'assert',
        'done',
        'give_up',
    ];

    /**
     * @return array{action:string,args:array<string,mixed>,done:bool,error:string}
     */
    public function decide(array $job, array $snapshot, array $recipe, int $stepNo): array
    {
        if ($stepNo > self::STEP_BUDGET) {
            return $this->giveUp('step_budget');
        }
        foreach (['recipe_missing', 'entry_unresolved'] as $recipeError) {
            if (\in_array($recipeError, $this->stringList($recipe['give_up'] ?? []), true)) {
                return $this->giveUp($recipeError);
            }
        }
        if (!$this->validVerify($recipe)) {
            return $this->giveUp('recipe_invalid');
        }

        $entry = $this->resolveEntry($recipe, $job);
        if (\trim((string)($recipe['entry'] ?? '')) !== '' && $entry === '') {
            return $this->giveUp('recipe_invalid');
        }

        $visibleText = $this->visibleText($snapshot);
        foreach ($this->stringList($recipe['give_up'] ?? []) as $blockedText) {
            if ($this->contains($visibleText, $blockedText)) {
                return $this->giveUp('page_blocked');
            }
        }

        $currentUrl = $this->currentUrl($snapshot);
        // 先判发布成功再判入口：平台发布后多为 SPA 跳转（/user/status/id），
        // 若先比较 entry 会把已成功的页面重新导航回 compose，循环到步数预算。
        // 发布成功判定需要三个前提：URL 命中、仍在同一站点、本单至少执行过一步动作。
        // 只凭 URL 会把「复用已有 /status/ 标签页」误判为已发布（R1）。
        if ($currentUrl !== ''
            && $stepNo > 1
            && $this->sameHost($currentUrl, $entry)
            && $this->actionsCompleted($recipe, $job)
            && $this->verifyMatches($recipe['verify'], $snapshot)) {
            return $this->action('done', ['remote_url' => $currentUrl], true);
        }

        if ($entry !== '' && ($currentUrl === '' || !$this->sameDocument($currentUrl, $entry))) {
            return $this->action('navigate', ['url' => $entry]);
        }

        $branch = $this->matchBranch($recipe, $visibleText);
        if ($branch !== null) {
            $branchDecision = $this->branchDecision($branch, $snapshot, $recipe, $job, $stepNo);
            if ($branchDecision !== null) {
                return $branchDecision;
            }
        }

        foreach ($this->fieldDefinitions($recipe) as $field) {
            if (!$this->isFieldInput($field['role'])) {
                continue;
            }
            $element = $this->findElement($snapshot, $field['role'], $field['anchor']);
            if ($element === null) {
                return $stepNo <= 10
                    ? $this->action('scroll', ['direction' => 'down'])
                    : $this->giveUp('anchor_not_found');
            }
            $handle = $this->safeHandle($element);
            if ($handle < 1) {
                return $this->giveUp('anchor_not_found');
            }
            $value = $this->fieldValue($job, $field['key']);
            if ($value === '' || $this->elementValue($element) === $value) {
                continue;
            }

            return $this->action('type', ['handle' => $handle, 'text' => $value]);
        }

        $mediaDecision = $this->mediaDecision($job, $snapshot, $recipe, $stepNo);
        if ($mediaDecision !== null) {
            return $mediaDecision;
        }

        $submitHandle = $this->findSubmit($snapshot, $recipe);
        if ($submitHandle > 0) {
            return $this->action('click', ['handle' => $submitHandle]);
        }

        return $this->action('assert', ['expect' => $this->verifyExpectation($recipe['verify'])]);
    }

    private function validVerify(array $recipe): bool
    {
        $verify = $recipe['verify'] ?? null;
        if (!\is_array($verify) || $verify === []) {
            return false;
        }

        return $this->stringList($verify['url_matches'] ?? []) !== []
            || $this->stringList($verify['text_any'] ?? []) !== [];
    }

    private function resolveEntry(array $recipe, array $job): string
    {
        $entry = \trim((string)($recipe['entry'] ?? ''));
        if ($entry === '') {
            return '';
        }

        $payload = $this->payload($job);
        $entry = \preg_replace_callback(
            '/\{([A-Za-z0-9_]+)\}/',
            static function (array $matches) use ($payload): string {
                $value = $payload[$matches[1]] ?? null;

                return \is_scalar($value) ? \rawurlencode((string)$value) : $matches[0];
            },
            $entry
        );
        $entry = \is_string($entry) ? $entry : '';
        if ($entry === '' || \preg_match('/\{[A-Za-z0-9_]+\}/', $entry) === 1) {
            return '';
        }

        return $this->safeUrl($entry);
    }

    private function safeUrl(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        if (\str_starts_with($url, '/')) {
            return \str_starts_with($url, '//') ? '' : $url;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '';
        }
        $scheme = \strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        return $url;
    }

    private function sameDocument(string $currentUrl, string $entry): bool
    {
        $currentPath = \parse_url($currentUrl, \PHP_URL_PATH);
        if (!\is_string($currentPath)) {
            return false;
        }
        $entryPath = \parse_url($entry, \PHP_URL_PATH);
        $entryHost = \parse_url($entry, \PHP_URL_HOST);
        if (\is_string($entryHost) && $entryHost !== '') {
            $currentHost = \parse_url($currentUrl, \PHP_URL_HOST);
            if (!\is_string($currentHost) || \strcasecmp($currentHost, $entryHost) !== 0) {
                return false;
            }
        }
        if (!\is_string($entryPath) || $entryPath === '' || $entryPath === '/') {
            return true;
        }

        $currentPath = '/' . \ltrim($currentPath, '/');
        $entryPath = '/' . \ltrim($entryPath, '/');

        return $currentPath === $entryPath
            || \str_starts_with($currentPath, \rtrim($entryPath, '/') . '/');
    }

    /**
     * done 之前必须真的执行过本配方要求的动作：有输入框要 type 过，有提交按钮要 click 过。
     * 只靠 stepNo 会被「连续 navigate」满足（OPC R8）。
     */
    private function actionsCompleted(array $recipe, array $job): bool
    {
        $executed = \array_map('strtolower', \array_map('strval', (array)($job['_executed_actions'] ?? [])));
        if ($executed === []) {
            return false;
        }

        $needsType = false;
        foreach ($this->fieldDefinitions($recipe) as $field) {
            if ($this->isFieldInput($field['role'])) {
                $needsType = true;
                break;
            }
        }
        $needsClick = \is_array($recipe['submit'] ?? null) || \is_string($recipe['submit'] ?? null);
        if (!$needsClick) {
            foreach ($this->fieldDefinitions($recipe) as $field) {
                if ($this->isButtonRole($field['role'])) {
                    $needsClick = true;
                    break;
                }
            }
        }

        if ($needsType && !\in_array('type', $executed, true)) {
            return false;
        }
        if ($needsClick && !\in_array('click', $executed, true)) {
            return false;
        }

        return true;
    }

    private function sameHost(string $url, string $entry): bool
    {
        $currentHost = \strtolower((string)\parse_url($url, \PHP_URL_HOST));
        $entryHost = \strtolower((string)\parse_url($entry, \PHP_URL_HOST));
        if ($currentHost === '' || $entryHost === '') {
            return $entry === '';
        }

        return $currentHost === $entryHost
            || \str_ends_with($currentHost, '.' . $entryHost)
            || \str_ends_with($entryHost, '.' . $currentHost);
    }

    private function currentUrl(array $snapshot): string
    {
        foreach (['url', 'current_url', 'page_url'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (\is_string($value) && \trim($value) !== '') {
                return \trim($value);
            }
        }

        return '';
    }

    private function visibleText(array $snapshot): string
    {
        $parts = [];
        foreach (['visible_text', 'text', 'body_text'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (\is_string($value)) {
                $parts[] = $value;
            } elseif (\is_array($value)) {
                foreach ($value as $item) {
                    if (\is_scalar($item)) {
                        $parts[] = (string)$item;
                    }
                }
            }
        }
        foreach ($this->elements($snapshot) as $element) {
            foreach (['text', 'label', 'placeholder', 'value'] as $key) {
                $value = $element[$key] ?? null;
                if (\is_scalar($value)) {
                    $parts[] = (string)$value;
                }
            }
        }

        return \implode(' ', $parts);
    }

    private function matchBranch(array $recipe, string $visibleText): ?array
    {
        foreach ((array)($recipe['branches'] ?? []) as $branch) {
            if (!\is_array($branch)) {
                continue;
            }
            foreach ($this->stringList($branch['when_text'] ?? []) as $needle) {
                if ($this->contains($visibleText, $needle)) {
                    return $branch;
                }
            }
        }

        return null;
    }

    private function branchDecision(array $branch, array $snapshot, array $recipe, array $job, int $stepNo): ?array
    {
        $do = \trim((string)($branch['do'] ?? ''));
        if ($do === '') {
            return null;
        }

        $parts = \explode(':', $do, 2);
        $action = \strtolower(\trim($parts[0]));
        $argument = \trim((string)($parts[1] ?? ''));
        if (!\in_array($action, self::ALLOWED_ACTIONS, true)) {
            return $this->giveUp('action_not_allowed');
        }

        switch ($action) {
            case 'give_up':
                return $this->giveUp($argument !== '' ? $argument : 'branch_give_up');

            case 'navigate':
                $target = '';
                if (isset($branch['url']) && \is_string($branch['url'])) {
                    $target = $branch['url'];
                } elseif ($argument !== '' && isset($recipe['routes']) && \is_array($recipe['routes']) && \is_string($recipe['routes'][$argument] ?? null)) {
                    $target = (string)$recipe['routes'][$argument];
                } elseif ($argument !== '') {
                    $target = $argument;
                }
                $target = $this->safeUrl($target);
                if ($target === '') {
                    return $this->giveUp('recipe_invalid');
                }

                return $this->action('navigate', ['url' => $target]);

            case 'click':
                $role = \strtolower(\trim((string)($branch['role'] ?? 'button')));
                $anchors = $this->stringList($branch['anchor'] ?? $branch['anchors'] ?? $branch['when_text'] ?? []);
                $element = $this->findElement($snapshot, $role, $anchors);
                $handle = $element !== null ? $this->safeHandle($element) : 0;
                if ($handle < 1) {
                    return $this->giveUp('anchor_not_found');
                }

                return $this->action('click', ['handle' => $handle]);

            case 'type':
                $fieldKey = $argument !== '' ? $argument : (string)($branch['field'] ?? '');
                $field = $this->fieldByKey($recipe, $fieldKey);
                if ($field === null) {
                    return $this->giveUp('recipe_invalid');
                }
                $element = $this->findElement($snapshot, $field['role'], $field['anchor']);
                $handle = $element !== null ? $this->safeHandle($element) : 0;
                $value = $this->fieldValue($job, $field['key']);
                if ($handle < 1 || $value === '') {
                    return $this->giveUp('anchor_not_found');
                }

                return $this->action('type', ['handle' => $handle, 'text' => $value]);

            case 'upload':
                return $this->mediaDecision($job, $snapshot, $recipe, $stepNo)
                    ?? $this->giveUp('media_not_found');

            case 'scroll':
                $direction = \strtolower(\trim($argument !== '' ? $argument : (string)($branch['direction'] ?? 'down')));

                return $this->action('scroll', [
                    'direction' => \in_array($direction, ['up', 'down', 'left', 'right'], true) ? $direction : 'down',
                ]);

            case 'wait':
                $ms = (int)($branch['ms'] ?? ($argument !== '' ? $argument : 1000));

                return $this->action('wait', ['ms' => \max(100, \min(5000, $ms))]);

            case 'assert':
                return $this->action('assert', ['expect' => $this->verifyExpectation($recipe['verify'])]);

            case 'done':
                return $this->action('done', ['remote_url' => $this->currentUrl($snapshot)], true);
        }

        return $this->giveUp('action_not_allowed');
    }

    /**
     * @return list<array{key:string,role:string,anchor:list<string>}>
     */
    private function fieldDefinitions(array $recipe): array
    {
        $fields = [];
        foreach ((array)($recipe['fields'] ?? []) as $key => $field) {
            if (!\is_array($field)) {
                continue;
            }
            $fieldKey = \trim((string)($field['key'] ?? (\is_string($key) ? $key : '')));
            if ($fieldKey === '') {
                continue;
            }
            $fields[] = [
                'key' => $fieldKey,
                'role' => \strtolower(\trim((string)($field['role'] ?? ''))),
                'anchor' => $this->stringList($field['anchor'] ?? $field['anchors'] ?? []),
            ];
        }

        return $fields;
    }

    private function fieldByKey(array $recipe, string $fieldKey): ?array
    {
        $fieldKey = \trim($fieldKey);
        if ($fieldKey === '') {
            return null;
        }
        foreach ($this->fieldDefinitions($recipe) as $field) {
            if ($field['key'] === $fieldKey) {
                return $field;
            }
        }

        return null;
    }

    private function isFieldInput(string $role): bool
    {
        if ($role === '') {
            return true;
        }

        return \in_array($role, [
            'textbox',
            'text',
            'textarea',
            'combobox',
            'searchbox',
            'input',
            'email',
            'url',
            'tel',
            'number',
        ], true);
    }

    private function isButtonRole(string $role): bool
    {
        return \in_array($role, ['button', 'link', 'menuitem'], true);
    }

    private function findElement(array $snapshot, string $role, array $anchors): ?array
    {
        foreach ($this->elements($snapshot) as $element) {
            if (!$this->roleMatches($role, $element)) {
                continue;
            }
            if ($anchors !== [] && !$this->anchorsMatch($anchors, $element)) {
                continue;
            }

            return $element;
        }

        return null;
    }

    private function roleMatches(string $role, array $element): bool
    {
        $role = \strtolower(\trim($role));
        if ($role === '') {
            return true;
        }

        $elementRole = \strtolower(\trim((string)($element['role'] ?? '')));
        $tag = \strtolower(\trim((string)($element['tag'] ?? $element['tag_name'] ?? '')));
        $type = \strtolower(\trim((string)($element['type'] ?? '')));

        if ($role === 'file') {
            return $elementRole === 'file' || ($tag === 'input' && $type === 'file');
        }
        if ($role === 'button' || $role === 'menuitem') {
            return \in_array($elementRole, ['button', 'menuitem', 'link'], true)
                || $tag === 'button'
                || $tag === 'a'
                || ($tag === 'input' && \in_array($type, ['button', 'submit'], true));
        }
        if ($role === 'link') {
            return $elementRole === 'link' || $tag === 'a';
        }
        if ($role === 'combobox') {
            return $elementRole === 'combobox' || $tag === 'select';
        }
        if ($role === 'textbox' || $role === 'text' || $role === 'textarea' || $role === 'searchbox' || $role === 'input') {
            if (\in_array($elementRole, ['textbox', 'text', 'textarea', 'searchbox', 'combobox', 'input'], true)) {
                return true;
            }

            return $tag === 'textarea'
                || ($tag === 'input' && !\in_array($type, ['file', 'button', 'submit', 'checkbox', 'radio', 'hidden'], true));
        }
        if ($elementRole !== '') {
            return $elementRole === $role;
        }

        return $tag === $role;
    }

    private function anchorsMatch(array $anchors, array $element): bool
    {
        $parts = [];
        foreach (['text', 'label', 'name', 'placeholder', 'aria_label', 'aria-label', 'title', 'value'] as $key) {
            $value = $element[$key] ?? null;
            if (\is_scalar($value)) {
                $parts[] = (string)$value;
            }
        }
        $haystack = \implode(' ', $parts);
        if ($haystack === '') {
            return false;
        }

        foreach ($anchors as $anchor) {
            if ($this->contains($haystack, $anchor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function elements(array $snapshot): array
    {
        foreach (['elements', 'interactive', 'nodes', 'controls'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (!\is_array($value)) {
                continue;
            }
            $elements = [];
            foreach ($value as $item) {
                if (\is_array($item)) {
                    $elements[] = $item;
                }
            }
            if ($elements !== []) {
                return $elements;
            }
        }

        return [];
    }

    private function safeHandle(array $element): int
    {
        $handle = $element['handle'] ?? $element['ref'] ?? $element['id'] ?? $element['handle_id'] ?? '';
        if (!\is_scalar($handle)) {
            return 0;
        }
        $handle = \trim((string)$handle);
        if ($handle === '' || \strlen($handle) > 18 || !\ctype_digit($handle)) {
            return 0;
        }
        $value = (int)$handle;

        return $value > 0 ? $value : 0;
    }

    private function elementValue(array $element): string
    {
        $value = $element['value'] ?? '';

        return \is_scalar($value) ? \trim((string)$value) : '';
    }

    private function payload(array $job): array
    {
        $payload = $job['payload'] ?? null;
        if (\is_array($payload)) {
            return $payload;
        }

        $payloadJson = $job['payload_json'] ?? null;
        if (\is_string($payloadJson) && $payloadJson !== '') {
            $decoded = \json_decode($payloadJson, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $job;
    }

    private function fieldValue(array $job, string $key): string
    {
        $payload = $this->payload($job);
        $key = \trim($key);
        if ($key === '') {
            return '';
        }

        $value = null;
        if (isset($payload['fields']) && \is_array($payload['fields']) && \array_key_exists($key, $payload['fields'])) {
            $value = $payload['fields'][$key];
        } elseif (\array_key_exists($key, $payload)) {
            $value = $payload[$key];
        } else {
            $aliases = [
                'body' => ['text', 'caption', 'body', 'content', 'message'],
                'text' => ['text', 'caption', 'body', 'content', 'message'],
                'caption' => ['caption', 'text', 'body', 'content', 'message'],
                'content' => ['content', 'text', 'caption', 'body', 'message'],
                'message' => ['message', 'text', 'caption', 'body', 'content'],
                'title' => ['title', 'subject', 'headline'],
                'subject' => ['subject', 'title', 'headline'],
                'url' => ['url', 'link'],
                'link' => ['link', 'url'],
            ];
            foreach ($aliases[\strtolower($key)] ?? [] as $alias) {
                if (\array_key_exists($alias, $payload)) {
                    $value = $payload[$alias];
                    break;
                }
            }
        }

        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (\is_scalar($item)) {
                    $parts[] = (string)$item;
                }
            }
            $value = \implode(' ', $parts);
        }
        if (!\is_scalar($value)) {
            return '';
        }

        return \str_replace("\0", '', \trim((string)$value));
    }

    /**
     * @return list<array{ref:string,mime:string}>
     */
    private function mediaAssets(array $job): array
    {
        $payload = $this->payload($job);
        $raw = $payload['assets'] ?? $payload['media'] ?? $payload['files'] ?? [];
        if (\is_string($raw) || \is_int($raw)) {
            $raw = [$raw];
        }
        if (!\is_array($raw)) {
            return [];
        }

        $assets = [];
        foreach ($raw as $item) {
            if (\is_string($item) || \is_int($item)) {
                $ref = \trim((string)$item);
                if ($ref !== '') {
                    $path = \parse_url($ref, \PHP_URL_PATH);
                    $name = \basename(\is_string($path) && $path !== '' ? $path : $ref);
                    $assets[] = [
                        'ref' => $ref,
                        'mime' => '',
                        'asset_id' => 0,
                        'checksum' => '',
                        'name' => $name !== '' ? $name : 'asset.bin',
                    ];
                }
                continue;
            }
            if (!\is_array($item)) {
                continue;
            }
            $assetId = (int)($item['asset_id'] ?? $item['id'] ?? 0);
            $ref = $item['path'] ?? $item['ref'] ?? $item['asset'] ?? $item['filename'] ?? $item['url'] ?? ($assetId > 0 ? (string)$assetId : '');
            if (!\is_scalar($ref)) {
                continue;
            }
            $ref = \trim((string)$ref);
            if ($ref === '') {
                continue;
            }
            $mime = $item['mime'] ?? $item['type'] ?? $item['content_type'] ?? '';
            $checksum = $item['checksum'] ?? '';
            $name = $item['name'] ?? $item['filename'] ?? '';
            if (!\is_scalar($name) || \trim((string)$name) === '') {
                $path = \parse_url($ref, \PHP_URL_PATH);
                $name = \basename(\is_string($path) && $path !== '' ? $path : $ref);
            }
            $assets[] = [
                'ref' => $ref,
                'mime' => \is_scalar($mime) ? \strtolower(\trim((string)$mime)) : '',
                'asset_id' => $assetId,
                'checksum' => \is_scalar($checksum) ? \trim((string)$checksum) : '',
                'name' => \trim((string)$name) !== '' ? \trim((string)$name) : 'asset.bin',
            ];
        }

        return $assets;
    }

    private function mediaDecision(array $job, array $snapshot, array $recipe, int $stepNo): ?array
    {
        $assets = $this->mediaAssets($job);
        if ($assets === []) {
            return null;
        }

        $media = $recipe['media'] ?? null;
        if (!\is_array($media) || $media === []) {
            return null;
        }
        $max = (int)($media['max'] ?? 0);
        if ($max > 0 && \count($assets) > $max) {
            return $this->giveUp('media_limit');
        }
        $accept = \array_map('strtolower', $this->stringList($media['accept'] ?? []));
        foreach ($assets as $asset) {
            if ($accept !== [] && $asset['mime'] !== '' && !$this->mimeAllowed($asset['mime'], $accept)) {
                return $this->giveUp('media_not_allowed');
            }
        }

        $uploaded = $this->stringList($snapshot['uploaded'] ?? $snapshot['uploaded_assets'] ?? []);
        $visibleText = $this->visibleText($snapshot);
        $pending = null;
        foreach ($assets as $asset) {
            $name = \basename((string)\parse_url($asset['ref'], \PHP_URL_PATH));
            if ($name === '') {
                $name = $asset['ref'];
            }
            $isUploaded = false;
            foreach ($uploaded as $marker) {
                if ($marker !== '' && (\str_contains($marker, $name) || \str_contains($name, $marker))) {
                    $isUploaded = true;
                    break;
                }
            }
            if (!$isUploaded && $name !== '' && $this->contains($visibleText, $name)) {
                $isUploaded = true;
            }
            if (!$isUploaded) {
                $pending = $asset;
                break;
            }
        }
        if ($pending === null) {
            return null;
        }

        $uploadRole = \strtolower(\trim((string)($media['upload_role'] ?? 'file')));
        $element = $this->findElement($snapshot, $uploadRole !== '' ? $uploadRole : 'file', []);
        $handle = $element !== null ? $this->safeHandle($element) : 0;
        if ($handle < 1) {
            return $stepNo <= 10
                ? $this->action('scroll', ['direction' => 'down'])
                : $this->giveUp('anchor_not_found');
        }

        $assetRef = $pending['asset_id'] > 0
            ? (string)$pending['asset_id']
            : (string)$pending['checksum'];

        return $this->action('upload', [
            'handle' => $handle,
            'asset' => $pending['ref'],
            'name' => $pending['name'],
            'ref' => $assetRef,
        ]);
    }

    private function mimeAllowed(string $mime, array $accept): bool
    {
        $mime = \strtolower(\trim($mime));
        if ($mime === '') {
            return true;
        }
        foreach ($accept as $pattern) {
            $pattern = \strtolower(\trim($pattern));
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $mime) {
                return true;
            }
            if (\str_ends_with($pattern, '/*') && \str_starts_with($mime, \substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    private function findSubmit(array $snapshot, array $recipe): int
    {
        $submit = $recipe['submit'] ?? null;
        if (\is_string($submit)) {
            $submit = ['anchor' => [$submit]];
        }
        if (\is_array($submit)) {
            $role = \strtolower(\trim((string)($submit['role'] ?? 'button')));
            $anchors = $this->stringList($submit['anchor'] ?? $submit['anchors'] ?? []);
            $testids = \array_map('strtolower', $this->stringList($submit['testid'] ?? $submit['testids'] ?? []));
            if ($testids !== []) {
                foreach ($this->elements($snapshot) as $candidate) {
                    $testid = \strtolower(\trim((string)($candidate['testid'] ?? $candidate['data_testid'] ?? '')));
                    if ($testid !== '' && \in_array($testid, $testids, true)) {
                        $handle = $this->safeHandle($candidate);
                        if ($handle > 0) {
                            return $handle;
                        }
                    }
                }
            }
            $element = $this->findElement($snapshot, $role, $anchors);
            $handle = $element !== null ? $this->safeHandle($element) : 0;
            if ($handle > 0) {
                return $handle;
            }
        }

        foreach ($this->fieldDefinitions($recipe) as $field) {
            if (!$this->isButtonRole($field['role'])) {
                continue;
            }
            $element = $this->findElement($snapshot, $field['role'], $field['anchor']);
            $handle = $element !== null ? $this->safeHandle($element) : 0;
            if ($handle > 0) {
                return $handle;
            }
        }

        return 0;
    }

    private function verifyMatches(mixed $verify, array $snapshot): bool
    {
        if (!\is_array($verify)) {
            return false;
        }

        $urlNeedles = $this->stringList($verify['url_matches'] ?? []);
        $textNeedles = $this->stringList($verify['text_any'] ?? []);
        if ($urlNeedles === [] && $textNeedles === []) {
            return false;
        }

        // URL 是发布成功的权威信号：url_matches 命中即成功，
        // 不再强制要求 toast 文案（真实平台 toast 可能渲染慢/被替换，强绑会回 compose 重复发帖）。
        if ($urlNeedles !== []) {
            $currentUrl = $this->currentUrl($snapshot);
            foreach ($urlNeedles as $needle) {
                if ($this->contains($currentUrl, $needle)) {
                    return true;
                }
            }

            return false;
        }

        $visibleText = $this->visibleText($snapshot);
        foreach ($textNeedles as $needle) {
            if ($this->contains($visibleText, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    private function verifyExpectation(mixed $verify): string
    {
        if (!\is_array($verify)) {
            return '';
        }

        $parts = [];
        $urlNeedles = $this->stringList($verify['url_matches'] ?? []);
        $textNeedles = $this->stringList($verify['text_any'] ?? []);
        if ($urlNeedles !== []) {
            $parts[] = 'url:' . \implode('|', $urlNeedles);
        }
        if ($textNeedles !== []) {
            $parts[] = 'text:' . \implode('|', $textNeedles);
        }

        return \implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            $value = \trim((string)$value);

            return $value === '' ? [] : [$value];
        }
        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (\is_scalar($item)) {
                $item = \trim((string)$item);
                if ($item !== '') {
                    $list[] = $item;
                }
            }
        }

        return $list;
    }

    private function contains(string $haystack, string $needle): bool
    {
        if ($haystack === '' || $needle === '') {
            return false;
        }

        return \stripos($haystack, $needle) !== false;
    }

    private function action(string $action, array $args = [], bool $done = false, string $error = ''): array
    {
        if (!\in_array($action, self::ALLOWED_ACTIONS, true)) {
            $action = 'give_up';
            $args = [];
            $done = false;
            $error = 'action_not_allowed';
        }

        return [
            'action' => $action,
            'args' => $args,
            'done' => $done,
            'error' => $error,
        ];
    }

    private function giveUp(string $error): array
    {
        $error = \strtolower(\trim($error));
        $error = \preg_replace('/[^a-z0-9_.:-]+/', '_', $error) ?? '';
        $error = \trim($error, '_.:-');
        if ($error === '') {
            $error = 'give_up';
        }

        return $this->action('give_up', [], false, \substr($error, 0, 64));
    }
}
