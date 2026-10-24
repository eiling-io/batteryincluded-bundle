<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Selects the availability scope (prices and stock of a country or sales channel) of a request from the
 * `batteryincluded.availability` configuration: host, then locale (`de_CH`, then `de`), then the default.
 */
class RequestAvailabilityResolver
{
    /**
     * @param array<string, string> $hosts   host => scope, `*.example.ch` matches all subdomains
     * @param array<string, string> $locales locale or language => scope
     */
    public function __construct(
        private readonly array $hosts = [],
        private readonly array $locales = [],
        private readonly ?string $default = null,
    ) {
    }

    public function resolve(Request $request): ?string
    {
        $attribute = $request->attributes->get(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE);
        if (is_scalar($attribute) && $attribute !== '') {
            return (string) $attribute;
        }

        $host = mb_strtolower($request->getHost());
        foreach ($this->hosts as $pattern => $scope) {
            $pattern = mb_strtolower((string) $pattern);
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, mb_substr($pattern, 1)))) {
                return $scope;
            }
        }

        $locales = array_change_key_case(array_combine(array_map(static fn ($locale) => str_replace('-', '_', (string) $locale), array_keys($this->locales)), $this->locales));
        $locale = mb_strtolower(str_replace('-', '_', $request->getLocale()));
        $language = explode('_', $locale)[0];

        return $locales[$locale] ?? $locales[$language] ?? $this->default;
    }
}
