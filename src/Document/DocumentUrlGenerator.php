<?php

declare(strict_types=1);

namespace Batteryincluded\BatteryincludedBundle\Document;

/**
 * Asks the DocumentUrlResolverInterface services for the url of a document, falls back to its `url` field.
 */
class DocumentUrlGenerator
{
    /**
     * @param iterable<DocumentUrlResolverInterface> $resolvers
     */
    public function __construct(private readonly iterable $resolvers = [])
    {
    }

    /**
     * @param array<string, mixed> $data payload of the document (`_<TYPE>`)
     */
    public function generate(string $id, string $type, array $data): ?string
    {
        if ($id !== '') {
            foreach ($this->resolvers as $resolver) {
                $url = $resolver->resolve($id, $type, $data);
                if ($url !== null && $url !== '') {
                    return $url;
                }
            }
        }

        $url = strip_tags((string) ($data['url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * Url of a document as delivered by the api, e.g. a browse hit or a suggestion.
     *
     * @param array<string, mixed> $document
     */
    public function generateForDocument(array $document): ?string
    {
        $type = Document::type($document);

        return $this->generate(Document::id($document, $type), $type, Document::data($document, type: $type));
    }
}
