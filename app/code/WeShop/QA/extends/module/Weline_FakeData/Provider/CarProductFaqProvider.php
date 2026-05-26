<?php

declare(strict_types=1);

namespace WeShop\QA\Extends\Module\Weline_FakeData\Provider;

use WeShop\Catalog\Model\Category;
use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductCategory;
use WeShop\QA\Model\Question;
use WeShop\QA\Service\QAService;
use Weline\FakeData\Api\FakeDataProviderInterface;
use Weline\FakeData\Data\FakeDataContext;
use Weline\FakeData\Data\FakeDataResult;

class CarProductFaqProvider implements FakeDataProviderInterface
{
    private const CODE = 'weshop_qa_car_faq';
    private const ENTITY_FAQ = 'car_product_faq';

    public function __construct(
        private readonly Product $product,
        private readonly ProductCategory $productCategory,
        private readonly Category $category,
        private readonly Question $question,
        private readonly QAService $qaService,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getModuleName(): string
    {
        return 'WeShop_QA';
    }

    public function getLabel(): string
    {
        return 'WeShop car product FAQ';
    }

    public function getSortOrder(): int
    {
        return 260;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function describe(): array
    {
        return [
            'entities' => [self::ENTITY_FAQ],
            'count' => count($this->findCarProducts()) * count($this->qaService->getDefaultCarProductFaqs()),
        ];
    }

    public function seed(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $products = $this->applyLimit($this->findCarProducts(), $context->getLimit());
        foreach ($products as $productData) {
            $productId = (int) ($productData[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                $result->addSkipped();
                continue;
            }

            foreach ($this->qaService->ensureDefaultCarProductFaqs($productId, $productData) as $entry) {
                $questionId = (int) ($entry['question_id'] ?? 0);
                if ($questionId <= 0) {
                    $result->addSkipped();
                    continue;
                }

                if (!empty($entry['created'])) {
                    $context->record(
                        self::CODE,
                        self::ENTITY_FAQ,
                        $questionId,
                        'car-faq:product:' . $productId . ':' . (int) ($entry['index'] ?? 0),
                        [
                            'product_id' => $productId,
                            'question' => (string) ($entry['question'] ?? ''),
                        ]
                    );
                    $result->addCreated();
                } elseif (!empty($entry['updated'])) {
                    $result->addUpdated();
                } else {
                    $result->addSkipped();
                }
            }
        }

        return $result;
    }

    public function cleanup(FakeDataContext $context): FakeDataResult
    {
        $result = new FakeDataResult();
        $records = $context->getRecordService()->getRecords(self::CODE, self::ENTITY_FAQ);
        foreach ($records as $record) {
            $questionId = (int) ($record['entity_id'] ?? 0);
            $stableKey = (string) ($record['stable_key'] ?? '');
            if ($questionId > 0) {
                $this->question->clear()
                    ->getQuery()
                    ->where(Question::schema_fields_ID, $questionId)
                    ->delete()
                    ->fetch();
                $result->addDeleted();
            }
            if ($stableKey !== '') {
                $context->getRecordService()->removeRecord(self::CODE, $stableKey);
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findCarProducts(): array
    {
        $rows = $this->product->clear()
            ->select()
            ->fetchArray();

        $products = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row[Product::schema_fields_ID] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $candidate = $row;
            $candidate['categories'] = $this->getProductCategories($productId);
            if ($this->qaService->isCarProductData($candidate)) {
                $products[] = $candidate;
            }
        }

        return $products;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getProductCategories(int $productId): array
    {
        $categoryLinks = (clone $this->productCategory)->clear()
            ->where(ProductCategory::schema_fields_product_id, $productId)
            ->select()
            ->fetchArray();
        $categoryIds = [];
        foreach ($categoryLinks as $link) {
            $categoryId = (int) ($link[ProductCategory::schema_fields_category_id] ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if ($categoryIds === []) {
            return [];
        }

        return (clone $this->category)->clear()
            ->where(Category::schema_fields_ID, $categoryIds, 'in')
            ->select()
            ->fetchArray();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function applyLimit(array $items, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return $items;
        }

        return array_slice($items, 0, $limit);
    }
}
