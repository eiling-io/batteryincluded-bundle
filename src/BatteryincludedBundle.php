<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle;

use Batteryincluded\BatteryincludedBundle\Client\CachingHttpClient;
use Batteryincluded\BatteryincludedBundle\Client\LoggingHttpClient;
use Batteryincluded\BatteryincludedBundle\Client\SymfonyHttpClient;
use Batteryincluded\BatteryincludedBundle\Client\TraceableHttpClient;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Doctrine\DocumentChangeListener;
use Batteryincluded\BatteryincludedBundle\Http\RequestAvailabilityResolver;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use Batteryincluded\BatteryincludedBundle\Profiler\ApiDataCollector;
use Batteryincluded\BatteryincludedBundle\Provider\DataProviderInterface;
use Batteryincluded\BatteryincludedBundle\Scheduler\ExportScheduleProvider;
use Batteryincluded\BatteryincludedBundle\Suggest\Suggester;
use Batteryincluded\BatteryincludedBundle\Test\InMemoryApi;
use BatteryIncludedSdk\CartRecommendations\CartRecommendationsService;
use BatteryIncludedSdk\Client\ApiClient;
use BatteryIncludedSdk\Client\CurlHttpClient;
use BatteryIncludedSdk\Highlights\HighlightsService;
use BatteryIncludedSdk\Presets\PresetsService;
use BatteryIncludedSdk\Recommendations\RecommendationsService;
use BatteryIncludedSdk\Service\SyncService;
use BatteryIncludedSdk\Shop\BrowseService;
use BatteryIncludedSdk\SimilarSearch\SimilarSearchService;
use BatteryIncludedSdk\Suggest\SuggestService;
use Doctrine\ORM\Events;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Schedule;

class BatteryincludedBundle extends AbstractBundle
{
    public const DEFAULT_COLLECTION = 'default';

    public const DEFAULT_BASE_URL = 'https://api.batteryincluded.io/api/v1/collections/';

    /**
     * Services of the sdk which talk to one collection, they are registered once per configured collection.
     */
    private const COLLECTION_SERVICES = [
        BrowseService::class,
        SuggestService::class,
        SyncService::class,
        PresetsService::class,
        HighlightsService::class,
        RecommendationsService::class,
        CartRecommendationsService::class,
        SimilarSearchService::class,
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        // literal values are validated, env placeholders are only known at runtime
        $isInvalidUrl = static fn (mixed $url) => is_string($url) && !str_contains($url, 'env_') && !str_contains($url, '%env(')
            && (filter_var($url, FILTER_VALIDATE_URL) === false || !str_ends_with($url, '/'));

        $definition->rootNode()
            ->children()
                ->scalarNode('base_url')
                    ->info('Base url of the api, the collection is appended')
                    ->defaultValue('%env(resolve:default:batteryincluded.default_base_url:BATTERYINCLUDED_BASE_URL)%')
                    ->cannotBeEmpty()
                    ->validate()->ifTrue($isInvalidUrl)->thenInvalid('The base url %s has to be an absolute url ending with "/".')->end()
                ->end()
                ->scalarNode('collection')
                    ->info('Collection of the default client, e.g. customer.my-shop')
                    ->defaultValue('%env(resolve:BATTERYINCLUDED_COLLECTION)%')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('api_key')
                    ->defaultValue('%env(resolve:BATTERYINCLUDED_API_KEY)%')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('collections')
                    ->info('Additional collections, e.g. one per sales channel. Inject them with `BrowseService $<name>BrowseService` or use --collection=<name> on the commands')
                    ->useAttributeAsKey('name')
                    ->validate()
                        ->ifTrue(static fn (array $collections) => array_filter(array_keys($collections), static fn (string $name) => $name === self::DEFAULT_COLLECTION || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) !== [])
                        ->thenInvalid('Collection names have to match [a-z][a-z0-9_]* and must not be "' . self::DEFAULT_COLLECTION . '", got %s.')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('collection')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('api_key')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('base_url')
                                ->info('Defaults to the base url of the default client')
                                ->defaultNull()
                                ->validate()->ifTrue($isInvalidUrl)->thenInvalid('The base url %s has to be an absolute url ending with "/".')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('http_client')
                    ->info('Service id of a Symfony http client (e.g. "http_client" or a scoped client with retries), null uses curl')
                    ->defaultNull()
                ->end()
                ->booleanNode('in_memory_api')
                    ->info('Replaces the api with the InMemoryApi, for the tests of your application (when@test), see BatteryIncludedAssertionsTrait')
                    ->defaultFalse()
                ->end()
                ->floatNode('write_timeout')
                    ->info('Seconds, idle timeout of the import, update and delete calls with the Symfony http client. The commit of a full import takes longer than a search, searches keep the timeout of the client.')
                    ->defaultValue(300)
                    ->min(1)
                ->end()
                ->arrayNode('curl')
                    ->info('Timeouts of curl, without http_client. Imports, updates and deletes have no limit, the commit of a full import waits for indexing.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->floatNode('timeout')->info('Seconds for a search, suggestion or recommendation, 0 for no limit')->defaultValue(10)->min(0)->end()
                        ->floatNode('connect_timeout')->info('Seconds to establish the connection, 0 for no limit')->defaultValue(5)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->info('Caches GET responses of rarely changing endpoints')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('pool')->defaultValue('cache.app')->cannotBeEmpty()->end()
                        ->integerNode('ttl')->info('Seconds')->defaultValue(300)->min(1)->end()
                        ->arrayNode('endpoints')
                            ->info('Endpoints (path after the collection) which are cached')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/documents/presets', '/documents/highlights', '/documents/recommendations'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->info('Logs failed and slow api calls to the "batteryincluded" channel')
                    ->canBeDisabled()
                    ->children()
                        ->integerNode('slow_threshold')->info('Milliseconds, 0 disables the warning')->defaultValue(1000)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('batch_size')->defaultValue(500)->min(1)->end()
                        ->arrayNode('schedule')
                            ->info('Recurring exports with the Symfony Scheduler, consume them with `messenger:consume scheduler_batteryincluded`')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('cron')->info('Cron expression, e.g. "0 3 * * *" or "@daily"')->isRequired()->cannotBeEmpty()->end()
                                    ->scalarNode('collection')->defaultValue(self::DEFAULT_COLLECTION)->cannotBeEmpty()->end()
                                    ->arrayNode('providers')
                                        ->info('Exports only these providers (class or short class name) without deleting anything, empty for a full import')
                                        ->scalarPrototype()->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('suggest')
                    ->info('Suggestions grouped by document type, used by the suggest endpoint, the Stimulus controller and the demo shop')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('limits')
                            ->info('Documents per type, "default" for all other types')
                            ->useAttributeAsKey('type')
                            ->normalizeKeys(false)
                            ->integerPrototype()->min(0)->end()
                            ->defaultValue(['PRODUCT' => 4, 'default' => 3])
                        ->end()
                        ->arrayNode('order')
                            ->info('Types in this order first, all others in the order of the api')
                            ->scalarPrototype()->end()
                            ->defaultValue(['PRODUCT', 'CONTENT', 'BLOG'])
                        ->end()
                        ->integerNode('completions')->info('Number of query completions')->defaultValue(5)->min(0)->end()
                        ->integerNode('min_length')->info('Shorter queries are not sent')->defaultValue(2)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Syncs entities with #[BatteryIncludedDocument] after flush, requires the DoctrineBundle')
                    ->canBeDisabled()
                ->end()
                ->arrayNode('availability')
                    ->info('Scope of prices and stock per request, stored in the request attribute `_batteryincluded_availability`')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('hosts')
                            ->info('Host => scope, e.g. "shop.ch": ch, "*.shop.ch" matches the subdomains')
                            ->useAttributeAsKey('host')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('locales')
                            ->info('Locale or language => scope, e.g. de_CH: ch, de: de')
                            ->useAttributeAsKey('locale')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->scalarNode('default')->defaultNull()->end()
                    ->end()
                ->end()
                ->arrayNode('search')
                    ->info('Defaults of the BrowseSearchStruct controller argument')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('per_page')->defaultValue(24)->min(1)->end()
                        ->integerNode('max_per_page')->defaultValue(100)->min(1)->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * The Stimulus controllers in assets/dist are available to the AssetMapper as `@batteryincluded/batteryincluded-bundle`.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!interface_exists(AssetMapperInterface::class) || !$builder->hasExtension('framework')) {
            return;
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => ['paths' => [dirname(__DIR__) . '/assets/dist' => '@batteryincluded/batteryincluded-bundle']],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // the sdk is located by its classes, the vendor directory is not always below the project dir (e.g. plugin test applications)
        $builder->setParameter('batteryincluded.sdk_dir', dirname((string) (new \ReflectionClass(ApiClient::class))->getFileName(), 2));
        $container->import(__DIR__ . '/../config/services.yaml');

        // the demo shop renders twig templates
        if (isset($builder->getParameter('kernel.bundles')['TwigBundle'])) {
            $container->import(__DIR__ . '/../config/services_demo.yaml');
        }

        if (interface_exists(MessageBusInterface::class)) {
            $container->import(__DIR__ . '/../config/services_messenger.yaml');
        }

        $builder->setParameter('batteryincluded.default_base_url', self::DEFAULT_BASE_URL);
        $builder->setParameter('batteryincluded.export.batch_size', $config['export']['batch_size']);

        if ($config['doctrine']['enabled'] && class_exists(Events::class) && isset($builder->getParameter('kernel.bundles')['DoctrineBundle'])) {
            $listener = $builder->register(DocumentChangeListener::class, DocumentChangeListener::class)->setAutowired(true);
            foreach ([Events::preFlush, Events::postPersist, Events::postUpdate, Events::preRemove, Events::postRemove, Events::postFlush, Events::onClear] as $event) {
                $listener->addTag('doctrine.event_listener', ['event' => $event]);
            }
        }

        $this->registerHttpClient($config, $builder);
        $this->registerCollections($config, $builder);

        $builder->getDefinition(RequestAvailabilityResolver::class)
            ->setArgument('$hosts', $config['availability']['hosts'])
            ->setArgument('$locales', $config['availability']['locales'])
            ->setArgument('$default', $config['availability']['default']);

        $builder->getDefinition(SearchStructValueResolver::class)
            ->setArgument('$defaultPerPage', $config['search']['per_page'])
            ->setArgument('$maxPerPage', $config['search']['max_per_page']);

        $this->registerSuggest($config, $builder);
        $this->registerSchedule($config, $builder);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSuggest(array $config, ContainerBuilder $builder): void
    {
        // types are upper case like the id prefix, the defaults are kept for types which are not configured
        $limits = ['PRODUCT' => 4, 'default' => 3];
        foreach ($config['suggest']['limits'] as $type => $limit) {
            $limits[$type === 'default' ? 'default' : mb_strtoupper((string) $type)] = $limit;
        }

        $builder->getDefinition(Suggester::class)
            ->setArgument('$limits', $limits)
            ->setArgument('$order', array_values(array_map(static fn ($type) => mb_strtoupper((string) $type), $config['suggest']['order'])))
            ->setArgument('$completions', $config['suggest']['completions'])
            ->setArgument('$minLength', $config['suggest']['min_length']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSchedule(array $config, ContainerBuilder $builder): void
    {
        $exports = $config['export']['schedule'];
        if ($exports === []) {
            return;
        }

        if (!class_exists(Schedule::class) || !interface_exists(MessageBusInterface::class)) {
            throw new \LogicException('batteryincluded.export.schedule requires the Symfony Scheduler, run "composer require symfony/scheduler".');
        }

        $collections = array_merge([self::DEFAULT_COLLECTION], array_keys($config['collections']));
        foreach ($exports as $export) {
            if (!in_array($export['collection'], $collections, true)) {
                throw new \LogicException(sprintf('The scheduled export uses the unknown collection "%s", configured are: %s.', $export['collection'], implode(', ', $collections)));
            }
        }

        $builder->register(ExportScheduleProvider::class, ExportScheduleProvider::class)
            ->setArgument('$exports', array_map(static fn (array $export) => ['collection' => $export['collection'], 'cron' => $export['cron'], 'providers' => array_values($export['providers'])], array_values($exports)))
            ->addTag('scheduler.schedule_provider', ['name' => ExportScheduleProvider::NAME]);
    }

    /**
     * TraceableHttpClient (profiler) → CachingHttpClient → LoggingHttpClient → curl, Symfony http client or InMemoryApi.
     *
     * @param array<string, mixed> $config
     */
    private function registerHttpClient(array $config, ContainerBuilder $builder): void
    {
        if ($config['in_memory_api']) {
            $builder->register(InMemoryApi::class, InMemoryApi::class)->setPublic(true);
        }

        $builder->getDefinition(CurlHttpClient::class)
            ->setArgument('$readTimeout', $config['curl']['timeout'])
            ->setArgument('$connectTimeout', $config['curl']['connect_timeout']);

        $transport = match (true) {
            $config['in_memory_api'] => new Reference(InMemoryApi::class),
            $config['http_client'] === null => new Reference(CurlHttpClient::class),
            default => (new Definition(SymfonyHttpClient::class))
                ->setArgument('$httpClient', new Reference($config['http_client']))
                ->setArgument('$writeTimeout', $config['write_timeout']),
        };
        $client = $transport;

        if ($config['logging']['enabled']) {
            $client = (new Definition(LoggingHttpClient::class))
                ->setArgument('$httpClient', $client)
                ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setArgument('$slowThreshold', $config['logging']['slow_threshold'])
                ->addTag('monolog.logger', ['channel' => 'batteryincluded']);
        }

        if ($config['cache']['enabled']) {
            $client = (new Definition(CachingHttpClient::class))
                ->setArgument('$httpClient', $client)
                ->setArgument('$cache', new Reference($config['cache']['pool']))
                ->setArgument('$ttl', $config['cache']['ttl'])
                ->setArgument('$endpoints', $config['cache']['endpoints']);
        }

        $builder->register(TraceableHttpClient::class, TraceableHttpClient::class)
            ->setArgument('$httpClient', $client)
            ->setArgument('$stopwatch', new Reference('debug.stopwatch', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
            ->addTag('kernel.reset', ['method' => 'reset']);
    }

    /**
     * One ApiClient plus the sdk services per collection. The default collection uses the class names as service ids,
     * further collections are available as `<name>BrowseService` etc. for autowiring and in the CollectionRegistry.
     *
     * @param array<string, mixed> $config
     */
    private function registerCollections(array $config, ContainerBuilder $builder): void
    {
        $collections = [self::DEFAULT_COLLECTION => ['base_url' => $config['base_url'], 'collection' => $config['collection'], 'api_key' => $config['api_key']]];
        foreach ($config['collections'] as $name => $collection) {
            $collections[$name] = $collection + ['base_url' => null];
            $collections[$name]['base_url'] ??= $config['base_url'];
        }

        $locator = [];
        $collectionConfig = [];
        foreach ($collections as $name => $collection) {
            $isDefault = $name === self::DEFAULT_COLLECTION;
            $apiClientId = $isDefault ? ApiClient::class : 'batteryincluded.collection.' . $name . '.api_client';
            $builder->register($apiClientId, ApiClient::class)
                ->setArgument('$httpClient', new Reference(TraceableHttpClient::class))
                ->setArgument('$baseUrl', $collection['base_url'])
                ->setArgument('$collection', $collection['collection'])
                ->setArgument('$apiKey', $collection['api_key']);

            $locator[$name . '|' . ApiClient::class] = new Reference($apiClientId);
            foreach (self::COLLECTION_SERVICES as $class) {
                $serviceId = $class;
                if (!$isDefault) {
                    $shortName = mb_substr($class, (int) mb_strrpos($class, '\\') + 1);
                    $serviceId = 'batteryincluded.collection.' . $name . '.' . lcfirst($shortName);
                    $builder->register($serviceId, $class)->setArgument('$apiClient', new Reference($apiClientId));
                    $builder->registerAliasForArgument($serviceId, $class, $name . $shortName);
                }
                $locator[$name . '|' . $class] = new Reference($serviceId);
            }

            $collectionConfig[$name] = ['baseUrl' => $collection['base_url'], 'collection' => $collection['collection'], 'apiKey' => $collection['api_key']];
        }

        $builder->register(CollectionRegistry::class, CollectionRegistry::class)
            ->setArgument('$locator', new ServiceLocatorArgument($locator))
            ->setArgument('$collections', $collectionConfig)
            ->setArgument('$providers', new TaggedIteratorArgument(DataProviderInterface::class));

        $builder->getDefinition(ApiDataCollector::class)->setArgument('$collections', new ServiceClosureArgument(new Reference(CollectionRegistry::class)));
    }
}
