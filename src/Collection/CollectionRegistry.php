<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Collection;

use Batteryincluded\BatteryincludedBundle\Provider\CollectionAwareDataProviderInterface;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Psr\Container\ContainerInterface;

/**
 * Configured collections (`default` plus `batteryincluded.collections`) with their sdk services and data providers.
 */
class CollectionRegistry
{
    /**
     * @param array<string, array{baseUrl: string, collection: string, apiKey: string}> $collections
     * @param iterable<DataProviderInterface>                                            $providers
     */
    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly array $collections,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->collections);
    }

    /**
     * @return array{baseUrl: string, collection: string, apiKey: string}
     */
    public function getConfig(string $name): array
    {
        $this->assertExists($name);

        return $this->collections[$name];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class e.g. BrowseService::class
     *
     * @return T
     */
    public function get(string $name, string $class): object
    {
        $this->assertExists($name);

        return $this->locator->get($name . '|' . $class);
    }

    /**
     * Providers which deliver documents for the collection, all of them unless they implement CollectionAwareDataProviderInterface.
     *
     * @return list<DataProviderInterface>
     */
    public function getProviders(string $name): array
    {
        $this->assertExists($name);

        $providers = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof CollectionAwareDataProviderInterface || $provider->supportsCollection($name)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    private function assertExists(string $name): void
    {
        if (!isset($this->collections[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown collection "%s", configured are: %s.', $name, implode(', ', array_keys($this->collections))));
        }
    }
}
