<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Review\Model\ReviewRatingOption;
use Weline\Framework\Manager\ObjectManager;

class ReviewRatingOptionService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDefaultOptions(): array
    {
        return [
            ['code' => 'quality', 'label' => '商品质量', 'sort_order' => 10],
            ['code' => 'logistics', 'label' => '物流体验', 'sort_order' => 20],
            ['code' => 'service', 'label' => '服务态度', 'sort_order' => 30],
            ['code' => 'description', 'label' => '描述相符', 'sort_order' => 40],
        ];
    }

    public function seedDefaultOptions(): void
    {
        foreach ($this->getDefaultOptions() as $option) {
            /** @var ReviewRatingOption $model */
            $model = ObjectManager::getInstance(ReviewRatingOption::class);
            $existing = $model->clear()
                ->where(ReviewRatingOption::schema_fields_CODE, $option['code'])
                ->find()
                ->fetch();

            if ($existing->getId()) {
                continue;
            }

            $model->clearData()
                ->setData(ReviewRatingOption::schema_fields_CODE, $option['code'])
                ->setData(ReviewRatingOption::schema_fields_LABEL, $option['label'])
                ->setData(ReviewRatingOption::schema_fields_IS_ENABLED, 1)
                ->setData(ReviewRatingOption::schema_fields_SORT_ORDER, $option['sort_order'])
                ->setData(ReviewRatingOption::schema_fields_IS_SYSTEM, 1)
                ->setData(ReviewRatingOption::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
                ->setData(ReviewRatingOption::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->forceCheck(true, [ReviewRatingOption::schema_fields_CODE])
                ->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllOptions(): array
    {
        /** @var ReviewRatingOption $model */
        $model = ObjectManager::getInstance(ReviewRatingOption::class);

        return $model->clear()
            ->order(ReviewRatingOption::schema_fields_SORT_ORDER, 'ASC')
            ->order(ReviewRatingOption::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEnabledOptions(): array
    {
        /** @var ReviewRatingOption $model */
        $model = ObjectManager::getInstance(ReviewRatingOption::class);

        return $model->clear()
            ->where(ReviewRatingOption::schema_fields_IS_ENABLED, 1)
            ->order(ReviewRatingOption::schema_fields_SORT_ORDER, 'ASC')
            ->order(ReviewRatingOption::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getEnabledOptionMap(): array
    {
        $map = [];
        foreach ($this->getEnabledOptions() as $option) {
            $code = (string) ($option[ReviewRatingOption::schema_fields_CODE] ?? '');
            if ($code !== '') {
                $map[$code] = $option;
            }
        }

        return $map;
    }

    /**
     * @param array<int|string, mixed> $options
     * @param array<string, mixed> $newOption
     */
    public function saveOptions(array $options, array $newOption = []): void
    {
        foreach ($options as $optionId => $optionData) {
            if (!is_array($optionData)) {
                continue;
            }

            $id = (int) ($optionData['option_id'] ?? $optionId);
            if ($id <= 0) {
                continue;
            }

            /** @var ReviewRatingOption $model */
            $model = ObjectManager::getInstance(ReviewRatingOption::class);
            $model->load($id);
            if (!$model->getId()) {
                continue;
            }

            $label = trim((string) ($optionData['label'] ?? ''));
            if ($label === '') {
                throw new \InvalidArgumentException((string) __('评价项名称不能为空'));
            }

            $model->setData(ReviewRatingOption::schema_fields_LABEL, $label)
                ->setData(ReviewRatingOption::schema_fields_IS_ENABLED, !empty($optionData['is_enabled']) ? 1 : 0)
                ->setData(ReviewRatingOption::schema_fields_SORT_ORDER, (int) ($optionData['sort_order'] ?? 0))
                ->setData(ReviewRatingOption::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
        }

        $this->createCustomOption($newOption);
    }

    /**
     * @param array<string, mixed> $newOption
     */
    private function createCustomOption(array $newOption): void
    {
        $label = trim((string) ($newOption['label'] ?? ''));
        $code = $this->normalizeCode((string) ($newOption['code'] ?? ''));

        if ($label === '' && $code === '') {
            return;
        }

        if ($label === '' || $code === '') {
            throw new \InvalidArgumentException((string) __('新增评价项需要同时填写编码和名称'));
        }

        /** @var ReviewRatingOption $model */
        $model = ObjectManager::getInstance(ReviewRatingOption::class);
        $existing = $model->clear()
            ->where(ReviewRatingOption::schema_fields_CODE, $code)
            ->find()
            ->fetch();

        if ($existing->getId()) {
            throw new \InvalidArgumentException((string) __('评价项编码已存在'));
        }

        $model->clearData()
            ->setData(ReviewRatingOption::schema_fields_CODE, $code)
            ->setData(ReviewRatingOption::schema_fields_LABEL, $label)
            ->setData(ReviewRatingOption::schema_fields_IS_ENABLED, !empty($newOption['is_enabled']) ? 1 : 0)
            ->setData(ReviewRatingOption::schema_fields_SORT_ORDER, (int) ($newOption['sort_order'] ?? 100))
            ->setData(ReviewRatingOption::schema_fields_IS_SYSTEM, 0)
            ->setData(ReviewRatingOption::schema_fields_CREATED_AT, date('Y-m-d H:i:s'))
            ->setData(ReviewRatingOption::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->forceCheck(true, [ReviewRatingOption::schema_fields_CODE])
            ->save();
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        return trim($code, '_');
    }
}
