<?php declare(strict_types=1);

namespace Shopware\Administration\Service;

use Elasticsearch\Client;
use Shopware\Administration\Framework\Search\CriteriaCollection;
use Shopware\Core\Framework\Api\Acl\Role\AclRoleDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Tokenizer;

class AdminSearcher
{
    private DefinitionInstanceRegistry $definitionRegistry;

    /**
     * @internal
     */
    public function __construct(DefinitionInstanceRegistry $definitionRegistry, Client $client, Tokenizer $tokenizer)
    {
        $this->definitionRegistry = $definitionRegistry;
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
}
