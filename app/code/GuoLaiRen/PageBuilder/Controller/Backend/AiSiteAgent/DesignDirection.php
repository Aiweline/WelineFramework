<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend\AiSiteAgent;

use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class DesignDirection extends BackendController
{
    public function list(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->json(['success' => false, 'message' => 'Admin user is required.'], 401);
        }

        try {
            $includeDisabled = $this->truthy($this->bodyValue('include_disabled', $this->request->getParam('include_disabled', '0')));
            $items = \array_values($this->service()->listDirections($adminId, $includeDisabled));

            return $this->json([
                'success' => true,
                'items' => $items,
                'builtin_code' => DesignDirectionService::BUILTIN_CARD_GAME_CODE,
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function save(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->json(['success' => false, 'message' => 'Admin user is required.'], 401);
        }

        try {
            $payload = $this->bodyParams();
            $data = [
                'code' => $this->bodyValue('code', $payload['code'] ?? ''),
                'name' => $this->bodyValue('name', $payload['name'] ?? ''),
                'description' => $this->bodyValue('description', $payload['description'] ?? ''),
                'status' => $this->bodyValue('status', $payload['status'] ?? 'active'),
                'cta_style' => $this->bodyValue('cta_style', $payload['cta_style'] ?? ''),
                'supplemental_prompt' => $this->bodyValue('supplemental_prompt', $payload['supplemental_prompt'] ?? ''),
            ];

            foreach ([
                'industry_tags',
                'match_keywords',
                'visual_keywords',
                'color_system',
                'layout_patterns',
                'image_strategy',
                'forbidden_patterns',
                'block_rules',
                'qa_rules',
                'example_refs',
            ] as $field) {
                $data[$field] = $this->bodyValue($field, $payload[$field] ?? []);
            }

            return $this->json([
                'success' => true,
                'item' => $this->service()->saveCustom($data, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    public function disable(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->json(['success' => false, 'message' => 'Admin user is required.'], 401);
        }

        $code = \trim((string)$this->bodyValue('code', $this->request->getParam('code', '')));
        if ($code === '') {
            return $this->json(['success' => false, 'message' => 'Direction code is required.'], 400);
        }

        try {
            return $this->json([
                'success' => true,
                'item' => $this->service()->disableCustom($code, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    public function cloneBuiltin(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->json(['success' => false, 'message' => 'Admin user is required.'], 401);
        }

        $code = \trim((string)$this->bodyValue('code', $this->request->getParam('code', DesignDirectionService::BUILTIN_CARD_GAME_CODE)));
        if ($code === '') {
            return $this->json(['success' => false, 'message' => 'Direction code is required.'], 400);
        }

        try {
            return $this->json([
                'success' => true,
                'item' => $this->service()->cloneBuiltin($code, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    public function match(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->json(['success' => false, 'message' => 'Admin user is required.'], 401);
        }

        try {
            $title = \trim((string)$this->bodyValue('site_title', $this->bodyValue('title', $this->request->getParam('site_title', ''))));
            $brief = \trim((string)$this->bodyValue('brief_description', $this->bodyValue('brief', $this->request->getParam('brief_description', ''))));
            $match = $this->service()->matchDirection($title, $brief, $adminId);
            $item = \is_array($match['item'] ?? null) ? $match['item'] : null;

            return $this->json([
                'success' => true,
                'matched' => !empty($match['matched']),
                'item' => $item,
                'direction' => $item,
                'score' => (int)($match['score'] ?? 0),
                'reason' => (string)($match['reason'] ?? ''),
                'matched_keywords' => $match['matched_keywords'] ?? [],
            ]);
        } catch (\Throwable $throwable) {
            return $this->json([
                'success' => false,
                'message' => $throwable->getMessage(),
            ], 500);
        }
    }

    private function service(): DesignDirectionService
    {
        return ObjectManager::getInstance(DesignDirectionService::class);
    }

    private function adminId(): int
    {
        return \max(0, (int)$this->getLoginUserId());
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyParams(): array
    {
        $params = $this->request->getBodyParams(true);
        if (\is_array($params)) {
            return $params;
        }

        return [];
    }

    private function bodyValue(string $key, mixed $default = null): mixed
    {
        $value = $this->request->getPost($key, null);
        if ($value !== null) {
            return $value;
        }

        $body = $this->bodyParams();
        if (\array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $default;
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function json(array $payload, int $statusCode = 200): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        return \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
