<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Suggest;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Collection\CollectionRegistry;
use Batteryincluded\BatteryincludedBundle\Document\Document;
use Batteryincluded\BatteryincludedBundle\Document\DocumentUrlGenerator;
use Batteryincluded\BatteryincludedBundle\Event\SuggestEvent;
use Batteryincluded\BatteryincludedBundle\Exception\ApiException;
use BatteryIncludedSdk\Suggest\SuggestResponse;
use BatteryIncludedSdk\Suggest\SuggestSearchStruct;
use BatteryIncludedSdk\Suggest\SuggestService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Suggestions grouped by document type: products, content pages, blog posts and your own types.
 *
 * Used by the suggest endpoint (`@BatteryincludedBundle/config/routes/suggest.yaml`) and the demo shop, call it from
 * your own controller to render the suggestions yourself.
 *
 * @phpstan-import-type Item from SuggestEvent
 * @phpstan-import-type Result from SuggestEvent
 */
class Suggester
{
    /**
     * @param array<string, int> $limits      documents per type, `default` for all other types
     * @param list<string>       $order       types in this order first, all others in the order of the api
     * @param int                $completions number of query completions
     * @param int                $minLength   shorter queries are not sent
     */
    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly DocumentUrlGenerator $urlGenerator,
        private readonly array $limits = ['PRODUCT' => 4, 'default' => 3],
        private readonly array $order = ['PRODUCT', 'CONTENT', 'BLOG'],
        private readonly int $completions = 5,
        private readonly int $minLength = 2,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    /**
     * @param string                       $locale       e.g. `de`, the gateway resolves the translations of it
     * @param string|null                  $availability scope of prices and stock, e.g. the country
     * @param array<string, list<string>>  $filters      e.g. `['type' => ['PRODUCT']]`
     *
     * @throws ApiException
     *
     * @return Result
     */
    public function suggest(string $query, string $locale, ?string $availability = null, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, array $filters = []): array
    {
        $query = mb_trim($query);
        if (mb_strlen($query) < $this->minLength) {
            return ['query' => $query, 'completions' => [], 'groups' => [], 'found' => 0];
        }

        $searchStruct = new SuggestSearchStruct();
        $searchStruct->setQuery($query);
        $searchStruct->setLocale($locale);
        $searchStruct->setAvailability($availability);
        $searchStruct->addFilters($filters);

        $response = $this->collections->get($collection, SuggestService::class)->suggestWithFilter($searchStruct);
        ApiException::assertSuccessful($response, 'Suggest');

        return $this->build($response, $query, $locale, $availability, $collection);
    }

    /**
     * Groups the documents of a suggest response.
     *
     * @param (callable(string $id, string $type, array<string, mixed> $data): ?string)|null $url url of a document, defaults to the DocumentUrlResolverInterface services
     *
     * @return Result
     */
    public function build(SuggestResponse $response, string $query, string $locale, ?string $availability = null, string $collection = BatteryincludedBundle::DEFAULT_COLLECTION, ?callable $url = null): array
    {
        $completions = [];
        foreach (array_slice($response->getQueryCompletions(), 0, $this->completions) as $completion) {
            $completions[] = [
                'value' => $completion->getValue(),
                'html' => Document::highlight($completion->getHighlighted() !== '' ? $completion->getHighlighted() : $completion->getValue()),
            ];
        }

        // the documents contain only the fields selected under Suggest in the portal
        $groups = [];
        foreach ($response->getDocuments() as $document) {
            if (!is_array($document)) {
                continue;
            }
            $type = Document::type($document);
            if (count($groups[$type] ?? []) < ($this->limits[$type] ?? $this->limits['default'] ?? 3)) {
                $groups[$type][] = $this->item($document, $type, $availability, $url);
            }
        }

        $position = array_flip($this->order);
        uksort($groups, static fn (string $a, string $b) => ($position[$a] ?? PHP_INT_MAX) <=> ($position[$b] ?? PHP_INT_MAX));

        $result = [
            'query' => $query,
            'completions' => $completions,
            'groups' => array_map(static fn (string $type, array $items) => ['type' => $type, 'label' => $type, 'items' => $items], array_keys($groups), array_values($groups)),
            'found' => $response->getFounds(),
        ];

        if ($this->eventDispatcher !== null) {
            $event = new SuggestEvent($result, $collection, $locale, $availability);
            $this->eventDispatcher->dispatch($event);
            $result = $event->getResult();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return Item
     */
    private function item(array $document, string $type, ?string $availability, ?callable $url): array
    {
        $data = Document::data($document, $availability, $type);
        $id = Document::id($document, $type);
        $title = (string) ($data['name'] ?? $data['title'] ?? '');
        $text = (string) ($data['teaser'] ?? $data['shortDescription'] ?? $data['ordernumber'] ?? '');
        $plain = static fn (mixed $value) => is_scalar($value) ? strip_tags((string) $value) : null;

        return [
            'id' => $id,
            'type' => $type,
            'title' => strip_tags($title),
            'html' => Document::highlight($title),
            'text' => Document::highlight($text),
            'image' => (string) $plain($data['imageUrl'] ?? $data['previewImage'] ?? ''),
            'url' => $url !== null ? $url($id, $type, $data) : $this->urlGenerator->generate($id, $type, $data),
            'price' => isset($data['price']) && is_numeric($plain($data['price'])) ? (float) $plain($data['price']) : null,
            'instock' => isset($data['instock']) && is_numeric($plain($data['instock'])) ? (int) $plain($data['instock']) : null,
            'active' => isset($data['active']) ? in_array($plain($data['active']), ['1', 'true'], true) : null,
            'data' => $data,
        ];
    }
}
