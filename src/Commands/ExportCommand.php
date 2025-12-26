<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Commands;

use BatteryIncludedSdk\Dto\CategoryDto;
use BatteryIncludedSdk\Dto\ProductBaseDto;
use BatteryIncludedSdk\Dto\ProductPropertyDto;
use BatteryIncludedSdk\Service\SyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    protected static string $defaultName = 'bi:export';

    public function __construct(
        private SyncService $syncService,
        ?string $name = null,
        ?callable $code = null)
    {
        parent::__construct($name, $code);
    }

    public function configure(): void
    {
        $this->setName(static::$defaultName)
            ->setDescription('Exportiert Daten aus dem System.')
            ->setHelp('Dieser Befehl exportiert Daten und gibt eine Bestätigung aus.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $products = self::generateProducts(20);
        $this->syncService->syncFullElements(... $products);
        $output->writeln('<info>Export erfolgreich abgeschlossen.</info>');

        return Command::SUCCESS;
    }

    public static function generateProducts(
        int $iterations,
        array $devices = ['iPhone', 'iPad', 'MacBook'],
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
                            'The latest ' . $device . ' with advanced features. Color: ' . $color . ', Storage: ' . $storage . '.'
                        );
                        $product->setId((string) $id);
                        $product->setOrdernumber('AP-00' . $i . '-' . $color . '-' . $storage);
                        $product->setPrice(1000 + $id);
                        $product->setInstock(rand(0, 50));
                        $product->setRating((float) (mt_rand(1, 10) / 2));
                        $product->setManufacture('Apple');
                        $product->setManufactureNumber('A' . $i . '-' . $color . '-' . $storage);
                        $product->setEan('195950639292');
                        $product->setImageUrl(
                            'https://dummyimage.com/600x400/bbb/fff.png&text=' . $i . '-' . $color . '-' . $storage
                        );
                        $product->setShopUrl('https://www.apple.com/' . $device . '-' . $i . '-pro/');
                        $product->setProperties(
                            (new ProductPropertyDto())
                                ->addProperty('Gerät', $device)
                                ->addProperty('Farbe', $color)
                                ->addProperty('Speicherkapazität', $storage)
                                ->addProperty('Displaygröße', '6,1')
                        );
                        $product->addCategory(
                            (new CategoryDto())->addCategoryNode('Apple')->addCategoryNode($device)->addCategoryNode($device . ' ' . $i . ' Pro')
                        );
                        $product->addCategory(
                            (new CategoryDto())->addCategoryNode('Apple')->addCategoryNode($device . ' Pro ' . $color)
                        );
                        $products[] = $product;
                    }
                }
            }
        }

        return $products;
    }
}
