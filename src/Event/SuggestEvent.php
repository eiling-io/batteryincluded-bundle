<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by the Suggester before the suggestions are returned, e.g. to translate the group labels, to format the
 * prices or to add fields of your shop. The suggest endpoint sends the result as json.
 *
 * @phpstan-type Item array{id: string, type: string, title: string, html: string, text: string, image: string, url: string|null, price: float|null, instock: int|null, active: bool|null, data: array<string, mixed>}
 * @phpstan-type Group array{type: string, label: string, items: list<Item>}
 * @phpstan-type Result array{query: string, completions: list<array{value: string, html: string}>, groups: list<Group>, found: int}
 */
class SuggestEvent extends Event
{
    /**
     * @param Result $result
     */
    public function __construct(
        private array $result,
        public readonly string $collection,
        public readonly string $locale,
        public readonly ?string $availability,
    ) {
    }

    /**
     * @return Result
     */
    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * @param Result $result
     */
    public function setResult(array $result): void
    {
        $this->result = $result;
    }
}
