<?php

namespace App\Provider;

use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use BatteryIncludedSdk\Dto\CategoryDto;
use BatteryIncludedSdk\Dto\ProductBaseDto;
use BatteryIncludedSdk\Dto\ProductPropertyDto;
use Generator;

/**
 * Test data of the sdk (tests/Helper.php): products with german and english translations and prices per country.
 */
class ProductProvider implements DataProviderInterface
{
    private const MANUFACTURER = 'BatteryIncluded';

    private const DEVICE_CODES = ['Smartphone' => 'SP', 'Tablet' => 'TB', 'Laptop' => 'LT'];

    private const COLORS = [
        'de' => ['Blau' => 'Blau', 'Rosa' => 'Rosa', 'Gold' => 'Gold', 'Schwarz' => 'Schwarz'],
        'en' => ['Blau' => 'Blue', 'Rosa' => 'Pink', 'Gold' => 'Gold', 'Schwarz' => 'Black'],
    ];

    private const TEXTS = [
        'de' => [
            'description' => 'Das neueste %s mit vielen Funktionen. Farbe: %s, Speicherkapazität: %s.',
            'shopUrl' => 'https://www.batteryincluded.io/de/%s-%d-pro/',
            'decimalSeparator' => ',',
            // nouns are capitalized in german
            'lowercaseDevice' => false,
        ],
        'en' => [
            'description' => 'The latest %s with advanced features. Color: %s, Storage: %s.',
            'shopUrl' => 'https://www.batteryincluded.io/en/%s-%d-pro/',
            'decimalSeparator' => '.',
            'lowercaseDevice' => true,
        ],
    ];

    // display sizes in inch of the small and the large model of each device, the generations alternate between both
    private const DISPLAY_SIZES = [
        'Smartphone' => [6.3, 6.9],
        'Tablet' => [11.0, 13.0],
        'Laptop' => [14.2, 16.2],
    ];

    public function getBatches(int $batchSize): Generator
    {
        $products = $this->generateProducts(20);
        $batches = array_chunk($products, $batchSize);

        foreach ($batches as $skuBatch) {
            yield $skuBatch;
        }
    }

    private function generateProducts(
        int $iterations,
        array $devices = ['Smartphone', 'Tablet', 'Laptop'],
        array $colours = ['Blau', 'Rosa', 'Gold', 'Schwarz'],
        array $storages = ['128GB', '256GB', '512GB'],
    ): array {
        $products = [];
        $id = 0;
        for ($i = 1; $i <= $iterations; $i++) {
            foreach ($devices as $device) {
                foreach ($colours as $color) {
                    foreach ($storages as $storage) {
                        $id++;
                        $product = new ProductBaseDto((string) $id);
                        $product->setName($device . ' ' . $i . ' Pro ' . $color . ' - ' . $storage);
                        $product->setDescription(
                            'Das neueste ' . $device . ' mit vielen Funktionen. Farbe: ' . $color . ', Speicherkapazität: ' . $storage . '.'
                        );
                        $product->setId((string) $id);
                        // unique per product, the device is part of the number
                        $product->setOrdernumber(sprintf('BI-%s-%02d-%s-%s', self::DEVICE_CODES[$device], $i, $color, $storage));
                        $product->setPrice(1000 + $id);
                        $product->setInstock(rand(0, 50));
                        $product->setActive(true);
                        $product->setRating((float) (mt_rand(1, 10) / 2));
                        $product->setManufacture(self::MANUFACTURER);
                        $product->setManufactureNumber(sprintf('%s%d-%s-%s', self::DEVICE_CODES[$device], $i, $color, $storage));
                        $product->setEan('195950639292');
                        $product->setImageUrl(
                            'https://dummyimage.com/600x400/bbb/fff.png&text=' . rawurlencode($device . ' ' . $i . ' ' . $color . ' ' . $storage)
                        );
                        $product->setShopUrl('https://www.batteryincluded.io/' . mb_strtolower($device) . '-' . $i . '-pro/');
                        // property names are always english, the labels are translated in the backend
                        $product->setProperties(
                            (new ProductPropertyDto())
                                ->addProperty('Device', $device)
                                ->addProperty('Color', $color)
                                ->addProperty('Storage', $storage)
                                ->addProperty('Display size', $this->displaySize($device, $i, 'de'))
                        );
                        $product->addCategory(
                            (new CategoryDto())->addCategoryNode(self::MANUFACTURER)->addCategoryNode($device)->addCategoryNode($device . ' ' . $i . ' Pro')
                        );
                        // every locale is exported to the _PRODUCT_i18n root node, the regular fields above are the fallback
                        foreach (array_keys(self::TEXTS) as $locale) {
                            $this->addTranslations($product, $locale, $i, $device, $color, $storage);
                        }
                        $this->addAvailabilities($product, $id);
                        $products[] = $product;
                    }
                }
            }
        }

        return $products;
    }

    private function addTranslations(ProductBaseDto $product, string $locale, int $i, string $device, string $color, string $storage): void
    {
        $texts = self::TEXTS[$locale];
        $translatedColor = self::COLORS[$locale][$color] ?? $color;

        $product
            ->addTranslation($locale, 'name', $device . ' ' . $i . ' Pro ' . $translatedColor . ' - ' . $storage)
            ->addTranslation($locale, 'description', sprintf($texts['description'], $texts['lowercaseDevice'] ? mb_strtolower($device) : $device, $translatedColor, $storage))
            ->addTranslation($locale, 'shopUrl', sprintf($texts['shopUrl'], mb_strtolower($device), $i))
            ->setPropertiesTranslation(
                $locale,
                (new ProductPropertyDto())
                    ->addProperty('Device', $device)
                    ->addProperty('Color', $translatedColor)
                    ->addProperty('Storage', $storage)
                    ->addProperty('Display size', $this->displaySize($device, $i, $locale))
            )
            ->addCategoryTranslation(
                $locale,
                (new CategoryDto())->addCategoryNode(self::MANUFACTURER)->addCategoryNode($device)->addCategoryNode($device . ' ' . $i . ' Pro')
            );
    }

    /**
     * Prices and status per country in `_PRODUCT_availability`, the regular fields above are the fallback.
     */
    private function addAvailabilities(ProductBaseDto $product, int $id): void
    {
        $price = (float) $product->getPrice();

        // every third product is on sale in germany
        $product->setAvailability('de', active: true, price: $id % 3 === 0 ? round($price * 0.9, 2) : $price);
        $product->setAvailability('at', price: round($price * 1.02, 2));
        // switzerland has a few products less and no stock of its own
        $product->setAvailability('ch', active: $id % 8 !== 0, price: round($price * 1.1, 2), instock: $id % 5 === 0 ? 0 : null);
    }

    private function displaySize(string $device, int $i, string $locale): string
    {
        $size = self::DISPLAY_SIZES[$device][$i % 2] ?? self::DISPLAY_SIZES['Smartphone'][$i % 2];
        $decimals = floor($size) === $size ? 0 : 1;

        return number_format($size, $decimals, self::TEXTS[$locale]['decimalSeparator'], '');
    }
}
