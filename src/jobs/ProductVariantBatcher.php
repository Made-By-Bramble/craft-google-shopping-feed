<?php

namespace MadeByBramble\GoogleShoppingFeed\jobs;

use Craft;
use craft\base\Batchable;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQuery;

/**
 * Product Variant Batcher
 *
 * A Batchable implementation that processes Commerce variants for feed generation.
 */
class ProductVariantBatcher implements Batchable
{
    /**
     * @var ElementQuery The variant query to process
     */
    private ElementQuery $query;

    /**
     * @var int The site ID
     */
    private int $siteId;

    /**
     * @var int|null Total count (cached)
     */
    private ?int $totalCount = null;

    /**
     * Constructor
     *
     * @param ElementQuery $query The variant query to process
     * @param int $siteId The site ID
     */
    public function __construct(ElementQuery $query, int $siteId)
    {
        $this->query = $query;
        $this->siteId = $siteId;
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        if ($this->totalCount === null) {
            $this->totalCount = (clone $this->query)->count();
        }

        return $this->totalCount;
    }

    /**
     * @inheritdoc
     */
    public function getSlice(int $offset, int $limit): iterable
    {
        $variants = (clone $this->query)
            ->offset($offset)
            ->limit($limit)
            ->all();

        foreach ($variants as $variant) {
            if ($variant->enabled) {
                $product = $variant->getOwner();
                if ($product && $product->enabled) {
                    yield $variant;
                }
            }
        }
    }
}
