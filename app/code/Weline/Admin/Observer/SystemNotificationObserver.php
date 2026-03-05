<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Observer;

use Weline\Admin\Model\System\SystemNotification;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class SystemNotificationObserver implements ObserverInterface
{
    /**
     * @DESC          # 处理系统消息事件
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     *
     * @param Event $event
     *
     * @return void
     */
    public function execute(Event &$event): void
    {
        // 获取事件数据
        $data = $event->getData('data');
        
        // 获取发送者信息（用于错误消息）
        $sender = $this->getSenderInfo();
        
        // 如果数据为空或不是数组，记录错误消息
        if (empty($data) || !is_array($data)) {
            $this->saveErrorNotification(
                __('系统消息数据格式错误'),
                __(
                    "收到无效的系统消息数据。\n\n发送者：%{sender}\n数据类型：%{type}\n数据内容：%{content}\n\n请检查发送系统消息的代码，确保数据格式正确。",
                    [
                        'sender' => $sender,
                        'type' => gettype($data),
                        'content' => is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]
                )
            );
            return;
        }

        // 验证必需字段
        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';
        
        if (empty($title) || empty($content)) {
            $missingFields = '';
            if (empty($title) && empty($content)) {
                $missingFields = __('title') . ', ' . __('content');
            } elseif (empty($title)) {
                $missingFields = __('title');
            } else {
                $missingFields = __('content');
            }
            
            $this->saveErrorNotification(
                __('系统消息缺少必需字段'),
                __(
                    "收到的系统消息缺少必需字段。\n\n发送者：%{sender}\n缺少字段：%{fields}\n\n请确保消息数据包含 'title' 和 'content' 字段。\n\n当前数据：%{data}",
                    [
                        'sender' => $sender,
                        'fields' => $missingFields,
                        'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    ]
                )
            );
            return;
        }

        try {
            /** @var SystemNotification $notification */
            $notification = ObjectManager::getInstance(SystemNotification::class);
            
            // 检查是否有重复的消息（根据标题和内容）
            $existingNotification = clone $notification;
            $existingNotification->where(SystemNotification::schema_fields_title, $title)
                                ->where(SystemNotification::schema_fields_content, $content)
                                ->find()
                                ->fetch();
            
            // 如果存在重复消息且已读，则重新设置为未读
            if ($existingNotification->getId()) {
                if ($existingNotification->isRead()) {
                    $existingNotification->setIsRead(false)->save();
                }
                return; // 重复消息已处理，不再创建新消息
            }
            
            // 创建新消息
            $notification->clear();
            
            // 设置标题和内容
            $notification->setTitle($title)
                        ->setContent($content);
            
            // 设置是否已读（默认为未读）
            $isRead = isset($data['is_read']) ? (bool)$data['is_read'] : false;
            $notification->setIsRead($isRead);
            
            // 设置头像类型和内容
            // 如果指定了 is_icon，使用图标
            if (isset($data['is_icon']) && $data['is_icon']) {
                $notification->setIsIcon(1)
                            ->setIsImg(0)
                            ->setAvatar($data['avatar'] ?? 'ri-notification-line');
            }
            // 如果指定了 is_img，使用图片
            elseif (isset($data['is_img']) && $data['is_img']) {
                $notification->setIsIcon(0)
                            ->setIsImg(1)
                            ->setAvatar($data['avatar'] ?? 'assets/images/users/avatar-1.jpg');
            }
            // 默认使用图标
            else {
                $notification->setIsIcon(1)
                            ->setIsImg(0)
                            ->setAvatar($data['avatar'] ?? 'ri-notification-line');
            }
            
            // 保存通知
            $notification->save();
        } catch (\Exception $e) {
            // 将错误也写入系统消息，让管理员知道
            $this->saveErrorNotification(
                __('系统消息保存失败'),
                __(
                    "保存系统消息时发生错误。\n\n发送者：%{sender}\n错误信息：%{error}\n\n消息标题：%{title}\n消息内容：%{content}",
                    [
                        'sender' => $sender,
                        'error' => $e->getMessage(),
                        'title' => $title ?? __('未知'),
                        'content' => $content ?? __('未知')
                    ]
                )
            );
        }
    }

    /**
     * @DESC          # 保存错误通知消息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     *
     * @param string $title
     * @param string $content
     *
     * @return void
     */
    private function saveErrorNotification(string $title, string $content): void
    {
        try {
            /** @var SystemNotification $notification */
            $notification = ObjectManager::getInstance(SystemNotification::class);
            
            // 检查是否有重复的错误消息
            $existingNotification = clone $notification;
            $existingNotification->where(SystemNotification::schema_fields_title, $title)
                                ->where(SystemNotification::schema_fields_content, $content)
                                ->find()
                                ->fetch();
            
            // 如果存在重复消息且已读，则重新设置为未读
            if ($existingNotification->getId()) {
                if ($existingNotification->isRead()) {
                    $existingNotification->setIsRead(false)->save();
                }
                return;
            }
            
            // 创建新的错误通知
            $notification->clear();
            $notification->setTitle($title)
                        ->setContent($content)
                        ->setIsRead(false)
                        ->setIsIcon(1)
                        ->setIsImg(0)
                        ->setAvatar('ri-error-warning-line')
                        ->save();
        } catch (\Exception $e) {
            // 如果连错误消息都保存失败，记录到错误日志
            if (defined('DEV') && DEV) {
                w_log_error(__('SystemNotificationObserver Error: Failed to save error notification - %{error}', ['error' => $e->getMessage()]));
            }
        }
    }

    /**
     * @DESC          # 获取发送者信息
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2024/12/19
     * 参数区：
     *
     * @return string
     */
    private function getSenderInfo(): string
    {
        try {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
            foreach ($trace as $index => $frame) {
                // 跳过当前类和框架类
                if (isset($frame['class']) && (
                    str_contains($frame['class'], 'SystemNotificationObserver') ||
                    str_contains($frame['class'], 'Event') ||
                    str_contains($frame['class'], 'EventsManager')
                )) {
                    continue;
                }
                
                // 找到第一个非框架类的调用者
                if (isset($frame['class'])) {
                    $class = $frame['class'];
                    $file = $frame['file'] ?? '';
                    
                    // 尝试从文件路径提取模块名
                    if (preg_match('/app\/code\/([^\/]+)\//', $file, $matches)) {
                        return $matches[1] . '::' . $class;
                    }
                    
                    return $class;
                }
                
                // 如果是函数调用
                if (isset($frame['function']) && isset($frame['file'])) {
                    $file = $frame['file'];
                    if (preg_match('/app\/code\/([^\/]+)\//', $file, $matches)) {
                        return $matches[1] . '::' . $frame['function'];
                    }
                    return $frame['function'];
                }
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        return __('未知来源');
    }
}

