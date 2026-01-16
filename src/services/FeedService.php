<?php

namespace MadeByBramble\GoogleShoppingFeed\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\UrlHelper;
use craft\models\Site;
use MadeByBramble\GoogleShoppingFeed\jobs\GenerateFeedJob;
use MadeByBramble\GoogleShoppingFeed\Plugin;

class FeedService extends Component
{
    private const CACHE_PREFIX = 'google-shopping-feed';
    private const CHUNK_COUNT = 100;

    /**
     * Get the cached feed XML for a site
     *
     * @param int $siteId The site ID
     * @return string|null The cached XML or null if not cached/expired
     */
    public function getCachedFeed(int $siteId): ?string
    {
        $cacheKey = $this->getCacheKey($siteId, 'xml');
        $cached = Craft::$app->getCache()->get($cacheKey);

        return $cached !== false ? $cached : null;
    }

    /**
     * Get feed metadata for a site
     *
     * @param int $siteId The site ID
     * @return array The metadata array
     */
    public function getFeedMeta(int $siteId): array
    {
        $cacheKey = $this->getCacheKey($siteId, 'meta');
        $meta = Craft::$app->getCache()->get($cacheKey);

        if ($meta === false || !is_array($meta)) {
            return [
                'status' => 'none',
                'startedAt' => null,
                'completedAt' => null,
                'itemCount' => 0,
                'error' => null,
            ];
        }

        return $meta;
    }

    /**
     * Invalidate the feed cache for a site (all chunks)
     *
     * @param int $siteId The site ID
     */
    public function invalidateCache(int $siteId): void
    {
        Craft::$app->getCache()->delete($this->getCacheKey($siteId, 'xml'));
        Craft::$app->getCache()->delete($this->getCacheKey($siteId, 'items'));
        
        for ($i = 0; $i < self::CHUNK_COUNT; $i++) {
            Craft::$app->getCache()->delete($this->getChunkCacheKey($siteId, $i));
        }
        Craft::$app->getCache()->delete($this->getCacheKey($siteId, 'chunk-meta'));

        Craft::info("Feed cache invalidated for site ID: {$siteId}", 'google-shopping-feed');
    }

    /**
     * Get the chunk index for a product ID
     */
    public function getChunkIndex(int $productId): int
    {
        return $productId % self::CHUNK_COUNT;
    }

    /**
     * Get the total number of chunks
     */
    public function getChunkCount(): int
    {
        return self::CHUNK_COUNT;
    }

    /**
     * Invalidate a specific chunk and the assembled XML
     */
    public function invalidateChunk(int $siteId, int $chunkIndex): void
    {
        Craft::$app->getCache()->delete($this->getChunkCacheKey($siteId, $chunkIndex));
        Craft::$app->getCache()->delete($this->getCacheKey($siteId, 'xml'));
        
        $chunkMeta = $this->getChunkMeta($siteId);
        unset($chunkMeta['chunks'][$chunkIndex]);
        $this->setChunkMeta($siteId, $chunkMeta);

        Craft::info("Chunk {$chunkIndex} invalidated for site ID: {$siteId}", 'google-shopping-feed');
    }

    /**
     * Get cached items for a specific chunk
     */
    public function getCachedChunk(int $siteId, int $chunkIndex): ?array
    {
        $cached = Craft::$app->getCache()->get($this->getChunkCacheKey($siteId, $chunkIndex));
        return $cached !== false ? $cached : null;
    }

    /**
     * Set cached items for a specific chunk
     */
    public function setCachedChunk(int $siteId, int $chunkIndex, array $items): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $cacheDuration = $settings->cacheDuration ?? 3600;

        Craft::$app->getCache()->set(
            $this->getChunkCacheKey($siteId, $chunkIndex),
            $items,
            $cacheDuration
        );

        $chunkMeta = $this->getChunkMeta($siteId);
        $chunkMeta['chunks'][$chunkIndex] = [
            'itemCount' => count($items),
            'updatedAt' => time(),
        ];
        $this->setChunkMeta($siteId, $chunkMeta);
    }

    /**
     * Get chunk metadata for a site
     */
    public function getChunkMeta(int $siteId): array
    {
        $cached = Craft::$app->getCache()->get($this->getCacheKey($siteId, 'chunk-meta'));
        if ($cached === false || !is_array($cached)) {
            return ['chunks' => []];
        }
        return $cached;
    }

    /**
     * Set chunk metadata for a site
     */
    protected function setChunkMeta(int $siteId, array $meta): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $cacheDuration = $settings->cacheDuration ?? 3600;
        
        Craft::$app->getCache()->set(
            $this->getCacheKey($siteId, 'chunk-meta'),
            $meta,
            $cacheDuration
        );
    }

    /**
     * Queue regeneration of a specific chunk
     */
    public function queueChunkRegeneration(int $siteId, int $chunkIndex): void
    {
        Craft::$app->getQueue()->push(new \MadeByBramble\GoogleShoppingFeed\jobs\RegenerateChunkJob([
            'siteId' => $siteId,
            'chunkIndex' => $chunkIndex,
        ]));

        Craft::info("Chunk {$chunkIndex} regeneration queued for site ID: {$siteId}", 'google-shopping-feed');
    }

    /**
     * Invalidate chunk for a product and queue regeneration
     */
    public function invalidateAndRegenerateChunkForProduct(int $siteId, int $productId): void
    {
        $chunkIndex = $this->getChunkIndex($productId);
        $this->invalidateChunk($siteId, $chunkIndex);
        $this->queueChunkRegeneration($siteId, $chunkIndex);
    }

    /**
     * Assemble feed XML from all cached chunks
     */
    public function assembleFeedFromChunks(int $siteId): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        if (!$site) {
            return null;
        }

        $allItems = [];
        
        for ($i = 0; $i < self::CHUNK_COUNT; $i++) {
            $chunkItems = $this->getCachedChunk($siteId, $i);
            if ($chunkItems !== null) {
                $allItems = array_merge($allItems, $chunkItems);
            }
        }

        $xml = $this->renderFeedXml($site, $allItems);

        $settings = Plugin::getInstance()->getSettings();
        $cacheDuration = $settings->cacheDuration ?? 3600;
        
        Craft::$app->getCache()->set(
            $this->getCacheKey($siteId, 'xml'),
            $xml,
            $cacheDuration
        );

        Craft::$app->getCache()->set($this->getCacheKey($siteId, 'meta'), [
            'status' => 'complete',
            'startedAt' => null,
            'completedAt' => time(),
            'itemCount' => count($allItems),
            'error' => null,
        ]);

        Craft::info("Assembled feed from chunks with " . count($allItems) . " items for site ID: {$siteId}", 'google-shopping-feed');

        return $xml;
    }

    /**
     * Get detailed cache status for a site
     */
    public function getCacheStatus(int $siteId): array
    {
        $chunkMeta = $this->getChunkMeta($siteId);
        $cachedXml = $this->getCachedFeed($siteId);
        $meta = $this->getFeedMeta($siteId);

        $cachedChunks = 0;
        $totalItems = 0;
        $chunkSize = 0;
        $oldestChunk = null;
        $newestChunk = null;

        for ($i = 0; $i < self::CHUNK_COUNT; $i++) {
            $chunk = $this->getCachedChunk($siteId, $i);
            if ($chunk !== null) {
                $cachedChunks++;
                $totalItems += count($chunk);
                $chunkSize += strlen(serialize($chunk));
                
                $chunkInfo = $chunkMeta['chunks'][$i] ?? null;
                if ($chunkInfo && isset($chunkInfo['updatedAt'])) {
                    if ($oldestChunk === null || $chunkInfo['updatedAt'] < $oldestChunk) {
                        $oldestChunk = $chunkInfo['updatedAt'];
                    }
                    if ($newestChunk === null || $chunkInfo['updatedAt'] > $newestChunk) {
                        $newestChunk = $chunkInfo['updatedAt'];
                    }
                }
            }
        }

        return [
            'chunkCount' => self::CHUNK_COUNT,
            'cachedChunks' => $cachedChunks,
            'totalItems' => $totalItems,
            'chunkSize' => $chunkSize,
            'xmlSize' => $cachedXml ? strlen($cachedXml) : 0,
            'xmlValid' => $cachedXml !== null,
            'oldestChunkAt' => $oldestChunk,
            'newestChunkAt' => $newestChunk,
            'status' => $meta['status'] ?? 'none',
            'completedAt' => $meta['completedAt'] ?? null,
        ];
    }

    /**
     * Get the cache key for a chunk
     */
    private function getChunkCacheKey(int $siteId, int $chunkIndex): string
    {
        return self::CACHE_PREFIX . ":{$siteId}:chunk:{$chunkIndex}";
    }

    /**
     * Queue feed generation for a site
     *
     * @param int $siteId The site ID
     * @param bool $force Force regeneration even if already generating
     * @return bool Whether a job was queued
     */
    public function queueFeedGeneration(int $siteId, bool $force = false): bool
    {
        $meta = $this->getFeedMeta($siteId);

        if (!$force && ($meta['status'] ?? null) === 'generating') {
            Craft::info("Feed generation already in progress for site ID: {$siteId}", 'google-shopping-feed');
            return false;
        }

        Craft::$app->getQueue()->push(new GenerateFeedJob([
            'siteId' => $siteId,
        ]));

        Craft::info("Feed generation job queued for site ID: {$siteId}", 'google-shopping-feed');
        return true;
    }

    /**
     * Build a feed item array from a variant (used by batch job)
     *
     * @param Variant $variant The variant to process
     * @return array|null The feed item array or null if invalid
     */
    public function buildFeedItemFromVariant(Variant $variant): ?array
    {
        $product = $variant->getOwner();

        if (!$product instanceof Product) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();
        $mappings = $settings->fieldMappings ?? [];

        $stores = CommercePlugin::getInstance()->getStores();
        $store = $stores->getStoreById($product->storeId ?? null) ?? $stores->getPrimaryStore();

        if (!$store) {
            return null;
        }

        $currency = $store->currency ?? 'USD';

        return $this->buildFeedItem($product, $variant, $mappings, $currency, $store);
    }

    /**
     * Render the feed XML from items array
     *
     * @param Site $site The site
     * @param array $items The feed items
     * @return string The rendered XML
     */
    public function renderFeedXml(Site $site, array $items): string
    {
        $templatePath = Plugin::getInstance()->getBasePath() . '/templates/_feed.twig';

        if (!file_exists($templatePath)) {
            Craft::error("Template not found: {$templatePath}", 'google-shopping-feed');
            return $this->generateErrorXml('Template not found');
        }

        $templateContent = file_get_contents($templatePath);
        $oldMode = Craft::$app->view->getTemplateMode();
        Craft::$app->view->setTemplateMode(Craft::$app->view::TEMPLATE_MODE_SITE);

        try {
            $output = Craft::$app->view->renderString($templateContent, [
                'site' => $site,
                'items' => $items,
            ]);
        } finally {
            Craft::$app->view->setTemplateMode($oldMode);
        }

        return $output;
    }

    /**
     * Generate the feed synchronously (legacy method, now uses cache when available)
     *
     * @return string The feed XML
     */
    public function generateFeed(): string
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        $cached = $this->getCachedFeed($site->id);
        if ($cached !== null) {
            return $cached;
        }

        $settings = Plugin::getInstance()->getSettings();
        $mappings = $settings->fieldMappings ?? [];

        $stores = CommercePlugin::getInstance()->getStores();
        $store = $stores->getCurrentStore();

        if (!$store) {
            Craft::error('No store configured for current site', 'google-shopping-feed');
            return $this->generateEmptyFeed('No store configured');
        }

        $currency = $store->currency ?? 'USD';

        $query = Product::find()
            ->status('live')
            ->site($site)
            ->with(['variants'])
            ->orderBy('id ASC');

        $items = [];

        foreach (\craft\helpers\Db::each($query, 100) as $product) {
            $variants = $product->getVariants();
            if (count($variants) === 0) {
                continue;
            }

            foreach ($variants as $variant) {
                if (!$variant->enabled) {
                    continue;
                }

                $item = $this->buildFeedItem($product, $variant, $mappings, $currency, $store);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $this->renderFeedXml($site, $items);
    }

    /**
     * Generate an empty feed XML
     *
     * @param string $message Optional message
     * @return string The empty feed XML
     */
    public function generateEmptyFeed(string $message = ''): string
    {
        $site = Craft::$app->getSites()->getCurrentSite();
        return $this->renderFeedXml($site, []);
    }

    /**
     * Generate an error XML response
     *
     * @param string $message The error message
     * @return string The error XML
     */
    public function generateErrorXml(string $message): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' .
            '<channel><title>Error</title><description>' .
            htmlspecialchars($message) .
            '</description></channel></rss>';
    }

    /**
     * Generate a "feed generating" XML response
     *
     * @param Site $site The site
     * @return string The generating XML
     */
    public function generateGeneratingXml(Site $site): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' .
            '<channel>' .
            '<title>' . htmlspecialchars($site->name) . ' - Product Feed</title>' .
            '<link>' . htmlspecialchars($site->getBaseUrl()) . '</link>' .
            '<description>Feed is currently being generated. Please try again shortly.</description>' .
            '</channel></rss>';
    }

    /**
     * Get the cache key for a site and type
     *
     * @param int $siteId The site ID
     * @param string $type The cache type (xml, meta, items)
     * @return string The cache key
     */
    private function getCacheKey(int $siteId, string $type): string
    {
        return self::CACHE_PREFIX . ":{$siteId}:{$type}";
    }

    /**
     * Build a feed item from product and variant
     */
    protected function buildFeedItem(Product $product, Variant $variant, array $mappings, string $currency, $store): ?array
    {
        $item = [];

        $id = $variant->sku ?: (string)$variant->id;
        $item['id'] = mb_substr($id, 0, 50);

        $title = $this->getTitle($product, $variant);
        if (!$title) {
            return null;
        }
        $item['title'] = $title;
        $item['description'] = $this->getDescription($product, $variant);

        $link = $this->getLink($product, $variant);
        if (!$link) {
            Craft::info("Skipping variant {$variant->id} ({$variant->sku}): no URL configured", 'google-shopping-feed');
            return null;
        }
        $item['link'] = $link;

        $image = $this->getImage($product, $variant, $mappings);
        $imageUrl = $this->getAssetUrl($image);
        if ($imageUrl) {
            $item['image_link'] = $imageUrl;
        }

        $price = $this->getPrice($variant, $currency);
        if ($price === null) {
            return null;
        }
        $item['price'] = $price;
        $item['availability'] = $this->getAvailability($variant);

        if ($variant->sku) {
            $item['sku'] = $variant->sku;
        }

        if (count($product->getVariants()) > 1) {
            $item['item_group_id'] = (string)$product->id;
        }

        if ($product->type) {
            $item['product_type'] = $product->type->name;
        }

        $salePrice = $this->getSalePrice($variant, $currency);
        if ($salePrice !== null) {
            $item['sale_price'] = $salePrice;
        }

        if (!isset($item['condition'])) {
            $item['condition'] = 'new';
        }

        $autoHandledFields = ['id', 'title', 'description', 'link', 'price', 'availability', 'sku', 'item_group_id', 'condition'];

        foreach ($mappings as $googleField => $mapping) {
            if (in_array($googleField, $autoHandledFields)) {
                continue;
            }

            if (empty($mapping['source']) || empty($mapping['fieldHandle'])) {
                continue;
            }

            $value = $this->getMappedValue($product, $variant, $mapping);
            if ($value !== null) {
                $formattedValue = $this->formatShippingField($googleField, $value);
                if ($formattedValue !== null) {
                    $item[$googleField] = $formattedValue;
                }
            }
        }

        $additionalImages = $this->getAdditionalImages($product, $variant, $mappings);
        if (!empty($additionalImages)) {
            $item['additional_image_link'] = $additionalImages;
        }

        if (!isset($item['brand']) && !isset($item['gtin']) && !isset($item['mpn'])) {
            $item['identifier_exists'] = 'no';
        }

        return $item;
    }

    protected function getTitle(Product $product, Variant $variant): ?string
    {
        $title = $product->title;

        if (!$title || trim($title) === '') {
            Craft::warning("Product {$product->id} has no title, skipping", 'google-shopping-feed');
            return null;
        }

        if ($variant->title && $variant->title !== $product->title) {
            $title = $product->title . ' - ' . $variant->title;
        }

        return mb_substr(trim($title), 0, 150);
    }

    protected function getDescription(Product $product, Variant $variant): string
    {
        $description = $this->getFieldValueSafe($variant, 'description');

        if (!$description) {
            $description = $this->getFieldValueSafe($product, 'description')
                ?? $this->getFieldValueSafe($product, 'summary')
                ?? $this->getFieldValueSafe($product, 'body')
                ?? $product->title;
        }

        $description = strip_tags((string)$description);
        return mb_substr(trim($description), 0, 5000);
    }

    protected function getPrice(Variant $variant, string $currency): ?string
    {
        $price = $variant->salePrice ?? $variant->price;

        if ($price === null) {
            return null;
        }

        return $this->formatPrice((float)$price, $currency);
    }

    protected function getSalePrice(Variant $variant, string $currency): ?string
    {
        if ($variant->onPromotion && $variant->promotionalPrice !== null) {
            return $this->formatPrice((float)$variant->promotionalPrice, $currency);
        }
        return null;
    }

    protected function formatPrice(float $price, string $currency): string
    {
        return number_format($price, 2, '.', '') . ' ' . $currency;
    }

    protected function getAvailability(Variant $variant): string
    {
        if (!$variant->availableForPurchase) {
            return 'out_of_stock';
        }

        if ($variant->hasUnlimitedStock) {
            return 'in_stock';
        }

        if ($variant->stock > 0) {
            return 'in_stock';
        }

        return 'out_of_stock';
    }

    protected function getAssetUrl(?\craft\elements\Asset $asset): ?string
    {
        if (!$asset) {
            return null;
        }

        $url = $asset->getUrl();

        if (!$url) {
            Craft::warning("Asset {$asset->id} has no public URL - volume may not have public URLs enabled", 'google-shopping-feed');
            return null;
        }

        return $url;
    }

    protected function getFieldValueSafe($element, string $fieldHandle): mixed
    {
        try {
            $field = Craft::$app->fields->getFieldByHandle($fieldHandle);
            if (!$field) {
                return null;
            }

            $fieldLayout = $element->getFieldLayout();
            if (!$fieldLayout || !$fieldLayout->isFieldIncluded($fieldHandle)) {
                return null;
            }

            return $element->getFieldValue($fieldHandle);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function getLink(Product $product, Variant $variant): string
    {
        $url = $variant->url ?: $product->url;

        if (!$url) {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        return UrlHelper::siteUrl($url);
    }

    protected function getImage(Product $product, Variant $variant, array $mappings): ?\craft\elements\Asset
    {
        if (isset($mappings['image_link'])) {
            $image = $this->getMappedValue($product, $variant, $mappings['image_link'], true);
            if ($image instanceof \craft\elements\Asset) {
                return $image;
            }
        }

        $fields = ['productImage', 'image', 'variantImage'];
        foreach ($fields as $fieldHandle) {
            $field = $product->{$fieldHandle} ?? null;
            if ($field) {
                if ($field instanceof \craft\elements\Asset) {
                    return $field;
                }
                if ($field instanceof \craft\elements\db\ElementQuery) {
                    $asset = $field->one();
                    if ($asset instanceof \craft\elements\Asset) {
                        return $asset;
                    }
                }
            }

            $field = $variant->{$fieldHandle} ?? null;
            if ($field) {
                if ($field instanceof \craft\elements\Asset) {
                    return $field;
                }
                if ($field instanceof \craft\elements\db\ElementQuery) {
                    $asset = $field->one();
                    if ($asset instanceof \craft\elements\Asset) {
                        return $asset;
                    }
                }
            }
        }

        return null;
    }

    protected function getAdditionalImages(Product $product, Variant $variant, array $mappings): array
    {
        $images = [];

        if (isset($mappings['additional_image_link'])) {
            $value = $this->getMappedValue($product, $variant, $mappings['additional_image_link'], true);
            if ($value) {
                if (is_array($value)) {
                    foreach ($value as $asset) {
                        if ($asset instanceof \craft\elements\Asset) {
                            $url = $this->getAssetUrl($asset);
                            if ($url) {
                                $images[] = $url;
                            }
                        }
                    }
                } elseif ($value instanceof \craft\elements\Asset) {
                    $url = $this->getAssetUrl($value);
                    if ($url) {
                        $images[] = $url;
                    }
                }
            }
        }

        if (empty($images)) {
            $additionalImages = $this->getFieldValueSafe($product, 'additionalImageLink');
            if ($additionalImages instanceof \craft\elements\db\AssetQuery) {
                foreach ($additionalImages->all() as $asset) {
                    $url = $this->getAssetUrl($asset);
                    if ($url) {
                        $images[] = $url;
                    }
                }
            }
        }

        return $images;
    }

    protected function getMappedValue(Product $product, Variant $variant, array $mapping, bool $returnAsset = false)
    {
        $source = $mapping['source'] ?? 'product';
        $fieldHandle = $mapping['fieldHandle'] ?? null;

        if (!$fieldHandle) {
            return null;
        }

        $element = $source === 'variant' ? $variant : $product;

        if ($fieldHandle === 'title') {
            return $element->title;
        }

        if ($fieldHandle === 'url') {
            return $element->url;
        }

        $value = $this->getFieldValueSafe($element, $fieldHandle);

        if ($value === null) {
            return null;
        }

        if ($value instanceof \craft\elements\db\ElementQuery) {
            $value = $value->one();
        }

        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }

        if ($value instanceof \craft\elements\Asset) {
            return $returnAsset ? $value : $this->getAssetUrl($value);
        }

        if ($value instanceof \craft\base\ElementInterface) {
            return $returnAsset ? null : ($value->title ?? (string)$value);
        }

        if (is_array($value)) {
            $results = [];
            foreach ($value as $item) {
                if ($item instanceof \craft\elements\Asset) {
                    $results[] = $returnAsset ? $item : $this->getAssetUrl($item);
                } elseif ($item instanceof \craft\base\ElementInterface) {
                    $results[] = $item->title ?? (string)$item;
                } else {
                    $results[] = $item;
                }
            }
            return $results;
        }

        return $value;
    }

    public function getAvailableGoogleFields(): array
    {
        return [
            'description' => 'Description',
            'image_link' => 'Image Link',
            'condition' => 'Condition',
            'brand' => 'Brand',
            'gtin' => 'GTIN',
            'mpn' => 'MPN',
            'google_product_category' => 'Google Product Category',
            'additional_image_link' => 'Additional Image Link',
            'color' => 'Color',
            'size' => 'Size',
            'gender' => 'Gender',
            'age_group' => 'Age Group',
            'material' => 'Material',
            'pattern' => 'Pattern',
            'shipping_weight' => 'Shipping Weight',
            'shipping_length' => 'Shipping Length',
            'shipping_width' => 'Shipping Width',
            'shipping_height' => 'Shipping Height',
            'expiration_date' => 'Expiration Date',
        ];
    }

    public function getAllowedFieldTypesForGoogleField(string $googleField): array
    {
        $constraints = [
            'image_link' => ['asset'],
            'additional_image_link' => ['asset'],
            'description' => ['text', 'richtext'],
            'condition' => ['text', 'dropdown', 'radio'],
            'brand' => ['text', 'dropdown', 'entries'],
            'gtin' => ['text', 'number'],
            'mpn' => ['text', 'number'],
            'google_product_category' => ['text', 'number'],
            'color' => ['text', 'dropdown', 'radio'],
            'size' => ['text', 'dropdown', 'radio'],
            'gender' => ['text', 'dropdown', 'radio'],
            'age_group' => ['text', 'dropdown', 'radio'],
            'material' => ['text', 'dropdown', 'radio'],
            'pattern' => ['text', 'dropdown', 'radio'],
            'shipping_weight' => ['number'],
            'shipping_length' => ['number'],
            'shipping_width' => ['number'],
            'shipping_height' => ['number'],
            'expiration_date' => ['date', 'datetime'],
        ];

        return $constraints[$googleField] ?? ['text', 'number', 'asset', 'date'];
    }

    protected function isFieldTypeAllowed(string $fieldClass, array $allowedTypes): bool
    {
        if (empty($allowedTypes)) {
            return true;
        }

        $fieldCategory = $this->getFieldTypeCategory($fieldClass);
        return in_array($fieldCategory, $allowedTypes);
    }

    public function getAvailableProductFields(string $googleField = null): array
    {
        $fields = [];

        try {
            $commerce = CommercePlugin::getInstance();
            if (!$commerce) {
                return [];
            }

            $allowedTypes = $googleField ? $this->getAllowedFieldTypesForGoogleField($googleField) : null;

            $productTypes = $commerce->getProductTypes()->getAllProductTypes();
            foreach ($productTypes as $productType) {
                $layout = $productType->getFieldLayout();
                if ($layout) {
                    foreach ($layout->getCustomFields() as $field) {
                        if (!isset($fields[$field->handle])) {
                            $fieldClass = get_class($field);

                            if ($allowedTypes && !$this->isFieldTypeAllowed($fieldClass, $allowedTypes)) {
                                continue;
                            }

                            $fields[$field->handle] = [
                                'handle' => $field->handle,
                                'name' => $field->name,
                                'type' => $fieldClass,
                                'typeCategory' => $this->getFieldTypeCategory($fieldClass),
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Craft::error('Error getting product fields: ' . $e->getMessage(), 'google-shopping-feed');
        }

        return array_values($fields);
    }

    public function getAvailableVariantFields(string $googleField = null): array
    {
        $fields = [];

        $standardFields = [
            ['handle' => 'sku', 'name' => 'SKU', 'type' => 'string', 'typeCategory' => 'text'],
            ['handle' => 'price', 'name' => 'Price', 'type' => 'number', 'typeCategory' => 'number'],
            ['handle' => 'stock', 'name' => 'Stock', 'type' => 'number', 'typeCategory' => 'number'],
            ['handle' => 'width', 'name' => 'Width', 'type' => 'number', 'typeCategory' => 'number'],
            ['handle' => 'height', 'name' => 'Height', 'type' => 'number', 'typeCategory' => 'number'],
            ['handle' => 'length', 'name' => 'Length', 'type' => 'number', 'typeCategory' => 'number'],
            ['handle' => 'weight', 'name' => 'Weight', 'type' => 'number', 'typeCategory' => 'number'],
        ];

        $allowedTypes = $googleField ? $this->getAllowedFieldTypesForGoogleField($googleField) : null;

        foreach ($standardFields as $field) {
            if (!$allowedTypes || in_array($field['typeCategory'], $allowedTypes)) {
                $fields[$field['handle']] = $field;
            }
        }

        try {
            $commerce = CommercePlugin::getInstance();
            if (!$commerce) {
                return array_values($fields);
            }

            $productTypes = $commerce->getProductTypes()->getAllProductTypes();
            foreach ($productTypes as $productType) {
                $layout = $productType->getVariantFieldLayout();
                if ($layout) {
                    foreach ($layout->getCustomFields() as $field) {
                        if (isset($fields[$field->handle])) {
                            continue;
                        }

                        $fieldClass = get_class($field);

                        if ($allowedTypes && !$this->isFieldTypeAllowed($fieldClass, $allowedTypes)) {
                            continue;
                        }

                        $fields[$field->handle] = [
                            'handle' => $field->handle,
                            'name' => $field->name,
                            'type' => $fieldClass,
                            'typeCategory' => $this->getFieldTypeCategory($fieldClass),
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            Craft::error('Error getting variant fields: ' . $e->getMessage(), 'google-shopping-feed');
        }

        return array_values($fields);
    }

    protected function formatShippingField(string $googleField, $value): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($googleField === 'shipping_weight') {
            return $this->formatShippingWeight($value, $settings->weightUnit);
        }

        if (in_array($googleField, ['shipping_length', 'shipping_width', 'shipping_height'])) {
            return $this->formatShippingDimension($value, $settings->dimensionUnit);
        }

        return $value;
    }

    protected function formatShippingWeight($value, string $unit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $numValue = (float)$value;
        if ($numValue <= 0) {
            return null;
        }
        return number_format($numValue, 2, '.', '') . ' ' . $unit;
    }

    protected function formatShippingDimension($value, string $unit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $numValue = (float)$value;
        if ($numValue <= 0) {
            return null;
        }
        return number_format($numValue, 2, '.', '') . ' ' . $unit;
    }

    protected function getFieldTypeCategory(string $fieldClass): string
    {
        if ($fieldClass === 'craft\fields\Assets' || strpos($fieldClass, 'Assets') !== false) {
            return 'asset';
        }

        if ($fieldClass === 'craft\fields\Number' || strpos($fieldClass, 'Number') !== false) {
            return 'number';
        }

        if ($fieldClass === 'craft\fields\Date' || strpos($fieldClass, 'Date') !== false) {
            return 'date';
        }

        if (strpos($fieldClass, 'Redactor') !== false ||
            strpos($fieldClass, 'CKEditor') !== false ||
            strpos($fieldClass, 'Ckeditor') !== false) {
            return 'richtext';
        }

        if ($fieldClass === 'craft\fields\Entries' || strpos($fieldClass, 'Entries') !== false) {
            return 'entries';
        }

        if ($fieldClass === 'craft\fields\Dropdown' ||
            $fieldClass === 'craft\fields\RadioButtons' ||
            strpos($fieldClass, 'Dropdown') !== false ||
            strpos($fieldClass, 'RadioButtons') !== false) {
            return 'dropdown';
        }

        return 'text';
    }
}
