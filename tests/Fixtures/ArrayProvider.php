<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use Batteryincluded\BatteryincludedBundle\Provider\CollectionAwareDataProviderInterface;
use Batteryincluded\BatteryincludedBundle\Provider\PartialDataProviderInterface;
use BatteryIncludedSdk\Dto\ProductBaseDto;

class ArrayProvider implements PartialDataProviderInterface, CollectionAwareDataProviderInterface
{
    /**
     * @var list<ProductBaseDto>
     */
    public array $documents;

    /**
     * @param list<string> $collections collections it delivers to, empty for all
     */
    public function __construct(int $count = 0, int $offset = 1, private readonly array $collections = [])
    {
        $this->documents = [];
        for ($i = $offset; $i < $offset + $count; ++$i) {
            $this->documents[] = self::product((string) $i);
        }
    }

    public static function product(string $id): ProductBaseDto
    {
        $product = new ProductBaseDto($id);
        $product->setId($id);
        $product->setName('Product ' . $id);

        return $product;
    }

    public function getBatches(int $batchSize): \Generator
    {
        foreach (array_chunk($this->documents, $batchSize) as $batch) {
            yield $batch;
        }
    }

    public function getByIds(array $ids): iterable
    {
        return array_filter($this->documents, static fn ($document) => in_array($document->getIdentifier(), $ids, true));
    }

    public function supportsCollection(string $collection): bool
    {
        return $this->collections === [] || in_array($collection, $this->collections, true);
    }
}
