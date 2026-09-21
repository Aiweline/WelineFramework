<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Backend\Api\Notification\ChannelAdapterInterface;
use Weline\Framework\Contract\InterfaceSourceContractProbe;
use Weline\Framework\Manager\ObjectManager;

/**
 * 渠道适配器收集服务
 *
 * 从 extends 注册表获取 ChannelAdapterInterface 实现并实例化。
 * 加载前先做源码级接口合规探测，避免缺方法类 autoload Fatal 打挂 Worker。
 */
class ChannelAdapterCollector
{
    private static ?array $adapters = null;

    /**
     * @var list<array{class:string,missing:list<string>,file:?string,note:string}>
     */
    private static array $skipped = [];

    /**
     * @return ChannelAdapterInterface[]
     */
    public function getAdapters(): array
    {
        if (self::$adapters !== null) {
            return self::$adapters;
        }

        self::$skipped = [];

        $extendsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'extends.php';
        if (!is_file($extendsFile)) {
            return self::$adapters = [];
        }

        $config = include $extendsFile;
        $implClasses = $config[ChannelAdapterInterface::class] ?? [];

        if (!is_array($implClasses)) {
            return self::$adapters = [];
        }

        $adapters = [];
        foreach ($implClasses as $implClass) {
            if (!is_string($implClass) || $implClass === '') {
                continue;
            }

            $probe = InterfaceSourceContractProbe::check(ChannelAdapterInterface::class, $implClass);
            if (!$probe['ok']) {
                self::$skipped[] = [
                    'class' => $implClass,
                    'missing' => $probe['missing'],
                    'file' => $probe['file'],
                    'note' => $probe['note'],
                ];
                $missing = implode(', ', $probe['missing']);
                if (function_exists('w_log_error')) {
                    w_log_error(
                        "ChannelAdapter skipped (interface contract): {$implClass}; missing=[{$missing}]; note={$probe['note']}",
                        ['file' => $probe['file']],
                        'notification'
                    );
                }
                continue;
            }

            if (!class_exists($implClass)) {
                self::$skipped[] = [
                    'class' => $implClass,
                    'missing' => [],
                    'file' => $probe['file'],
                    'note' => 'class_not_found',
                ];
                continue;
            }

            $adapter = ObjectManager::getInstance($implClass);
            if ($adapter instanceof ChannelAdapterInterface) {
                $adapters[] = $adapter;
            }
        }

        return self::$adapters = $adapters;
    }

    public function getAdapterByCode(string $channelCode): ?ChannelAdapterInterface
    {
        foreach ($this->getAdapters() as $adapter) {
            if ($adapter->getChannelCode() === $channelCode) {
                return $adapter;
            }
        }
        return null;
    }

    /**
     * @return list<array{class:string,missing:list<string>,file:?string,note:string}>
     */
    public function getSkippedAdapters(): array
    {
        $this->getAdapters();
        return self::$skipped;
    }

    /**
     * 校验 extends 注册表中全部 ChannelAdapter（不实例化失败类）。
     *
     * @return list<array{class:string,ok:bool,missing:list<string>,file:?string,note:string}>
     */
    public function validateRegisteredContracts(): array
    {
        $extendsFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'extends.php';
        if (!is_file($extendsFile)) {
            return [];
        }

        $config = include $extendsFile;
        $implClasses = $config[ChannelAdapterInterface::class] ?? [];
        if (!is_array($implClasses)) {
            return [];
        }

        $results = [];
        foreach ($implClasses as $implClass) {
            if (!is_string($implClass) || $implClass === '') {
                continue;
            }
            $probe = InterfaceSourceContractProbe::check(ChannelAdapterInterface::class, $implClass);
            $results[] = [
                'class' => $implClass,
                'ok' => $probe['ok'],
                'missing' => $probe['missing'],
                'file' => $probe['file'],
                'note' => $probe['note'],
            ];
        }

        return $results;
    }

    public static function resetCache(): void
    {
        self::$adapters = null;
        self::$skipped = [];
    }
}
