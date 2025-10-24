<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/4 23:16:51
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\I18n\Model\I18n;
use Weline\I18n\Model\Locals;

class I18nLocalsUpgrade implements \Weline\Framework\Event\ObserverInterface
{
    private Locals $locals;
    private I18n $i18n;

    function __construct(
        Locals $locals,
        I18n   $i18n
    )
    {
        $this->locals = $locals;
        $this->i18n = $i18n;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        $locals = $this->i18n->getLocalesWithFlags(0, 22);
        foreach ($locals as $local_code => $local) {
            $localLocals = $this->i18n->getLocalesWithFlagsDisplaySelf($local_code, 0, 22, true);
            foreach ($localLocals as $self_local_code => $localLocal) {
                $localData = [
                    'code' => $self_local_code,
                    'target_code' => $local_code,
                    'name' => $localLocal['name'],
                    'flag' => $localLocal['flag'],
                    'is_install' => 1,
                    'is_active' => 1,
                ];
                # 查询
                $hasLocal = $this->locals->reset()->where('code', $self_local_code)
                    ->where('target_code', $local_code)->find()->fetch();
                if ($hasLocal->getId()) {
                    # 更新
                    $this->locals->reset()->where('code', $self_local_code)
                        ->where('target_code', $local_code)
                        ->update($localData)
                        ->fetch();
                } else {
                    $this->locals->reset()->insert($localData, '', 'code,target_code')->fetch();
                }
            }
        }
    }
}