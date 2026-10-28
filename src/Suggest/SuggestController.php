<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Suggest;

use Batteryincluded\BatteryincludedBundle\BatteryincludedBundle;
use Batteryincluded\BatteryincludedBundle\Http\SearchStructValueResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Json endpoint of the suggestions for the `suggest` Stimulus controller, import
 * `@BatteryincludedBundle/config/routes/suggest.yaml`.
 *
 * - q: the query
 * - locale: defaults to the locale of the request
 * - availability: the request attribute `_batteryincluded_availability` (see the availability configuration), the
 *   visitor cannot choose it
 * - collection: the route default `collection`, the visitor cannot choose it either
 */
class SuggestController
{
    public function __construct(
        private readonly Suggester $suggester,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $locale = (string) $request->query->get('locale', '');
        $locale = preg_match('/^[a-zA-Z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/', $locale) === 1 ? $locale : $request->getLocale();
        $availability = $request->attributes->get(SearchStructValueResolver::AVAILABILITY_ATTRIBUTE);
        $collection = (string) $request->attributes->get('collection', BatteryincludedBundle::DEFAULT_COLLECTION);

        try {
            $result = $this->suggester->suggest(
                (string) $request->query->get('q', ''),
                $locale,
                is_scalar($availability) ? (string) $availability : null,
                $collection,
            );
        } catch (\Throwable $exception) {
            // the message may contain the url with the collection, the visitor gets no details
            $this->logger?->error('BatteryIncluded suggestions failed: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);

            return new JsonResponse(['error' => 'Suggestions are not available.'], Response::HTTP_BAD_GATEWAY);
        }

        // prices stay floats, e.g. 999.0
        $response = new JsonResponse(json_encode($result, JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), json: true);
        // prices and stock depend on the visitor
        $response->setPrivate();
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }
}
