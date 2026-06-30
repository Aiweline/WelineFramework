<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Currency\Controller\Backend;

use Weline\Currency\Model\Currency as CurrencyModel;
use Weline\Currency\Service\CurrencyLocalDescriptionService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 货币管理控制器
 */
#[\Weline\Framework\Acl\Acl('Weline_Currency::currency_list', '货币管理', 'mdi mdi-currency-usd', '货币管理功能')]
class Currency extends BackendController
{
    /**
     * @var CurrencyModel
     */
    private CurrencyModel $currencyModel;
    private ?CurrencyLocalDescriptionService $localDescriptionService = null;

    /**
     * 构造函数
     */
    public function __construct(CurrencyModel $currencyModel)
    {
        $this->currencyModel = $currencyModel;
    }

    /**
     * 货币列表
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_list_view', '查看货币列表', '', '查看货币列表')]
     */
    public function index()
    {
        if ($search = $this->request->getGet('search')) {
            $this->currencyModel->concat_like('code,name,symbol', "%$search%");
        }
        
        $currencies = $this->currencyModel->order('currency_id', 'desc')
            ->pagination()
            ->select()
            ->fetch();
        
        // 处理货币数据，添加格式化示例
        $currencyItems = $currencies->getItems();
        $currencyData = [];
        foreach ($currencyItems as $currency) {
            $data = $currency->getData();
            // 添加格式化示例
            $data['formatted_example'] = $currency->formatAmount(1234.56);
            $currencyData[] = $data;
        }
        
        $this->assign('currencies', $currencyData);
        $this->assign('pagination', $currencies->getPagination());
        return $this->fetch();
    }

    /**
     * 添加货币表单
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_add', '添加货币', '', '添加货币界面')]
     */
    public function getAdd()
    {
        $currency = clone $this->currencyModel->clear();
        $this->assign('currency', $currency);
        $this->assignLocalDescriptionFormData($currency);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('currency/backend/currency/postAdd'));
        return $this->fetch('form');
    }

    /**
     * 编辑货币表单
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_edit', '编辑货币', '', '编辑货币界面')]
     */
    public function getEdit()
    {
        $currency = clone $this->currencyModel->clear()->load($this->request->getGet('id'));
        if (!$currency->getId()) {
            $this->getMessageManager()->addError(__('货币不存在！'));
            return $this->redirect('currency/backend/currency/index');
        }
        $this->assign('currency', $currency);
        $this->assignLocalDescriptionFormData($currency);
        $this->assign('action', $this->request->getUrlBuilder()->getBackendUrl('currency/backend/currency/postEdit', $this->request->getGet()));
        return $this->fetch('form');
    }

    /**
     * 添加货币
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_add_post', '添加货币请求', '', '请求添加货币')]
     */
    public function postAdd()
    {
        return $this->postSave();
    }

    /**
     * 编辑货币
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_edit_post', '编辑货币请求', '', '请求编辑货币')]
     */
    public function postEdit()
    {
        return $this->postSave();
    }

    /**
     * 保存货币（添加/编辑）
     */
    private function postSave()
    {
        try {
            $id = $this->request->getPost('currency_id');
            $currency = clone $this->currencyModel->clear();
            
            if ($id) {
                $currency->load($id);
            }
            
            $currency->setCode(strtoupper($this->request->getPost('code')))
                ->setName($this->request->getPost('name'))
                ->setRate((float)$this->request->getPost('rate'))
                ->setSymbol($this->request->getPost('symbol'))
                ->setPosition($this->request->getPost('position', 'left'))
                ->setFormat($this->request->getPost('format', '2,0'))
                ->setStatus((bool)$this->request->getPost('status', true))
                ->setIcon($this->request->getPost('icon'))
                ->setThousandSeparator($this->request->getPost('thousand_separator', ','))
                ->setDecimalSeparator($this->request->getPost('decimal_separator', '.'))
                ->setBaseCurrency(strtoupper($this->request->getPost('base_currency', 'CNY')))
                ->save();

            $this->localDescriptionService()->saveLocalNames(
                $currency,
                (array)$this->request->getPost('local_names', [])
            );
            
            $this->getMessageManager()->addSuccess(__('保存成功！'));
            $this->redirect('currency/backend/currency/index');
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('保存失败: %{1}', $e->getMessage()));
            if (DEV) {
                $this->getMessageManager()->addException($e);
            }
            $this->redirect('currency/backend/currency/index');
        }
    }

    /**
     * 删除货币
     * 
     * #[\Weline\Framework\Acl\Acl('Weline_Currency::currency_delete', '删除货币', '', '删除货币')]
     */
    public function postDelete()
    {
        try {
            // 支持 JSON 和表单数据
            $params = $this->request->getParams();
            $id = $params['id'] ?? $this->request->getPost('id');
            
            if (empty($id)) {
                throw new \Exception(__('货币ID不能为空'));
            }
            
            $currency = $this->currencyModel->clear()->load($id);
            
            if (!$currency->getId()) {
                throw new \Exception(__('货币不存在'));
            }
            
            // 检查是否为基准货币或默认货币
            $config = ObjectManager::getInstance(\Weline\Currency\Model\Config::class);
            $baseCurrency = $config->getBaseCurrency();
            
            if ($currency->getCode() === $baseCurrency) {
                throw new \Exception(__('不能删除基准货币'));
            }
            
            $currencyId = (int)$currency->getId();
            $currency->delete();
            $this->localDescriptionService()->deleteLocalNames($currencyId);
            
            // 返回 JSON 响应（w-delete 组件需要）
            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功！'),
                'msg' => __('删除成功！')
            ]);
        } catch (\Exception $e) {
            // 返回 JSON 错误响应
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败: %{1}', $e->getMessage()),
                'msg' => __('删除失败: %{1}', $e->getMessage())
            ]);
        }
    }

    private function assignLocalDescriptionFormData(CurrencyModel $currency): void
    {
        $this->assign('currency_locales', $this->localDescriptionService()->getAvailableLocales());
        $this->assign(
            'currency_local_names',
            $this->localDescriptionService()->getLocalNames((int)$currency->getId())
        );
    }

    private function localDescriptionService(): CurrencyLocalDescriptionService
    {
        if ($this->localDescriptionService === null) {
            $this->localDescriptionService = ObjectManager::getInstance(CurrencyLocalDescriptionService::class);
        }

        return $this->localDescriptionService;
    }
}

