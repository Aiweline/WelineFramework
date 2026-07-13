<?php

declare(strict_types=1);

namespace Weline\UrlManager\Api\Rewrite;

use Weline\UrlManager\Model\UrlRewrite;

final class UrlRewriteDirectory implements UrlRewriteDirectoryInterface
{
    public function __construct(
        private readonly UrlRewrite $urlRewrite,
    ) {
    }

    public function listNonEmptyRewrites(): array
    {
        $rows = $this->urlRewrite->reset()
            ->where(UrlRewrite::schema_fields_REWRITE, '', '!=')
            ->order(UrlRewrite::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $records = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $websiteIdSpecified = \array_key_exists(UrlRewrite::schema_fields_WEBSITE_ID, $row)
                && $row[UrlRewrite::schema_fields_WEBSITE_ID] !== null;
            $rawWebsiteId = $websiteIdSpecified
                ? $row[UrlRewrite::schema_fields_WEBSITE_ID]
                : null;
            $websiteId = $websiteIdSpecified && \is_numeric($rawWebsiteId)
                ? (int)$rawWebsiteId
                : null;

            $records[] = new UrlRewriteRecord(
                id: (int)($row[UrlRewrite::schema_fields_ID] ?? 0),
                websiteId: $websiteId,
                websiteIdSpecified: $websiteIdSpecified,
                path: (string)($row[UrlRewrite::schema_fields_PATH] ?? ''),
                rewrite: (string)($row[UrlRewrite::schema_fields_REWRITE] ?? ''),
            );
        }

        return $records;
    }

    public function findByPath(string $path, int $websiteId): ?UrlRewriteRecord
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return null;
        }
        $row = (clone $this->urlRewrite)->reset()
            ->where(UrlRewrite::schema_fields_WEBSITE_ID, $websiteId)
            ->where(UrlRewrite::schema_fields_PATH, $path)
            ->order(UrlRewrite::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();
        if (!$row->getId()) {
            return null;
        }
        return new UrlRewriteRecord(
            id: (int)$row->getData(UrlRewrite::schema_fields_ID),
            websiteId: (int)$row->getData(UrlRewrite::schema_fields_WEBSITE_ID),
            websiteIdSpecified: true,
            path: (string)$row->getData(UrlRewrite::schema_fields_PATH),
            rewrite: (string)$row->getData(UrlRewrite::schema_fields_REWRITE),
        );
    }

    public function currentWebsiteId(): int
    {
        return UrlRewrite::getCurrentWebsiteId();
    }
}
