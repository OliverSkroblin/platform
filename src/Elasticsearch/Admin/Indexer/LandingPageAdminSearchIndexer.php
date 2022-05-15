<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin\Indexer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

final class LandingPageAdminSearchIndexer extends AdminSearchIndexer
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
        return LandingPageDefinition::ENTITY_NAME;
    }

    public function getIterator(): IterableQuery
    {
        return $this->factory->createIterator($this->getEntityName(), null, 150);
    }

    public function fetch(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(landing_page.id)) as id',
            'GROUP_CONCAT(landing_page_translation.name) as name',
            'GROUP_CONCAT(tag.name) as tags',
        ]);

        $query->from('landing_page');
        $query->innerJoin('landing_page', 'landing_page_translation', 'landing_page_translation', 'landing_page.id = landing_page_translation.landing_page_id');
        $query->leftJoin('landing_page', 'landing_page_tag', 'landing_page_tag', 'landing_page.id = landing_page_tag.landing_page_id');
        $query->leftJoin('landing_page_tag', 'tag', 'tag', 'landing_page_tag.tag_id = tag.id');
        $query->andWhere('landing_page.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('landing_page.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }
}
