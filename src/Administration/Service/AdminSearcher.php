<?php declare(strict_types=1);

namespace Shopware\Administration\Service;

use Elasticsearch\Client;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\WildcardQuery;
use ONGR\ElasticsearchDSL\Search;
use Shopware\Administration\Framework\Search\CriteriaCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Tokenizer;
use Shopware\Elasticsearch\Framework\Command\ElasticsearchAdminIndexingCommand;

class AdminSearcher
{
    private DefinitionInstanceRegistry $definitionRegistry;

    private Client $client;

    private Tokenizer $tokenizer;

    /**
     * @internal
     */
    public function __construct(DefinitionInstanceRegistry $definitionRegistry, Client $client, Tokenizer $tokenizer)
    {
        $this->definitionRegistry = $definitionRegistry;
        $this->client = $client;
        $this->tokenizer = $tokenizer;
    }

    public function elastic(string $term, Context $context): array
    {
        $result = [];

        $entities = ['product', 'customer', 'order', 'media', 'cms_page', 'shipping_method', 'payment_method', 'customer_group', 'property_group', 'promotion', 'landing_page', 'product_manufacturer', 'sales_channel'];

        $tokens = $this->tokenizer->tokenize($term);

        foreach ($entities as $entity) {
            if (!$this->definitionRegistry->has($entity)) {
                continue;
            }

            if (!$context->isAllowed($entity . ':' . AclRoleDefinition::PRIVILEGE_READ)) {
                continue;
            }

            $response = $this->client->search([
                'index' => ElasticsearchAdminIndexingCommand::ADMIN_SEARCH_INDEX,
                'track_total_hits' => true,
                'body' => $this->buildEsQuery($tokens, $entity)->toArray(),
            ]);

            $hits = $response['hits']['hits'];
            if (empty($hits)) {
                continue;
            }

            $ids = \array_map(static function(array $hit) {
                return $hit['_source']['id'];
            }, $hits);

            $criteria = new Criteria($ids);
            $repository = $this->definitionRegistry->getRepository($entity);
            $collection = $repository->search($criteria, $context);

            $result[$entity] = [
                'data' => $collection->getEntities(),
                'total' => $collection->getTotal(),
            ];
        }

        return $result;
    }

    public function search(CriteriaCollection $entities, Context $context): array
    {
        $result = [];

        foreach ($entities as $entityName => $criteria) {
            if (!$this->definitionRegistry->has($entityName)) {
                continue;
            }

            if (!$context->isAllowed($entityName . ':' . AclRoleDefinition::PRIVILEGE_READ)) {
                continue;
            }

            $repository = $this->definitionRegistry->getRepository($entityName);
            $collection = $repository->search($criteria, $context);

            $result[$entityName] = [
                'data' => $collection->getEntities(),
                'total' => $collection->getTotal(),
            ];
        }

        return $result;
    }

    private function buildEsQuery(array $tokens, string $entity): Search
    {
        $search = new Search();
        $search->setSize(10);

        $query = new BoolQuery();
        foreach ($tokens as $token) {
            $query->add(new MatchQuery('text', $token), BoolQuery::SHOULD);
            $query->add(new WildcardQuery('text', '*' . $token . '*'), BoolQuery::SHOULD);
        }
        $query->addParameter('minimum_should_match', 1);

        $search->addQuery($query);

        $bool = new BoolQuery();
        $bool->add(new TermQuery('entity_name', $entity), BoolQuery::MUST);
        $search->addQuery($bool, BoolQuery::FILTER);

        return $search;
    }
}
