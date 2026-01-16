<?php

namespace MadeByBramble\GoogleShoppingFeed;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\console\Application as ConsoleApplication;
use craft\events\RegisterCacheOptionsEvent;
use craft\helpers\ElementHelper;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use craft\events\RegisterUrlRulesEvent;
use MadeByBramble\GoogleShoppingFeed\services\FeedService;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public static $plugin;

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'feedService' => FeedService::class,
        ]);

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'MadeByBramble\\GoogleShoppingFeed\\console\\controllers';
        }

        $this->registerRoutes();
        $this->registerEventHandlers();
    }

    protected function registerRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['google-shopping-feed.xml'] = 'google-shopping-feed/feed/index';
            }
        );
    }

    /**
     * Register event handlers for cache invalidation and utilities
     */
    protected function registerEventHandlers(): void
    {
        // Register element save events to invalidate cache on product/variant changes
        Event::on(
            Product::class,
            Product::EVENT_AFTER_SAVE,
            [$this, 'handleProductSave']
        );

        Event::on(
            Variant::class,
            Variant::EVENT_AFTER_SAVE,
            [$this, 'handleVariantSave']
        );

        Event::on(
            Product::class,
            Product::EVENT_AFTER_DELETE,
            [$this, 'handleProductDelete']
        );

        Event::on(
            Variant::class,
            Variant::EVENT_AFTER_DELETE,
            [$this, 'handleVariantDelete']
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $options = $event->options;
                $options['google-shopping-feed'] = [
                    'key' => 'google-shopping-feed',
                    'label' => 'Google Shopping Feed',
                    'info' => 'Invalidates the feed cache and queues regeneration for all sites.',
                    'action' => function () {
                        foreach (Craft::$app->getSites()->getAllSites() as $site) {
                            $feedService = Plugin::getInstance()->feedService;
                            $feedService->invalidateCache($site->id);
                            $feedService->queueFeedGeneration($site->id, true);
                        }
                    },
                ];
                $event->options = $options;
            }
        );
    }

    /**
     * Handle product save event - invalidates and regenerates only the affected chunk
     */
    public function handleProductSave(\craft\events\ModelEvent $event): void
    {
        /** @var Product $product */
        $product = $event->sender;

        if (ElementHelper::isDraftOrRevision($product)) {
            return;
        }

        $this->invalidateAndRegenerateChunk($product->siteId, $product->id);
    }

    /**
     * Handle variant save event - invalidates and regenerates only the affected chunk
     */
    public function handleVariantSave(\craft\events\ModelEvent $event): void
    {
        /** @var Variant $variant */
        $variant = $event->sender;

        if (ElementHelper::isDraftOrRevision($variant)) {
            return;
        }

        $product = $variant->getOwner();
        if ($product) {
            $this->invalidateAndRegenerateChunk($variant->siteId, $product->id);
        }
    }

    /**
     * Handle product delete event - invalidates and regenerates only the affected chunk
     */
    public function handleProductDelete(Event $event): void
    {
        /** @var Product $product */
        $product = $event->sender;

        $this->invalidateAndRegenerateChunk($product->siteId, $product->id);
    }

    /**
     * Handle variant delete event - invalidates and regenerates only the affected chunk
     */
    public function handleVariantDelete(Event $event): void
    {
        /** @var Variant $variant */
        $variant = $event->sender;

        $product = $variant->getOwner();
        if ($product) {
            $this->invalidateAndRegenerateChunk($variant->siteId, $product->id);
        }
    }

    /**
     * Invalidate and regenerate the chunk containing a specific product
     */
    protected function invalidateAndRegenerateChunk(int $siteId, int $productId): void
    {
        $feedService = $this->feedService;
        $feedService->invalidateAndRegenerateChunkForProduct($siteId, $productId);
    }

    /**
     * Invalidate and regenerate feeds for all sites
     */
    protected function invalidateAndRegenerateAllSites(): void
    {
        $feedService = $this->feedService;

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $feedService->invalidateCache($site->id);
            $feedService->queueFeedGeneration($site->id, true);
        }
    }

    public function createSettingsModel(): ?\craft\base\Model
    {
        return new \MadeByBramble\GoogleShoppingFeed\models\Settings();
    }

    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $settings->validate();

        try {
            $feedService = $this->feedService;
            $allProductFields = $feedService->getAvailableProductFields();
            $allVariantFields = $feedService->getAvailableVariantFields();

            $fieldConstraints = [];
            $googleFields = $feedService->getAvailableGoogleFields();
            foreach ($googleFields as $googleField => $googleFieldName) {
                $fieldConstraints[$googleField] = $feedService->getAllowedFieldTypesForGoogleField($googleField);
            }

            // Get feed status for all sites
            $feedStatus = [];
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $meta = $feedService->getFeedMeta($site->id);
                $hasCachedFeed = $feedService->getCachedFeed($site->id) !== null;

                $feedStatus[$site->id] = [
                    'site' => $site,
                    'meta' => $meta,
                    'hasCachedFeed' => $hasCachedFeed,
                ];
            }

            return Craft::$app->view->renderTemplate('google-shopping-feed/_settings', [
                'settings' => $settings,
                'googleFields' => $googleFields,
                'productFields' => $allProductFields,
                'variantFields' => $allVariantFields,
                'fieldConstraints' => $fieldConstraints,
                'feedStatus' => $feedStatus,
                'cacheDurationOptions' => \MadeByBramble\GoogleShoppingFeed\models\Settings::getCacheDurationOptions(),
            ]);
        } catch (\Throwable $e) {
            Craft::error('Error rendering Google Shopping Feed settings: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), 'google-shopping-feed');
            return '<div class="error">Error loading settings: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}
