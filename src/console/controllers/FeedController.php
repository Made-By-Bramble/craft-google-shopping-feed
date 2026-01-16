<?php

namespace MadeByBramble\GoogleShoppingFeed\console\controllers;

use Craft;
use craft\console\Controller;
use MadeByBramble\GoogleShoppingFeed\jobs\GenerateFeedJob;
use MadeByBramble\GoogleShoppingFeed\Plugin;
use yii\console\ExitCode;

/**
 * Feed Console Controller
 *
 * Provides console commands for managing the Google Shopping Feed.
 * 
 * Usage for cron (runs hourly):
 * 0 * * * * /path/to/craft google-shopping-feed/feed/generate
 */
class FeedController extends Controller
{
    /**
     * @var bool Whether to force regeneration even if cache is valid
     */
    public bool $force = false;

    /**
     * @var int|null Specific site ID to generate feed for (null = all sites)
     */
    public ?int $siteId = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'generate') {
            $options[] = 'force';
            $options[] = 'siteId';
        }

        return $options;
    }

    /**
     * Generate the Google Shopping Feed
     *
     * Queues a job to generate the feed for one or all sites.
     * Use --force to regenerate even if the cache is still valid.
     * Use --site-id=X to generate for a specific site only.
     *
     * @return int Exit code
     */
    public function actionGenerate(): int
    {
        $this->stdout("Google Shopping Feed Generator\n");
        $this->stdout("================================\n\n");

        $sites = [];

        if ($this->siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            if (!$site) {
                $this->stderr("Site with ID {$this->siteId} not found.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            $sites[] = $site;
        } else {
            $sites = Craft::$app->getSites()->getAllSites();
        }

        $feedService = Plugin::getInstance()->feedService;
        $jobsQueued = 0;

        foreach ($sites as $site) {
            $this->stdout("Processing site: {$site->name} (ID: {$site->id})\n");

            if (!$this->force) {
                $cachedXml = $feedService->getCachedFeed($site->id);
                if ($cachedXml !== null) {
                    $meta = $feedService->getFeedMeta($site->id);
                    $completedAt = $meta['completedAt'] ?? null;
                    $timeAgo = $completedAt ? $this->formatTimeAgo($completedAt) : 'unknown';
                    
                    $this->stdout("  - Cache is valid (generated {$timeAgo}). Use --force to regenerate.\n");
                    continue;
                }
            }

            $meta = $feedService->getFeedMeta($site->id);
            if (($meta['status'] ?? null) === 'generating') {
                $this->stdout("  - Feed is currently being generated. Skipping.\n");
                continue;
            }

            $this->stdout("  - Queueing feed generation job...\n");
            
            Craft::$app->getQueue()->push(new GenerateFeedJob([
                'siteId' => $site->id,
            ]));

            $jobsQueued++;
            $this->stdout("  - Job queued successfully.\n");
        }

        $this->stdout("\n");

        if ($jobsQueued > 0) {
            $this->stdout("Queued {$jobsQueued} feed generation job(s).\n");
            $this->stdout("Run the queue to process: ./craft queue/run\n");
        } else {
            $this->stdout("No jobs queued. All feeds are up to date.\n");
        }

        return ExitCode::OK;
    }

    /**
     * Show the status of the Google Shopping Feed cache
     *
     * @return int Exit code
     */
    public function actionStatus(): int
    {
        $this->stdout("Google Shopping Feed Status\n");
        $this->stdout("============================\n\n");

        $feedService = Plugin::getInstance()->feedService;
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            $this->stdout("Site: {$site->name} (ID: {$site->id})\n");

            $meta = $feedService->getFeedMeta($site->id);
            $cachedXml = $feedService->getCachedFeed($site->id);

            $status = $meta['status'] ?? 'none';
            $itemCount = $meta['itemCount'] ?? 0;
            $completedAt = $meta['completedAt'] ?? null;
            $error = $meta['error'] ?? null;

            $this->stdout("  Status: {$status}\n");
            $this->stdout("  Items: {$itemCount}\n");

            if ($completedAt) {
                $timeAgo = $this->formatTimeAgo($completedAt);
                $this->stdout("  Last Generated: {$timeAgo}\n");
            }

            if ($cachedXml !== null) {
                $size = strlen($cachedXml);
                $this->stdout("  Cache: Valid (" . $this->formatBytes($size) . ")\n");
            } else {
                $this->stdout("  Cache: Expired or missing\n");
            }

            if ($error) {
                $this->stdout("  Error: {$error}\n");
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Show detailed cache status including chunk information
     *
     * @return int Exit code
     */
    public function actionCacheStatus(): int
    {
        $this->stdout("Google Shopping Feed Cache Status\n");
        $this->stdout("==================================\n\n");

        $feedService = Plugin::getInstance()->feedService;
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            $this->stdout("Site: {$site->name} (ID: {$site->id})\n");

            $cacheStatus = $feedService->getCacheStatus($site->id);

            $this->stdout("  Chunks: {$cacheStatus['cachedChunks']}/{$cacheStatus['chunkCount']} cached\n");
            $this->stdout("  Items: {$cacheStatus['totalItems']}\n");
            $this->stdout("  Chunk Size: " . $this->formatBytes($cacheStatus['chunkSize']) . "\n");
            
            if ($cacheStatus['xmlValid']) {
                $this->stdout("  Assembled XML: Valid (" . $this->formatBytes($cacheStatus['xmlSize']) . ")\n");
            } else {
                $this->stdout("  Assembled XML: Not cached\n");
            }

            $this->stdout("  Status: {$cacheStatus['status']}\n");

            if ($cacheStatus['newestChunkAt']) {
                $this->stdout("  Last Chunk Update: " . $this->formatTimeAgo($cacheStatus['newestChunkAt']) . "\n");
            }

            if ($cacheStatus['oldestChunkAt'] && $cacheStatus['oldestChunkAt'] !== $cacheStatus['newestChunkAt']) {
                $this->stdout("  Oldest Chunk: " . $this->formatTimeAgo($cacheStatus['oldestChunkAt']) . "\n");
            }

            if ($cacheStatus['cachedChunks'] > 0 && $cacheStatus['cachedChunks'] < $cacheStatus['chunkCount']) {
                $percentage = round(($cacheStatus['cachedChunks'] / $cacheStatus['chunkCount']) * 100);
                $this->stdout("  Coverage: {$percentage}%\n");
            }

            $this->stdout("\n");
        }

        return ExitCode::OK;
    }

    /**
     * Invalidate the feed cache for one or all sites
     *
     * @return int Exit code
     */
    public function actionInvalidate(): int
    {
        $this->stdout("Invalidating Google Shopping Feed Cache\n");
        $this->stdout("========================================\n\n");

        $feedService = Plugin::getInstance()->feedService;

        if ($this->siteId !== null) {
            $site = Craft::$app->getSites()->getSiteById($this->siteId);
            if (!$site) {
                $this->stderr("Site with ID {$this->siteId} not found.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
            
            $feedService->invalidateCache($site->id);
            $this->stdout("Cache invalidated for site: {$site->name}\n");
        } else {
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                $feedService->invalidateCache($site->id);
                $this->stdout("Cache invalidated for site: {$site->name}\n");
            }
        }

        return ExitCode::OK;
    }

    /**
     * Format a timestamp as a human-readable time ago string
     */
    private function formatTimeAgo(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return "{$diff} seconds ago";
        }

        if ($diff < 3600) {
            $minutes = floor($diff / 60);
            return "{$minutes} minute" . ($minutes !== 1 ? 's' : '') . " ago";
        }

        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return "{$hours} hour" . ($hours !== 1 ? 's' : '') . " ago";
        }

        $days = floor($diff / 86400);
        return "{$days} day" . ($days !== 1 ? 's' : '') . " ago";
    }

    /**
     * Format bytes as human-readable string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 2) . ' ' . $units[$index];
    }
}
