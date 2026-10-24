<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Service\Tag;

/**
 * 线索分类标签字典（三组，白名单归一）。
 *
 * - buyer_type 买家类型：批发商/零售商/网店主/网红KOL/活动主办/进口商/写真馆/租赁店/其他
 * - channel    渠道类型：独立站/电商平台/社媒/博客/黄页名录/论坛社区/搜索引擎
 * - influence  影响力层级：头部/腰部/素人/未知
 *
 * 标签在线索 tags JSON 与客户标签表中都以 `group:code` 形式存储，如 `buyer_type:influencer`。
 */
final class TagCatalog
{
 public const GROUP_BUYER_TYPE = 'buyer_type';
 public const GROUP_CHANNEL = 'channel';
 public const GROUP_INFLUENCE = 'influence';

 public const GROUPS = [self::GROUP_BUYER_TYPE, self::GROUP_CHANNEL, self::GROUP_INFLUENCE];

 public const LABELS = [
 self::GROUP_BUYER_TYPE => [
 'wholesaler' => '批发商',
 'retailer' => '零售商',
 'shop_owner' => '网店主',
 'influencer' => '网红',
 'kol' => 'KOL',
 'event_organizer' => '活动主办',
 'importer' => '进口商',
 'photostudio' => '写真馆',
 'rental' => '租赁店',
 'other' => '其他',
 ],
 self::GROUP_CHANNEL => [
 'independent_site' => '独立站',
 'marketplace' => '电商平台',
 'social_media' => '社媒',
 'blog' => '博客',
 'directory' => '黄页名录',
 'forum' => '论坛社区',
 'search' => '搜索引擎',
 ],
 self::GROUP_INFLUENCE => [
 'mega' => '头部',
 'mid_tier' => '腰部',
 'nano' => '素人',
 'unknown' => '未知',
 ],
 ];

 /**
 * 归一输入标签（接受 `buyer_type:influencer`、`influencer` 或数组）→ 限定在字典内的 tag 列表。
 *
 * @param mixed $tags
 * @return list<string>
 */
 public static function normalize(mixed $tags): array
 {
 if (is_string($tags)) {
 $decoded = json_decode($tags, true);
 $tags = is_array($decoded) ? $decoded : [$tags];
 }
 if (!is_array($tags)) {
 return [];
 }

 $result = [];
 foreach ($tags as $key => $value) {
 $tag = is_string($key) && is_string($value) && $value !== ''
 ? $key . ':' . $value
 : (string)$value;
 $tag = self::normalizeOne($tag);
 if ($tag !== '' && !in_array($tag, $result, true)) {
 $result[] = $tag;
 }
 }

 return $result;
 }

 /**
 * 单标签归一：缺组前缀时按字典反查补齐；字典外丢弃。
 */
 public static function normalizeOne(string $tag): string
 {
 $tag = trim(str_replace(['：', '｜', ' '], [':', ':', '_'], $tag), ": \t\n\r");
 if ($tag === '') {
 return '';
 }
 if (preg_match('/^(' . implode('|', self::GROUPS) . '):([a-z0-9_]+)$/i', $tag, $m) === 1) {
 $group = strtolower($m[1]);
 $code = strtolower($m[2]);
 return isset(self::LABELS[$group][$code]) ? $group . ':' . $code : '';
 }
 // 无组前缀：先按中文名反查，再按 code 反查
 $clean = str_replace(' ', '', $tag);
 foreach (self::LABELS as $group => $codes) {
 $hit = array_search($clean, $codes, true);
 if ($hit !== false) {
 return $group . ':' . $hit;
 }
 }
 $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $tag));
 foreach (self::LABELS as $group => $codes) {
 if (isset($codes[$code])) {
 return $group . ':' . $code;
 }
 }

 return '';
 }

 /**
 * 两个标签集合并集（保持顺序、去重）。
 *
 * @param list<string> $base
 * @param mixed $incoming
 * @return list<string>
 */
 public static function merge(array $base, mixed $incoming): array
 {
 $merged = $base;
 foreach (self::normalize($incoming) as $tag) {
 if (!in_array($tag, $merged, true)) {
 $merged[] = $tag;
 }
 }

 return $merged;
 }

 /**
 * 每组只保留一个标签（后写覆盖先写），避免同一组内标签堆积。
 *
 * @param list<string> $tags
 * @return list<string>
 */
 public static function dedupeByGroup(array $tags): array
 {
 $byGroup = [];
 $rest = [];
 foreach ($tags as $tag) {
 $group = explode(':', $tag)[0];
 if (in_array($group, self::GROUPS, true)) {
 $byGroup[$group] = $tag;
 } else {
 $rest[] = $tag;
 }
 }

 return array_values(array_merge($rest, array_values($byGroup)));
 }

 /**
 * @return array<string,string> group:code => 中文标签
 */
 public static function allLabels(): array
 {
 $out = [];
 foreach (self::LABELS as $group => $codes) {
 foreach ($codes as $code => $label) {
 $out[$group . ':' . $code] = $label;
 }
 }

 return $out;
 }

 public static function label(string $tag): string
 {
 return self::allLabels()[$tag] ?? $tag;
 }
}
