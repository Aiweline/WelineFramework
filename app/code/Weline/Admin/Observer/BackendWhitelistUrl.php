<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Observer;

use Weline\Acl\Api\Resource\WhitelistServiceInterface;
use Weline\Framework\App\Debug;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Url;

class BackendWhitelistUrl implements \Weline\Framework\Event\ObserverInterface
{
    public const white_urls = [
        ['path' => 'admin/login/post'],
        ['path' => 'admin/login/verification-code'],
        ['path' => 'admin/login/verificationcode'],
        ['path' => 'admin/login/index'],
        ['path' => 'admin/login'],
        ['path' => 'admin/login/logout'],
    ];
    private Url $url;
    private WhitelistServiceInterface $whitelistService;

    public function __construct(
        Url $url,
        WhitelistServiceInterface $whitelistService
    )
    {
        $this->url = $url;
        $this->whitelistService = $whitelistService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $whiteUrls = array_column(self::white_urls, 'path');
        $this->whitelistService->upsertPaths($whiteUrls);
        $data = $event->getData('data');
        if ($data) {
            $data->setData('whitelist_url', $whiteUrls);
        }
    }
}
