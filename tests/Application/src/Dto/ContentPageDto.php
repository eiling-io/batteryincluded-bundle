<?php

namespace App\Dto;

use BatteryIncludedSdk\Dto\AbstractTranslatableDto;

/**
 * Own document type for content pages (shipping, returns, about us, …), exported with the id `CONTENT-<identifier>`
 * and the fields in `_CONTENT` and `_CONTENT_i18n`.
 *
 * The fields have to be added to the schema of the collection in the BatteryIncluded backend to be searchable.
 */
class ContentPageDto extends AbstractTranslatableDto
{
    private ?string $title = null;

    private ?string $teaser = null;

    private ?string $content = null;

    private ?string $url = null;

    private ?bool $active = null;

    public function __construct(string $identifier, string $type = 'CONTENT')
    {
        parent::__construct($identifier, $type);
    }

    // the status is the same in every language
    protected function getTranslatableFields(): ?array
    {
        return ['title', 'teaser', 'content', 'url'];
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTeaser(): ?string
    {
        return $this->teaser;
    }

    public function setTeaser(?string $teaser): static
    {
        $this->teaser = $teaser;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(?bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function jsonSerialize(): array
    {
        $json = parent::jsonSerialize();
        $json['_' . $this->getType()] = $this->filterJsonValues([
            'title' => $this->getTitle(),
            'teaser' => $this->getTeaser(),
            'content' => $this->getContent(),
            'url' => $this->getUrl(),
            'active' => $this->getActive(),
        ]);

        return $json;
    }
}
