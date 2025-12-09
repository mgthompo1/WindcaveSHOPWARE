<?php

declare(strict_types=1);

namespace Windcave\Service\Struct;

class WindcaveDropInSession
{
    public function __construct(
        private readonly string $id,
        /** @var array<int,array<string,mixed>> */
        private readonly array $links,
        private readonly ?string $hppUrl = null
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
        * @return array<int,array<string,mixed>>
        */
    public function getLinks(): array
    {
        return $this->links;
    }

    public function getHppUrl(): ?string
    {
        return $this->hppUrl;
    }

    public function asArray(): array
    {
        return [
            'id' => $this->id,
            'links' => $this->links,
            'hppUrl' => $this->hppUrl,
        ];
    }
}
