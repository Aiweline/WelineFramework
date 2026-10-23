<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Site;

use Weline\AiAcquisition\Model\Lead;
use Weline\AiAcquisition\Model\LeadRun;
use Weline\Framework\Manager\ObjectManager;
use Weline\Ops\Service\OpsPersonaService;
use Weline\Ops\Service\OpsWebsiteContextReader;
use Weline\Websites\Model\Website;

/**
 * 获客的网站画像上下文。
 *
 * 获客不是“无边界的网上找客户”：先选站点，再把站点身份、运营画像、类目/商品、
 * 价格带、语种市场与证据缺口注入智能体提示词，避免找回来的线索与站点不匹配。
 */
class WebsiteProfileService
{
    /**
     * 后台网站下拉数据。
     *
     * @return list<array{website_id:int,name:string,url:string,code:string}>
     */
    public function listWebsites(): array
    {
        $rows = [];
        if (\class_exists(Website::class)) {
            try {
                $items = ObjectManager::getInstance(Website::class)
                    ->clearData()
                    ->reset()
                    ->order(Website::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetchArray();
                $rows = \is_array($items) ? $items : [];
            } catch (\Throwable) {
                $rows = [];
            }
        }

        if ($rows === [] && \function_exists('w_query')) {
            try {
                $queried = \w_query('websites', 'getWebsiteList', [], 'backend');
                if (\is_array($queried)) {
                    $rows = $queried;
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        $websites = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
            $code = \trim((string)($row['code'] ?? $row['website_code'] ?? ''));
            if ($websiteId < 0 && $code === '') {
                continue;
            }
            $name = \trim((string)($row['name'] ?? $code));
            $websites[$websiteId] = [
                'website_id' => $websiteId,
                'name' => $name !== '' ? $name : ('#' . $websiteId),
                'url' => \trim((string)($row['url'] ?? '')),
                'code' => $code,
            ];
        }

        return \array_values($websites);
    }

    /**
     * 供运行页预览 + 提示词共用的画像数据。
     *
     * @return array<string,mixed>
     */
    public function profile(int $websiteId): array
    {
        $websiteId = \max(0, $websiteId);
        $profile = [
            'website_id' => $websiteId,
            'name' => '',
            'url' => '',
            'description' => '',
            'persona_summary' => '',
            'audience' => [],
            'pillars' => [],
            'tone' => [],
            'categories' => [],
            'catalog_sample' => [],
            'price_band' => null,
            'locales' => [],
            'evidence_gaps' => [],
            'available' => false,
            'lead_count' => 0,
            'run_count' => 0,
        ];

        if ($websiteId < 0) {
            return $profile;
        }

        $context = [];
        $persona = null;
        if (\class_exists(OpsWebsiteContextReader::class)) {
            try {
                $context = ObjectManager::getInstance(OpsWebsiteContextReader::class)->read($websiteId);
            } catch (\Throwable) {
                $context = [];
            }
        }
        if (\class_exists(OpsPersonaService::class)) {
            try {
                $persona = ObjectManager::getInstance(OpsPersonaService::class)->getView($websiteId);
            } catch (\Throwable) {
                $persona = null;
            }
        }

        $name = \trim((string)($context['name'] ?? ''));
        $description = \trim((string)($context['description'] ?? ''));
        $url = '';
        if (\class_exists(Website::class)) {
            try {
                $website = ObjectManager::getInstance(Website::class);
                $website->clearData()->reset()->load($websiteId);
                if ((int)$website->getId() > 0) {
                    $name = $name !== '' ? $name : \trim((string)$website->getData(Website::schema_fields_NAME));
                    $description = $description !== '' ? $description : \trim((string)$website->getData(Website::schema_fields_DESCRIPTION));
                    $url = \trim((string)$website->getData(Website::schema_fields_URL));
                }
            } catch (\Throwable) {
                // 画像仍可只依赖 Ops 上下文
            }
        }

        $profile['name'] = $name;
        $profile['url'] = $url;
        $profile['description'] = $description;
        $profile['available'] = (bool)($context['available'] ?? false) || $name !== '';
        if (\is_array($persona)) {
            $profile['persona_summary'] = \trim((string)($persona['summary'] ?? ''));
            $profile['audience'] = \array_values((array)($persona['audience'] ?? []));
            $profile['pillars'] = \array_values((array)($persona['pillars'] ?? []));
            $profile['tone'] = \array_values((array)($persona['tone'] ?? []));
        }

        foreach ((array)($context['categories'] ?? []) as $category) {
            if (\is_array($category) && \trim((string)($category['name'] ?? '')) !== '') {
                $profile['categories'][] = \trim((string)$category['name']);
            }
        }
        foreach ((array)($context['catalog_sample'] ?? []) as $product) {
            if (\is_array($product) && \trim((string)($product['name'] ?? '')) !== '') {
                $profile['catalog_sample'][] = \trim((string)$product['name']);
            }
        }
        $profile['categories'] = \array_slice(\array_values(\array_unique($profile['categories'])), 0, 12);
        $profile['catalog_sample'] = \array_slice(\array_values(\array_unique($profile['catalog_sample'])), 0, 8);
        $profile['price_band'] = \is_array($context['price_band'] ?? null) ? $context['price_band'] : null;
        $profile['locales'] = \array_values((array)($context['locales'] ?? []));
        $profile['evidence_gaps'] = \array_values((array)($context['evidence_gaps'] ?? []));

        try {
            $profile['lead_count'] = (int)ObjectManager::getInstance(Lead::class)
                ->reset()
                ->clearQuery()
                ->where(Lead::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
            $profile['run_count'] = (int)ObjectManager::getInstance(LeadRun::class)
                ->reset()
                ->clearQuery()
                ->where(LeadRun::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
        } catch (\Throwable) {
            // 表未安装时预览仍可用
        }

        return $profile;
    }


    /**
     * 运行页预览用的轻量画像：只读网站身份 + 运营画像，不扫类目/商品/政策。
     *
     * @return array<string,mixed>
     */
    public function preview(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        $preview = [
            'website_id' => $websiteId,
            'name' => '',
            'url' => '',
            'description' => '',
            'persona_summary' => '',
            'audience' => [],
            'pillars' => [],
            'tone' => [],
            'categories' => [],
            'locales' => [],
            'evidence_gaps' => [],
            'available' => false,
            'lead_count' => 0,
            'run_count' => 0,
        ];

        if ($websiteId < 0) {
            return $preview;
        }

        if (class_exists(Website::class)) {
            try {
                $website = ObjectManager::getInstance(Website::class);
                $website->clearData()->reset()->load($websiteId);
                if ((int)$website->getId() > 0 || $websiteId === 0) {
                    $preview['name'] = trim((string)$website->getData(Website::schema_fields_NAME));
                    $preview['description'] = trim((string)$website->getData(Website::schema_fields_DESCRIPTION));
                    $preview['url'] = trim((string)$website->getData(Website::schema_fields_URL));
                    $preview['available'] = true;
                }
            } catch (\Throwable) {
                // 继续尝试 Ops 画像
            }
        }

        if (class_exists(OpsPersonaService::class)) {
            try {
                $persona = ObjectManager::getInstance(OpsPersonaService::class)->getView($websiteId);
                if (is_array($persona)) {
                    $preview['persona_summary'] = trim((string)($persona['summary'] ?? ''));
                    $preview['audience'] = array_values((array)($persona['audience'] ?? []));
                    $preview['pillars'] = array_values((array)($persona['pillars'] ?? []));
                    $preview['tone'] = array_values((array)($persona['tone'] ?? []));
                    $preview['available'] = true;
                }
            } catch (\Throwable) {
                // 预览不因画像失败而报错
            }
        }

        try {
            $preview['lead_count'] = (int)ObjectManager::getInstance(Lead::class)
                ->reset()
                ->clearQuery()
                ->where(Lead::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
            $preview['run_count'] = (int)ObjectManager::getInstance(LeadRun::class)
                ->reset()
                ->clearQuery()
                ->where(LeadRun::schema_fields_WEBSITE_ID, $websiteId)
                ->total();
        } catch (\Throwable) {
            // 表未安装时预览仍可用
        }

        return $preview;
    }

    /**
     * 拼进智能体 system prompt 的站点画像文本。
     */
    public function buildPromptContext(int $websiteId): string
    {
        $profile = $this->profile($websiteId);
        if (!$profile['available'] && $profile['name'] === '' && $profile['description'] === '') {
            return '';
        }

        $lines = ['【站点画像 · 获客必须以此为准】'];
        $lines[] = '网站：' . ($profile['name'] !== '' ? $profile['name'] : ('#' . $websiteId))
            . ($profile['url'] !== '' ? '（' . $profile['url'] . '）' : '');
        if ($profile['description'] !== '') {
            $lines[] = '网站描述：' . $this->limit($profile['description'], 600);
        }
        if ($profile['persona_summary'] !== '') {
            $lines[] = '运营画像：' . $this->limit($profile['persona_summary'], 600);
        }
        if ($profile['audience'] !== []) {
            $lines[] = '目标受众：' . $this->join($profile['audience'], 12);
        }
        if ($profile['pillars'] !== []) {
            $lines[] = '内容/产品支柱：' . $this->join($profile['pillars'], 12);
        }
        if ($profile['tone'] !== []) {
            $lines[] = '品牌语气：' . $this->join($profile['tone'], 8);
        }
        if ($profile['categories'] !== []) {
            $lines[] = '核心类目：' . $this->join($profile['categories'], 12);
        }
        if ($profile['catalog_sample'] !== []) {
            $lines[] = '商品抽样：' . $this->join($profile['catalog_sample'], 8);
        }
        if (\is_array($profile['price_band'])) {
            $currency = (string)($profile['price_band']['currency'] ?? '');
            $min = $profile['price_band']['min'] ?? null;
            $max = $profile['price_band']['max'] ?? null;
            if ($min !== null || $max !== null) {
                $lines[] = '价格带：' . \trim($currency . ' ' . (string)$min . '–' . (string)$max);
            }
        }
        if ($profile['locales'] !== []) {
            $lines[] = '语种市场：' . $this->join($profile['locales'], 12);
        }
        if ($profile['evidence_gaps'] !== []) {
            $lines[] = '画像证据缺口：' . $this->join($profile['evidence_gaps'], 12) . '（缺失信息不得编造）';
        }
        $lines[] = '获客要求：只寻找与上述站点产品、市场、价格带匹配的买家；线索必须能回溯到公开来源并附证据摘录。';

        return \implode("\n", $lines);
    }

    /** @param array<int,mixed> $values */
    private function join(array $values, int $limit): string
    {
        $parts = [];
        foreach ($values as $value) {
            if (\is_scalar($value)) {
                $text = \trim((string)$value);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            if (\count($parts) >= $limit) {
                break;
            }
        }

        return \implode('、', $parts);
    }

    private function limit(string $text, int $length): string
    {
        return \function_exists('mb_substr') ? \mb_substr($text, 0, $length) : \substr($text, 0, $length);
    }
}
