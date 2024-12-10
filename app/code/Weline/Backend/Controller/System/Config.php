<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Controller\System;

use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Manager\ObjectManager;

class Config extends \Weline\Framework\App\Controller\BackendController
{
    public function set()
    {
        $key = $this->request->getGet('key');
        $value = $this->request->getGet('value');
        $type = $this->request->getGet('type','');
        /**@var \Weline\Backend\Model\Config $config */
        $config = ObjectManager::getInstance(\Weline\Backend\Model\Config::class);
        $config->setConfig($key, $value, 'Weline_Backend');
        $fetchName = 'fetch' . ucfirst((string)$type);
        if(!method_exists($this, $fetchName)){
            $this->getMessageManager()->addWarning(__('保存失败，请重试!不支持的类型：%1', $type));
            return $this->fetch();
        }
        # 清理缓存
        /**@var Clear $cache */
        $cache = ObjectManager::getInstance(Clear::class);
        $cache->execute(['-f']);
        ob_clean();
        try {
            if($type === 'json'){
                return $this->$fetchName($this->success('保存成功!'));
            }else{
                $this->getMessageManager()->addSuccess(__('保存成功,缓存清理成功！'));
                $this->redirect($this->request->getReferer());
            }
        } catch (\Exception $exception) {
            if($type === 'json'){
                return $this->$fetchName($this->error(__('保存失败! %1', $exception->getMessage())));
            }else{
                $this->getMessageManager()->addWarning(__('保存失败! %1', $exception->getMessage()));
                $this->redirect($this->request->getReferer());
            }
        }
    }
}
