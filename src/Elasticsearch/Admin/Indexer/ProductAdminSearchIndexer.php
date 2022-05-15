<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

final class ProductAdminSearchIndexer extends AdminSearchIndexer
{
    private Connection $connection;

    private IteratorFactory $factory;

    public function __construct(Connection $connection, IteratorFactory $factory)
    {
        $this->connection = $connection;
        $this->factory = $factory;
    }

    public function getDecorated(): AdminSearchIndexer
    {
        throw new DecorationPatternException(self::class);
    }

    public function getEntityName(): string
    {
        return 'product';
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntityName(), null, 150);
    }

    public function fetch(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(product.id)) as id',
            'GROUP_CONCAT(translation.name) as name',
            'GROUP_CONCAT(tag.name) as tags',
            'product.product_number',
            'product.ean',
            'product.manufacturer_number',
        ]);

        $query->from('product');
        $query->innerJoin('product', 'product_translation', 'translation', 'product.id = translation.product_id AND product.version_id = translation.product_version_id');
        $query->leftJoin('product', 'product_tag', 'product_tag', 'product.id = product_tag.product_id AND product.version_id = product_tag.product_version_id');
        $query->leftJoin('product_tag', 'tag', 'tag', 'product_tag.tag_id = tag.id');
        $query->andWhere('product.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('product.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }
}
