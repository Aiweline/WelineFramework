<?php

declare(strict_types=1);

namespace Weline\I18n\Controller\Frontend\Word;

/**
 * Legacy online page-collector endpoint — fail-closed.
 * Word collection runs via scheduled tasks only.
 *
 * @Extra type=fpc enabled=false
 */
class Collect extends \Weline\Framework\App\Controller\FrontendController
{
    public function post()
    {
        return $this->fetchJson($this->error((string)__('在线页收集已移除，请使用定时任务收集词汇')));
    }

    public function get()
    {
        return $this->fetchJson($this->error((string)__('在线页收集已移除，请使用定时任务收集词汇')));
    }
}
