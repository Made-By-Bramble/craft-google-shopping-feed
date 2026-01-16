<?php

namespace MadeByBramble\GoogleShoppingFeed\jobs;

use Craft;
use craft\base\Batchable;
use craft\commerce\elements\Variant;
use craft\queue\BaseBatchedJob;
use MadeByBramble\GoogleShoppingFeed\Plugin;

/**
 * Generate Feed Job
 *
 * Queue job that generates the Google Shopping Feed for a specific site.
 * Uses Craft's batched job system to automatically split processing across
 * multiple job executions, preventing timeouts on large catalogs.
 * 
 * Items are distributed into chunks based on product ID for efficient
 * partial cache invalidation when individual products are updated.
 */
class GenerateFeedJob extends BaseBatchedJob
{
    public ?int $siteId = null;
    public int $batchSize = 100;

    private function getCacheKeyPrefix(): string
    {
        return "google-shopping-feed:{$this->siteId}";
    }

    protected function before(): void
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        Craft::info("Starting full feed generation for site ID: {$site->id}", 'google-shopping-feed');

        $feedService = Plugin::getInstance()->feedService;
        $feedService->invalidateCache($this->siteId);
        
        Craft::$app->getCache()->delete("{$this->getCacheKeyPrefix()}:items");
        
        Craft::$app->getCache()->set("{$this->getCacheKeyPrefix()}:meta", [
            'status' => 'generating',
            'startedAt' => time(),
            'completedAt' => null,
            'itemCount' => 0,
            'error' => null,
        ]);
    }

    /**
     * Load the data to be processed in batches
     * Returns a ProductVariantBatcher that processes all enabled variants
     *
     * @return Batchable The batcher containing the variant query
     */
    public function loadData(): Batchable
    {
        $site = Craft::$app->getSites()->getSiteById($this->siteId);

        if (!$site) {
            throw new \Exception("Site with ID {$this->siteId} not found");
        }

        $query = Variant::find()
            ->site($site)
            ->status('enabled')
            ->innerJoin(
                ['products' => '{{%commerce_products}}'],
                '[[commerce_variants.primaryOwnerId]] = [[products.id]]'
            )
            ->orderBy('commerce_variants.id ASC');

        return new ProductVariantBatcher($query, $this->siteId);
    }

    public function processItem(mixed $item): void
    {
        if (!$item instanceof Variant) {
            return;
        }

        try {
            $feedService = Plugin::getInstance()->feedService;
            $feedItem = $feedService->buildFeedItemFromVariant($item);

            if ($feedItem) {
                $product = $item->getOwner();
                $productId = $product ? $product->id : 0;
                
                $cacheKey = $this->getCacheKeyPrefix() . ':items';
                $items = Craft::$app->getCache()->get($cacheKey) ?: [];
                $items[] = [
                    'productId' => $productId,
                    'item' => $feedItem,
                ];
                Craft::$app->getCache()->set($cacheKey, $items);
            }
        } catch (\Throwable $e) {
            Craft::error("Error processing variant {$item->id}: {$e->getMessage()}", 'google-shopping-feed');
        }
    }

    protected function after(): void
    {
        $cacheKeyPrefix = $this->getCacheKeyPrefix();

        try {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            
            if (!$site) {
                throw new \Exception("Site with ID {$this->siteId} not found");
            }

            $feedService = Plugin::getInstance()->feedService;
            $rawItems = Craft::$app->getCache()->get("{$cacheKeyPrefix}:items") ?: [];
            
            $chunks = [];
            $chunkCount = $feedService->getChunkCount();
            
            for ($i = 0; $i < $chunkCount; $i++) {
                $chunks[$i] = [];
            }

            foreach ($rawItems as $rawItem) {
                $productId = $rawItem['productId'] ?? 0;
                $feedItem = $rawItem['item'] ?? null;
                
                if ($feedItem) {
                    $chunkIndex = $feedService->getChunkIndex($productId);
                    $chunks[$chunkIndex][] = $feedItem;
                }
            }

            $totalItems = 0;
            foreach ($chunks as $chunkIndex => $chunkItems) {
                $feedService->setCachedChunk($this->siteId, $chunkIndex, $chunkItems);
                $totalItems += count($chunkItems);
            }

            Craft::info("Distributed {$totalItems} items into {$chunkCount} chunks for site ID: {$this->siteId}", 'google-shopping-feed');

            $xml = $feedService->assembleFeedFromChunks($this->siteId);

            Craft::$app->getCache()->delete("{$cacheKeyPrefix}:items");

            Craft::info("Feed generation completed for site ID: {$this->siteId} with {$totalItems} items", 'google-shopping-feed');
        } catch (\Throwable $e) {
            Craft::error("Feed generation failed for site ID: {$this->siteId}: {$e->getMessage()}", 'google-shopping-feed');

            Craft::$app->getCache()->set("{$cacheKeyPrefix}:meta", [
                'status' => 'error',
                'startedAt' => null,
                'completedAt' => time(),
                'itemCount' => 0,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return 'Generating Google Shopping Feed';
    }
}
