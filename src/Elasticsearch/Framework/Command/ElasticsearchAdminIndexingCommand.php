<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Framework\Command;

use Doctrine\DBAL\Connection;
use Elasticsearch\Client;
use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\DataAbstractionLayer\Command\ConsoleProgressTrait;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Elasticsearch\Exception\ElasticsearchIndexingException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ElasticsearchAdminIndexingCommand extends Command
{
    use ConsoleProgressTrait;

    public const ADMIN_SEARCH_INDEX = 'admin_search';

    protected static $defaultName = 'es:admin:index';

    private Client $client;

    private IteratorFactory $factory;
    private Connection $connection;

    /**
     * @internal
     */
    public function __construct(Client $client, IteratorFactory $factory, Connection $connection)
    {
        parent::__construct();
        $this->client = $client;
        $this->factory = $factory;
        $this->connection = $connection;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Index the elasticsearch for the admin search');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new ShopwareStyle($input, $output);

        if ($this->indexExists()) {
            $this->client->indices()->delete(['index' => self::ADMIN_SEARCH_INDEX]);
        }

        if (!$this->indexExists()) {
            $this->createIndex();
        }

        $this->loop('product', function($ids) {
            return $this->fetchProducts($ids);
        });

        $this->loop('customer', function($ids) {
            return $this->fetchCustomers($ids);
        });

        $this->loop('order', function($ids) {
            return $this->fetchOrders($ids);
        });

        $this->loop('media', function($ids) {
            return $this->fetchMedia($ids);
        });

        $this->loop('cms_page', function($ids) {
            return $this->fetchCmsPages($ids);
        });

        $this->loop('shipping_method', function($ids) {
            return $this->fetchShippingMethods($ids);
        });

        $this->loop('payment_method', function($ids) {
            return $this->fetchPaymentMethods($ids);
        });

        $this->loop('customer_group', function($ids) {
            return $this->fetchCustomerGroups($ids);
        });

        $this->loop('property_group', function($ids) {
            return $this->fetchPropertyGroups($ids);
        });

        $this->loop('promotion', function($ids) {
            return $this->fetchPromotions($ids);
        });

        $this->loop('landing_page', function($ids) {
            return $this->fetchLandingPages($ids);
        });

        $this->loop('product_manufacturer', function($ids) {
            return $this->fetchProductManufacturers($ids);
        });

        $this->loop('sales_channel', function($ids) {
            return $this->fetchSalesChannels($ids);
        });

        return self::SUCCESS;
    }

    private function indexExists(): bool
    {
        return $this->client->indices()->exists(['index' => self::ADMIN_SEARCH_INDEX]);
    }

    private function createIndex(): void
    {
        $mapping = [
            'properties' => [
                'id' => ['type' => 'keyword'],
                'entity_id' => ['type' => 'keyword'],
                'entity_name' => ['type' => 'keyword'],
                'text' => ['type' => 'text']
            ]
        ];

        $this->client->indices()->create([
            'index' => self::ADMIN_SEARCH_INDEX,
            'body' => ['mappings' => $mapping]
        ]);
    }

    private function loop(string $entity, \Closure $data)
    {
        $iterator = $this->factory->createIterator($entity);

        $this->progress = $this->io->createProgressBar($iterator->fetchCount());
        $this->progress->setFormat("<info>[%message%]</info>\n%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%");
        $this->progress->setMessage('Start indexing ' . $entity);

        while ($ids = $iterator->fetch()) {
            $values = $data($ids);

            $this->push($entity, $values, $ids);

            $this->progress->advance(count($ids));
        }

        $this->progress->setMessage('Finish indexing ' . $entity);
        $this->progress->finish();
        $this->io->newLine(2);
    }

    private function push(string $type, array $data, array $ids): void
    {
        $toRemove = array_filter($ids, static fn (string $id) => !isset($data[$id]));

        $documents = [];
        foreach ($data as $id => $document) {
            $documents[] = ['index' => ['_id' => self::id($type, $id)]];
            $document['entity_name'] = $type;
            $documents[] = $document;
        }

        foreach ($toRemove as $id) {
            $documents[] = ['delete' => ['_id' => self::id($type, $id)]];
        }

        $arguments = [
            'index' => self::ADMIN_SEARCH_INDEX,
            'body' => $documents,
        ];

        $result = $this->client->bulk($arguments);

        if (\is_array($result) && isset($result['errors']) && $result['errors']) {
            $errors = $this->parseErrors($result);

            throw new ElasticsearchIndexingException($errors);
        }
    }

    private static function id(string $type, string $id): string
    {
        return $type . '_' . $id;
    }

    private function parseErrors(array $result): array
    {
        $errors = [];
        foreach ($result['items'] as $item) {
            $item = $item['index'] ?? $item['delete'];

            if (\in_array($item['status'], [200, 201], true)) {
                continue;
            }

            $errors[] = [
                'index' => $item['_index'],
                'id' => $item['_id'],
                'type' => $item['error']['type'] ?? $item['_type'],
                'reason' => $item['error']['reason'] ?? $item['result'],
            ];
        }

        return $errors;
    }

    private function fetchProducts(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(product.id)) as id',
            'GROUP_CONCAT(translation.name) as name',
            'GROUP_CONCAT(tag.name) as tags',
            'product.product_number',
            'product.ean',
            'product.manufacturer_number'
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

    private function fetchCustomers($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(customer.id)) as id',
            'GROUP_CONCAT(tag.name) as tags',
            'GROUP_CONCAT(country_translation.name) as country',
            'GROUP_CONCAT(customer_address.city) as city',
            'GROUP_CONCAT(customer_address.zipcode) as zipcode',
            'GROUP_CONCAT(customer_address.street) as street',
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'customer.company',
            'customer.customer_number',
        ]);

        $query->from('customer');
        $query->leftJoin('customer', 'customer_address', 'customer_address', 'customer.id = customer_address.customer_id');
        $query->leftJoin('customer_address', 'country', 'country', 'customer_address.country_id = country.id');
        $query->leftJoin('country', 'country_translation', 'country_translation', 'country.id = country_translation.country_id');
        $query->leftJoin('customer', 'customer_tag', 'customer_tag', 'customer.id = customer_tag.customer_id');
        $query->leftJoin('customer_tag', 'tag', 'tag', 'customer_tag.tag_id = tag.id');
        $query->groupBy('customer.id');

        $query->andWhere('customer.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchOrders($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(`order`.id)) as id',
            'GROUP_CONCAT(tag.name) as tags',
            'GROUP_CONCAT(country_translation.name) as country',
            'GROUP_CONCAT(order_address.city) as city',
            'GROUP_CONCAT(order_address.zipcode) as zipcode',
            'GROUP_CONCAT(order_address.street) as street',
            '`order_customer`.first_name',
            '`order_customer`.last_name',
            '`order_customer`.email',
            '`order_customer`.company',
            '`order_customer`.customer_number',
            '`order`.order_number',
        ]);

        $query->from('`order`');
        $query->leftJoin('`order`', 'order_customer', 'order_customer', '`order`.id = order_customer.order_id');
        $query->leftJoin('`order`', 'order_address', 'order_address', '`order`.id = order_address.order_id');
        $query->leftJoin('order_address', 'country', 'country', 'order_address.country_id = country.id');
        $query->leftJoin('country', 'country_translation', 'country_translation', 'country.id = country_translation.country_id');
        $query->leftJoin('`order`', 'order_tag', 'order_tag', '`order`.id = order_tag.order_id');
        $query->leftJoin('order_tag', 'tag', 'tag', 'order_tag.tag_id = tag.id');
        $query->groupBy('`order`.id');

        $query->andWhere('`order`.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchMedia($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(media.id)) as id',
            'media.file_name',
            'GROUP_CONCAT(media_translation.alt) as alt',
            'GROUP_CONCAT(media_translation.title) as title',
            'media_folder.name',
            'GROUP_CONCAT(tag.name) as tags',
        ]);

        $query->from('media');
        $query->innerJoin('media', 'media_translation', 'media_translation', 'media.id = media_translation.media_id');
        $query->leftJoin('media', 'media_folder', 'media_folder', 'media.media_folder_id = media_folder.id');
        $query->leftJoin('media', 'media_tag', 'media_tag', 'media.id = media_tag.media_id');
        $query->leftJoin('media_tag', 'tag', 'tag', 'media_tag.tag_id = tag.id');
        $query->andWhere('media.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('media.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchCmsPages(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(cms_page.id)) as id',
            'GROUP_CONCAT(cms_page_translation.name) as name',
        ]);

        $query->from('cms_page');
        $query->innerJoin('cms_page', 'cms_page_translation', 'cms_page_translation', 'cms_page.id = cms_page_translation.cms_page_id');
        $query->andWhere('cms_page.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('cms_page.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchShippingMethods(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(shipping_method.id)) as id',
            'GROUP_CONCAT(shipping_method_translation.name) as name',
        ]);

        $query->from('shipping_method');
        $query->innerJoin('shipping_method', 'shipping_method_translation', 'shipping_method_translation', 'shipping_method.id = shipping_method_translation.shipping_method_id');
        $query->andWhere('shipping_method.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('shipping_method.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchPaymentMethods(array $ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(payment_method.id)) as id',
            'GROUP_CONCAT(payment_method_translation.name) as name',
        ]);

        $query->from('payment_method');
        $query->innerJoin('payment_method', 'payment_method_translation', 'payment_method_translation', 'payment_method.id = payment_method_translation.payment_method_id');
        $query->andWhere('payment_method.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('payment_method.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchCustomerGroups($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(customer_group.id)) as id',
            'GROUP_CONCAT(customer_group_translation.name) as name',
        ]);

        $query->from('customer_group');
        $query->innerJoin('customer_group', 'customer_group_translation', 'customer_group_translation', 'customer_group.id = customer_group_translation.customer_group_id');
        $query->andWhere('customer_group.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('customer_group.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchPropertyGroups($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(property_group.id)) as id',
            'GROUP_CONCAT(property_group_translation.name) as name',
        ]);

        $query->from('property_group');
        $query->innerJoin('property_group', 'property_group_translation', 'property_group_translation', 'property_group.id = property_group_translation.property_group_id');
        $query->andWhere('property_group.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('property_group.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchPromotions($ids)
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(promotion.id)) as id',
            'GROUP_CONCAT(promotion_translation.name) as name',
        ]);

        $query->from('promotion');
        $query->innerJoin('promotion', 'promotion_translation', 'promotion_translation', 'promotion.id = promotion_translation.promotion_id');
        $query->andWhere('promotion.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('promotion.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchLandingPages($ids): array
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

    private function fetchProductManufacturers($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(product_manufacturer.id)) as id',
            'GROUP_CONCAT(product_manufacturer_translation.name) as name',
        ]);

        $query->from('product_manufacturer');
        $query->innerJoin('product_manufacturer', 'product_manufacturer_translation', 'product_manufacturer_translation', 'product_manufacturer.id = product_manufacturer_translation.product_manufacturer_id');
        $query->andWhere('product_manufacturer.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('product_manufacturer.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }

    private function fetchSalesChannels($ids): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select([
            'LOWER(HEX(sales_channel.id)) as id',
            'GROUP_CONCAT(sales_channel_translation.name) as name',
        ]);

        $query->from('sales_channel');
        $query->innerJoin('sales_channel', 'sales_channel_translation', 'sales_channel_translation', 'sales_channel.id = sales_channel_translation.sales_channel_id');
        $query->andWhere('sales_channel.id IN (:ids)');
        $query->setParameter('ids', Uuid::fromHexToBytesList($ids), Connection::PARAM_STR_ARRAY);
        $query->groupBy('sales_channel.id');

        $data = $query->execute()->fetchAll();

        $mapped = [];
        foreach ($data as $row) {
            $id = $row['id'];
            $mapped[$id] = ['id' => $id, 'text' => \implode(' ', $row)];
        }

        return $mapped;
    }
}
