<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend;

use Weline\Framework\App\Exception;

class Total extends \Weline\Framework\View\Block
{
    /**
     * @throws Exception
     */
    public function __init()
    {
        parent::__init();
        # 如果没有模板，则使用默认模板
        if (!$this->getTemplate()) {
            $this->setTemplate('Weline_Admin::backend/total-card.phtml');
        }
        # 检测模型是否提供
        if (!$this->getData('model')) {
            throw new Exception('未提供模型model参数');
        }
        # 检测标题是否提供
        if (!$this->getData('title')) {
            throw new Exception('未提供标题title参数');
        }
        # 检测提示是否提供
        if (!$this->getData('tip')) {
            throw new Exception('未提供提示tip参数');
        }
        # 将标题和提示赋值给模板
        $this->assign('title', $this->getData('title'));
        $this->assign('tip', $this->getData('tip'));
        # 检测比较期限是否提供
        $last_period = $this->getData('last-period') ?? ''; # 默认用昨日期限
        $now_period = $this->getData('now-period') ?? ''; # 默认用今日期限
        $sum_field = $this->getData('sum-field') ?? ''; # 统计字段
        $period_field = $this->getData('period-field') ?? 'main_table.create_time'; # 排期字段
        $model = $this->_getModel($this->getData('model'));
        # 默认
        $last_period_total = 0;
        $now_period_total = 0;
        if ($sum_field) {
            $now_items = $model->reset()->period($now_period,$period_field)->select()->fetchArray();
            $total = 0;
            foreach ($now_items as $now_item) {
                $total += $now_item[$sum_field] ?? 0;
            }
            # period上期数据总计
            if ($last_period) {
                $last_period_items = $model->reset()->period($last_period,$period_field)->select()->fetchArray();
                foreach ($last_period_items as $last_period_item) {
                    $last_period_total += $last_period_item[$sum_field] ?? 0;
                }
            }
            # period本期数据总计
            if ($now_period) {
                $now_period_items = $model->reset()->period($now_period,$period_field)->select()->fetchArray();
                foreach ($now_period_items as $now_period_item) {
                    $now_period_total += $now_period_item[$sum_field] ?? 0;
                }
            }
        } else {
            # period上期数据总计
            if($last_period){
                $last_period_total = $model->reset()->period($last_period,$period_field)->total();
            }
            # period本期数据总计
            if($now_period){
                $now_period_total = $model->reset()->period($now_period,$period_field)->total();
            }
            $total = $now_period_total;
        }

        $this->assign('total', $total);
        $this->assign('last_period_total', $last_period_total);
        $this->assign('now_period_total', $now_period_total);

        # 相比较上涨率 除数不能为0
        if ($last_period_total == 0) {
            if($now_period_total==0){
                $rate = 0;
            }else{
                $rate = 100;
            }
        } else {
            if($now_period_total==0){
                $rate = -100;
            }else{
                $rate = (($now_period_total - $last_period_total) / $last_period_total) * 100;
            }
        }
        $rate = number_format($rate, 0);
        $this->assign('up_down', $rate > 0 ? 'up' : 'down');
        $this->assign('rate', $rate);
        // 使用 md5 + uniqid 确保每个 block 实例的 ID 唯一，避免重复声明（如 hook 多次渲染同数据块）
        $id = 'id' . md5(json_encode($this->getData()) . '_' . uniqid('', true));
        $this->assign('id', $id);
        # class
        $this->assign('class', $rate > 0 ? 'text-danger' : 'text-success');
        $options_name = 'radialoptions' . $id;
        $chart_name = 'radialchart' . $id;
        # 向footer中注入js脚本（需在 ApexCharts 加载后执行，使用 IIFE + 延迟检测）
        $this->getFooter()->append('Weline_Admin::head',
            "<script>
(function() {
    function initRadialChart() {
        if (typeof ApexCharts === 'undefined') {
            setTimeout(initRadialChart, 50);
            return;
        }
        var opts = {
            series: [{$rate}],
            chart: { type: 'radialBar', width: 72, height: 72, sparkline: { enabled: true } },
            dataLabels: { enabled: false },
            colors: ['#0ab39c'],
            stroke: { lineCap: 'round' },
            plotOptions: {
                radialBar: {
                    hollow: { margin: 0, size: '70%' },
                    track: { margin: 0 },
                    dataLabels: { name: { show: false }, value: { offsetY: 5, show: true } }
                }
            }
        };
        var el = document.querySelector('#{$id}');
        if (el) {
            var chart = new ApexCharts(el, opts);
            chart.render();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRadialChart);
    } else {
        initRadialChart();
    }
})();
                </script>"
        );
    }
}
