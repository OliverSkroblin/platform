<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin;

class AdminSearchIndexingMessage
{
    private string $entity;

    private array $ids;

    public function __construct(string $entity, array $ids)
    {
        $this->entity = $entity;
        $this->ids = $ids;
    }

    public function getEntity(): string
    {
        return $this->entity;
    }

    public function getIds(): array
    {
        return $this->ids;
    }
}
