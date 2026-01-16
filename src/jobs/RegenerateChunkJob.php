<?php

namespace MadeByBramble\GoogleShoppingFeed\jobs;

use Craft;
use craft\commerce\elements\Product;
use craft\queue\BaseJob;
use MadeByBramble\GoogleShoppingFeed\Plugin;

/**
 * Regenerate Chunk Job
 *
 * Lightweight job that regenerates a single chunk of the feed.
 * Used when individual products are updated to avoid full catalog rebuild.
 */
class RegenerateChunkJob extends BaseJob
{
    public ?int $siteId = null;
    public ?int $chunkIndex = null;

    public function execute($queue): void
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        $feedService = Plugin::getInstance()->feedService;
        $chunkCount = $feedService->getChunkCount();

        if ($this->chunkIndex < 0 || $this->chunkIndex >= $chunkCount) {
            throw new \Exception("Invalid chunk index: {$this->chunkIndex}");
        }

        Craft::info("Regenerating chunk {$this->chunkIndex} for site ID: {$this->siteId}", 'google-shopping-feed');

        $query = Product::find()
            ->site($site)
            ->status('live')
            ->andWhere('MOD([[commerce_products.id]], :count) = :chunk', [
                ':count' => $chunkCount,
                ':chunk' => $this->chunkIndex,
            ])
            ->with(['variants'])
            ->orderBy('id ASC');

        $items = [];

        foreach ($query->all() as $product) {
            $variants = $product->getVariants();

            foreach ($variants as $variant) {
                if (!$variant->enabled) {
                    continue;
                }

                $feedItem = $feedService->buildFeedItemFromVariant($variant);
                if ($feedItem) {
                    $items[] = $feedItem;
                }
            }
        }

        $feedService->setCachedChunk($this->siteId, $this->chunkIndex, $items);

        Craft::info("Chunk {$this->chunkIndex} regenerated with " . count($items) . " items for site ID: {$this->siteId}", 'google-shopping-feed');
    }

    protected function defaultDescription(): string
    {
        return "Regenerating feed chunk {$this->chunkIndex}";
    }
}
