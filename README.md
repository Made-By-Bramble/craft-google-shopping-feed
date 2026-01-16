# Google Shopping Feed

A Craft CMS plugin that generates Google Shopping product feeds from Craft Commerce products.

## Requirements

- PHP 8.2 or later
- Craft CMS 5.5.0 or later
- Craft Commerce 5.2 or later

## Installation

### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your Craft project directory
cd /path/to/your/project

# Require the plugin via Composer
composer require madebybramble/craft-google-shopping-feed

# Install the plugin via the Craft CLI
php craft plugin/install google-shopping-feed
```

Alternatively, you can install the plugin through the Craft Control Panel by navigating to Settings > Plugins, searching for "Google Shopping Feed", and clicking Install.

## Feed URL

Once installed, the feed is available at:

```
https://your-site.com/google-shopping-feed.xml
```

The feed is generated per site, so multi-site installations will have separate feeds for each site.

## Configuration

All settings are managed through the Control Panel at Settings > Plugins > Google Shopping Feed.

### Cache Settings

| Setting | Description | Options |
|---------|-------------|---------|
| Cache Duration | How long the generated feed is cached before regeneration | 15 minutes, 30 minutes, 1 hour, 2 hours, 4 hours, 8 hours, 12 hours, 24 hours |

**Important:** The cron job interval must be set to run at or before the cache duration expires. If the cache expires before the cron runs, visitors requesting the feed will receive a 503 response while regeneration is queued. For example, if cache duration is set to 1 hour, the cron should run at least every hour.

### Unit Settings

| Setting | Description | Options |
|---------|-------------|---------|
| Weight Unit | Unit for shipping weight values | kg, g, lb, oz |
| Dimension Unit | Unit for shipping dimension values | cm, in |

### Field Mappings

Map your product and variant custom fields to Google Shopping Feed attributes. The following Google Shopping fields can be mapped:

| Google Field | Description | Supported Field Types |
|--------------|-------------|----------------------|
| description | Product description | Text, Rich Text |
| image_link | Primary product image | Asset |
| additional_image_link | Additional product images | Asset |
| condition | Product condition | Text, Dropdown, Radio |
| brand | Product brand | Text, Dropdown, Entries |
| gtin | Global Trade Item Number | Text, Number |
| mpn | Manufacturer Part Number | Text, Number |
| google_product_category | Google product taxonomy ID | Text, Number |
| color | Product color | Text, Dropdown, Radio |
| size | Product size | Text, Dropdown, Radio |
| gender | Target gender | Text, Dropdown, Radio |
| age_group | Target age group | Text, Dropdown, Radio |
| material | Product material | Text, Dropdown, Radio |
| pattern | Product pattern | Text, Dropdown, Radio |
| shipping_weight | Shipping weight | Number |
| shipping_length | Shipping length | Number |
| shipping_width | Shipping width | Number |
| shipping_height | Shipping height | Number |
| expiration_date | Product expiration date | Date, DateTime |

The following fields are automatically populated and do not require mapping:

- id (uses variant SKU or ID)
- title (uses product/variant title)
- link (uses product/variant URL)
- price (uses variant price with store currency)
- sale_price (uses promotional price when applicable)
- availability (derived from stock and purchasability)
- item_group_id (set for products with multiple variants)
- product_type (uses product type name)
- identifier_exists (set to "no" when brand, gtin, and mpn are all missing)

## Console Commands

The plugin provides console commands for managing feed generation.

### Generate Feed

Queue feed generation jobs for one or all sites:

```bash
# Generate for all sites
php craft google-shopping-feed/feed/generate

# Generate for a specific site
php craft google-shopping-feed/feed/generate --site-id=1

# Force regeneration even if cache is valid
php craft google-shopping-feed/feed/generate --force
```

### Check Status

Display the current status of the feed cache:

```bash
php craft google-shopping-feed/feed/status
```

### Check Cache Status

Display detailed cache information including chunk data:

```bash
php craft google-shopping-feed/feed/cache-status
```

### Invalidate Cache

Clear the feed cache for one or all sites:

```bash
# Invalidate all sites
php craft google-shopping-feed/feed/invalidate

# Invalidate a specific site
php craft google-shopping-feed/feed/invalidate --site-id=1
```

## Cron Setup

To keep the feed current, set up a cron job to regenerate the feed at your configured cache duration interval:

```bash
# Example: regenerate hourly
0 * * * * /usr/bin/php /path/to/your/project/craft google-shopping-feed/feed/generate --force
```

The `--force` flag ensures regeneration is queued even if the cache is still valid, guaranteeing the feed is always available.

## Cache Invalidation

The feed cache is automatically invalidated when:

- A product is created, updated, or deleted
- A variant is created, updated, or deleted

The plugin uses a chunked caching strategy, so when a single product is updated, only the relevant cache chunk is regenerated rather than the entire feed.

You can also manually invalidate the cache through:

- The Clear Caches utility in the Control Panel (Utilities > Clear Caches > Google Shopping Feed)
- The Regenerate button in the plugin settings
- The console command `php craft google-shopping-feed/feed/invalidate`

## Feed Generation

Feed generation uses Craft's queue system to process products in batches. This prevents timeouts when dealing with large product catalogs.

When the feed URL is requested:

1. If a cached feed exists, it is served immediately
2. If the feed is currently being generated, a 503 response is returned with a Retry-After header
3. If no cache exists, a generation job is queued and a 503 response is returned

## Multi-Site Support

The plugin supports multi-site installations. Each site has its own feed with:

- Separate cache
- Site-specific product URLs
- Store-specific currency

## License

Proprietary. See LICENSE.md for details.
