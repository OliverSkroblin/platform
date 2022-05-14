<?php declare(strict_types=1);

namespace Shopware\Administration\Service;

use Elasticsearch\Client;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\FullText\MatchQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermQuery;
use ONGR\ElasticsearchDSL\Query\TermLevel\TermsQuery;
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

    public function resolveTerm(string $term, string $entity, int $page = 1, int $limit = 50): array
    {
        $tokens = $this->tokenizer->tokenize($term);

        $search = new Search();
        $search->setFrom($limit * ($page - 1));
        $search->setSize($limit);

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

        $response = $this->client->search([
            'index' => ElasticsearchAdminIndexingCommand::ADMIN_SEARCH_INDEX,
            'track_total_hits' => true,
            'body' => $search->toArray(),
        ]);

        $ids = [];
        $total = $response['hits']['total']['value'];

        foreach ($response['hits']['hits'] as $hit) {
            $ids[] = $hit['_source']['id'];
        }

        return ['ids' => $ids, 'total' => $total];
    }

    public function elastic(string $term, array $entities, Context $context, int $limit = 5): array
    {
        $search = $this->buildGlobalSearch($term, $entities, $limit);

        $response = $this->client->search([
            'index' => ElasticsearchAdminIndexingCommand::ADMIN_SEARCH_INDEX,
            'track_total_hits' => true,
            'body' => $search,
        ]);

        $result = [];
        foreach ($response['hits']['hits'] as $hit) {
            $entity = \array_shift($hit['fields']['entity_name']);

            $hits = [];
            foreach ($hit['inner_hits']['hits']['hits']['hits'] as $inner) {
                $hits[] = $inner['_source']['id'];
            }

            $result[$entity] = [
                'ids' => $hits,
                'total' => $hit['inner_hits']['hits']['hits']['total']['value']
            ];
        }

        $mapped = [];
        foreach ($result as $entity => $values) {
            if (!$this->definitionRegistry->has($entity)) {
                continue;
            }
            if (!$context->isAllowed($entity . ':' . AclRoleDefinition::PRIVILEGE_READ)) {
                continue;
            }

            $repository = $this->definitionRegistry->getRepository($entity);

            $collection = $repository->search(new Criteria($values['ids']), $context);

            $mapped[$entity] = [
                'data' => $collection->getEntities(),
                'total' => $values['total']
            ];
        }

        return $mapped;
    }

    private function buildGlobalSearch(string $term, array $entities, int $limit): array
    {
        $tokens = $this->tokenizer->tokenize($term);

        $search = new Search();
        $query = new BoolQuery();
        foreach ($tokens as $token) {
            $query->add(new MatchQuery('text', $token), BoolQuery::SHOULD);
            $query->add(new WildcardQuery('text', '*' . $token . '*'), BoolQuery::SHOULD);
        }
        $query->addParameter('minimum_should_match', 1);
        $search->addQuery($query);

        $bool = new BoolQuery();
        $bool->add(new TermsQuery('entity_name', \array_values($entities)), BoolQuery::MUST);
        $search->addQuery($bool, BoolQuery::FILTER);

        $array = $search->toArray();

        $array['collapse'] = [
            'field' => 'entity_name',
            'inner_hits' => ['name' => 'hits', 'size' => $limit],
        ];

        return $array;
    }
}
