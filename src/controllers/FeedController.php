<?php

namespace MadeByBramble\GoogleShoppingFeed\controllers;

use Craft;
use craft\web\Controller;
use MadeByBramble\GoogleShoppingFeed\Plugin;
use yii\web\Response;

class FeedController extends Controller
{
    protected array|int|bool $allowAnonymous = ['index'];

    public function actionIndex(): Response
    {
        try {
            $site = Craft::$app->getSites()->getCurrentSite();
            $feedService = Plugin::getInstance()->feedService;

            $cachedXml = $feedService->getCachedFeed($site->id);

            if ($cachedXml !== null) {
                return $this->respondWithXml($cachedXml);
            }

            $meta = $feedService->getFeedMeta($site->id);

            if (($meta['status'] ?? null) === 'generating') {
                return $this->respondWithGenerating($site);
            }

            $cacheStatus = $feedService->getCacheStatus($site->id);
            if ($cacheStatus['cachedChunks'] > 0) {
                $xml = $feedService->assembleFeedFromChunks($site->id);
                if ($xml) {
                    return $this->respondWithXml($xml);
                }
            }

            $feedService->queueFeedGeneration($site->id);

            return $this->respondWithGenerating($site);

        } catch (\Throwable $e) {
            Craft::error('Google Shopping Feed generation failed: ' . $e->getMessage(), 'google-shopping-feed');

            $response = Craft::$app->getResponse();
            $response->format = Response::FORMAT_RAW;
            $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
            $response->statusCode = 500;
            $response->data = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel><title>Feed Error</title><description>Feed generation failed</description></channel></rss>';

            return $response;
        }
    }

    /**
     * Regenerate feed action (called from CP settings)
     */
    public function actionRegenerate(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $siteId = $request->getBodyParam('siteId');

        if (!$siteId) {
            return $this->asJson([
                'success' => false,
                'error' => 'Site ID is required.',
            ]);
        }

        $site = Craft::$app->getSites()->getSiteById((int)$siteId);

        if (!$site) {
            return $this->asJson([
                'success' => false,
                'error' => 'Site not found.',
            ]);
        }

        try {
            $feedService = Plugin::getInstance()->feedService;
            $feedService->invalidateCache($site->id);
            $queued = $feedService->queueFeedGeneration($site->id, true);

            return $this->asJson([
                'success' => true,
                'queued' => $queued,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Failed to queue feed regeneration: ' . $e->getMessage(), 'google-shopping-feed');

            return $this->asJson([
                'success' => false,
                'error' => 'Failed to queue regeneration: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Respond with cached XML feed
     */
    private function respondWithXml(string $xml): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->data = $xml;

        return $response;
    }

    /**
     * Respond with 503 when feed is generating
     */
    private function respondWithGenerating(\craft\models\Site $site): Response
    {
        $feedService = Plugin::getInstance()->feedService;

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->headers->set('Retry-After', '60');
        $response->statusCode = 503;
        $response->data = $feedService->generateGeneratingXml($site);

        return $response;
    }
}
