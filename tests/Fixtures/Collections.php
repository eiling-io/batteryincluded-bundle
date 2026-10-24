<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundleTests\Fixtures;

use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use BatteryIncludedSdk\Client\ApiClient;
use BatteryIncludedSdk\Client\HttpClientInterface;
use BatteryIncludedSdk\Service\SyncService;
use BatteryIncludedSdk\Shop\BrowseService;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Registry with the collections `default` and `ch`, all calls go to the given http client.
 */
final class Collections
{
    /**
     * @param list<DataProviderInterface> $providers
     */
    public static function registry(HttpClientInterface $httpClient, array $providers = []): CollectionRegistry
    {
        $configs = [
            'default' => ['baseUrl' => 'https://api.test/collections/', 'collection' => 'customer.test', 'apiKey' => 'secret-default'],
            'ch' => ['baseUrl' => 'https://api.test/collections/', 'collection' => 'customer.test-ch', 'apiKey' => 'secret-ch'],
        ];

        $factories = [];
        foreach ($configs as $name => $config) {
            $apiClient = static fn () => new ApiClient($httpClient, $config['baseUrl'], $config['collection'], $config['apiKey']);
            $factories[$name . '|' . ApiClient::class] = $apiClient;
            $factories[$name . '|' . SyncService::class] = static fn () => new SyncService($apiClient());
            $factories[$name . '|' . BrowseService::class] = static fn () => new BrowseService($apiClient());
        }

        return new CollectionRegistry(new ServiceLocator($factories), $configs, $providers);
    }
}
