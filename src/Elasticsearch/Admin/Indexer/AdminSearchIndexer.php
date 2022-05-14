<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin\Indexer;

use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;

abstract class AdminSearchIndexer
{
    abstract public function getDecorated(): self;

    abstract public function getEntityName(): string;

    abstract public function getIterator(): IterableQuery;

    abstract public function fetch(array $ids): array;
}
