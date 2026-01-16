<?php

namespace MadeByBramble\GoogleShoppingFeed\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * @var array Field mappings configuration
     */
    public array $fieldMappings = [];

    /**
     * @var string Weight unit for shipping (kg, g, lb, oz)
     */
    public string $weightUnit = 'kg';

    /**
     * @var string Dimension unit for shipping (cm, in)
     */
    public string $dimensionUnit = 'cm';

    /**
     * @var int Cache duration in seconds (default 1 hour)
     */
    public int $cacheDuration = 3600;

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['fieldMappings'], 'safe'],
            [['weightUnit'], 'in', 'range' => ['kg', 'g', 'lb', 'oz']],
            [['dimensionUnit'], 'in', 'range' => ['cm', 'in']],
            [['cacheDuration'], 'integer', 'min' => 60, 'max' => 86400],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        if (!is_array($this->fieldMappings)) {
            $this->fieldMappings = [];
        }
    }

    /**
     * Get cache duration options for the settings form
     */
    public static function getCacheDurationOptions(): array
    {
        return [
            900 => '15 minutes',
            1800 => '30 minutes',
            3600 => '1 hour',
            7200 => '2 hours',
            14400 => '4 hours',
            28800 => '8 hours',
            43200 => '12 hours',
            86400 => '24 hours',
        ];
    }
}
