<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Observer;

use Weline\Acl\Model\WhiteAclSource;
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
    ];
    private Url $url;
    /**
     * @var \Weline\Acl\Model\WhiteAclSource
     */
    private WhiteAclSource $whiteAclSource;

    public function __construct(
        Url            $url,
        WhiteAclSource $whiteAclSource
    ) {
        $this->url = $url;
        $this->whiteAclSource = $whiteAclSource;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        $white_acl_sources = self::white_urls;
        $this->whiteAclSource->insert($white_acl_sources, 'path')->fetch();
        $data = $event->getData('data');
        if ($data) {
            $white_urls = [];
            foreach (self::white_urls as $item) {
                $white_urls[] = $item['path'];
            }
            $data->setData('whitelist_url', $white_urls);
        }
    }
}
