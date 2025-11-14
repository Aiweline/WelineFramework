<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Event\EventRegistry;
use Weline\Framework\Event\EventData;
use Weline\Framework\Manager\ObjectManager;

class Rebuild extends CommandAbstract
{
    /**
     * 重新扫描并生成 generated/events.php
     */
    public function execute(array $args = [], array $data = [])
    {
        try {
            $this->printer->setup(__('开始重建事件注册表...'));

            /** @var EventRegistry $registry */
            $registry = ObjectManager::getInstance(EventRegistry::class);

            $ok = $registry->refresh();
            if ($ok) {
                // 清除 EventData 缓存
                EventData::clearCache();
                
                $this->printer->success(__('✓ 事件注册表已重建完成。'));
                $this->printer->note(__('位置：generated/events.php'));
                
                // 显示统计信息
                $events = $registry->getEvents();
                $dynamicPatterns = $registry->getDynamicPatterns();
                
                // 统计普通事件
                $normalEventsCount = count($events);
                $normalEventsWithDoc = 0;
                $normalEventsWithoutDoc = 0;
                $normalEventsWithoutDocList = [];
                
                foreach ($events as $eventName => $eventInfo) {
                    if ($eventInfo['has_doc'] ?? false) {
                        $normalEventsWithDoc++;
                    } else {
                        $normalEventsWithoutDoc++;
                        $normalEventsWithoutDocList[] = [
                            'event_name' => $eventName,
                            'module' => $eventInfo['module'] ?? '未知模块',
                            'name' => $eventInfo['name'] ?? $eventName,
                            'doc' => $eventInfo['doc'] ?? '',
                        ];
                    }
                }
                
                // 统计动态事件模式
                $dynamicPatternsCount = count($dynamicPatterns);
                $dynamicPatternsWithDoc = 0;
                $dynamicPatternsWithoutDoc = 0;
                $dynamicPatternsWithoutDocList = [];
                
                foreach ($dynamicPatterns as $pattern => $patternInfo) {
                    if ($patternInfo['has_doc'] ?? false) {
                        $dynamicPatternsWithDoc++;
                    } else {
                        $dynamicPatternsWithoutDoc++;
                        $dynamicPatternsWithoutDocList[] = [
                            'event_name' => $pattern,
                            'module' => $patternInfo['module'] ?? '未知模块',
                            'name' => $patternInfo['name'] ?? $pattern,
                            'doc' => $patternInfo['doc'] ?? '',
                        ];
                    }
                }
                
                // 总事件数 = 普通事件数 + 动态事件模式数
                $totalEvents = $normalEventsCount + $dynamicPatternsCount;
                $totalEventsWithDoc = $normalEventsWithDoc + $dynamicPatternsWithDoc;
                $totalEventsWithoutDoc = $normalEventsWithoutDoc + $dynamicPatternsWithoutDoc;
                $allEventsWithoutDocList = array_merge($normalEventsWithoutDocList, $dynamicPatternsWithoutDocList);
                
                $this->printer->note(__('统计信息：'));
                $this->printer->note(__('  - 总事件数：%{count}（普通事件：%{normal}，动态事件模式：%{dynamic}）', [
                    'count' => $totalEvents,
                    'normal' => $normalEventsCount,
                    'dynamic' => $dynamicPatternsCount
                ]));
                $this->printer->note(__('  - 有文档：%{count}', ['count' => $totalEventsWithDoc]));
                if ($totalEventsWithoutDoc > 0) {
                    $this->printer->warning(__('  - 缺少文档：%{count}', ['count' => $totalEventsWithoutDoc]));
                    $this->printer->warning(__('缺少文档的事件列表：'));
                    foreach ($allEventsWithoutDocList as $eventInfo) {
                        $isDynamic = str_contains($eventInfo['event_name'], '{');
                        $typeLabel = $isDynamic ? '[动态事件模式]' : '';
                        $this->printer->warning(sprintf(
                            '    • %s %s (%s) - 模块：%s',
                            $eventInfo['event_name'],
                            $typeLabel,
                            $eventInfo['name'],
                            $eventInfo['module']
                        ));
                        if (!empty($eventInfo['doc'])) {
                            $this->printer->warning(sprintf(
                                '      期望文档：doc/event/%s',
                                $eventInfo['doc']
                            ));
                        } else {
                            $this->printer->warning('      未指定文档文件名');
                        }
                    }
                }
            } else {
                $this->printer->error(__('✖ 写入事件注册表失败。'));
            }
        } catch (\RuntimeException $e) {
            // 事件名冲突错误，显示详细错误信息
            $this->printer->error(__('✖ 事件注册表重建失败：事件名冲突'));
            $this->printer->error($e->getMessage());
            exit(1); // 致命错误，退出程序
        } catch (\Throwable $e) {
            $this->printer->error(__('重建失败：%{1}', [$e->getMessage()]));
            if (DEV) {
                $this->printer->error($e->getTraceAsString());
            }
        }
    }

    public function tip(): string
    {
        return '扫描所有模块事件规约并重建 generated/events.php';
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'event:rebuild',
            '扫描所有模块的 event.php 规约文件并重建事件注册表',
            [
                '--debug' => '显示调试信息（可选）',
            ],
            [
                '执行后会在项目根目录的 generated/events.php 写入全量事件规约信息。',
                '所有事件必须同时具备规约文件 (event.php) 和文档文件 (doc/event/*.md) 才能正常执行。',
            ],
            [
                '直接重建' => 'php bin/w event:rebuild',
            ],
            'php bin/w event:rebuild'
        );
    }
}

