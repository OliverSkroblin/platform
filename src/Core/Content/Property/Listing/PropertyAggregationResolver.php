<?php declare(strict_types=1);

namespace Shopware\Core\Content\Property\Listing;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\SalesChannel\Listing\FilterCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

class PropertyAggregationResolver extends AbstractPropertyAggregationResolver
{
    private Connection $connection;

    private EntityRepositoryInterface $optionRepository;

    private EntityRepositoryInterface $groupRepository;

    private bool $onDemand;

    public function __construct(Connection $connection, EntityRepositoryInterface $optionRepository, EntityRepositoryInterface $groupRepository)
    {
        $this->connection = $connection;
        $this->optionRepository = $optionRepository;
        $this->onDemand = true;
        $this->groupRepository = $groupRepository;
    }

    public function getDecorated(): AbstractPropertyAggregationResolver
    {
        throw new DecorationPatternException(self::class);
    }

    public function resolve(EntitySearchResult $result, Context $context): void
    {
        $ids = $this->collectOptionIds($result);

        if (empty($ids)) {
            return;
        }

        if ($this->onDemand) {
            $this->loadGroups($ids, $result, $context);

            return;
        }

        $this->loadAll($ids, $result, $context);
    }

    private function collectOptionIds(EntitySearchResult $result): array
    {
        $aggregations = $result->getAggregations();

        /** @var TermsResult|null $properties */
        $properties = $aggregations->get('properties');

        /** @var TermsResult|null $options */
        $options = $aggregations->get('options');

        $options = $options ? $options->getKeys() : [];
        $properties = $properties ? $properties->getKeys() : [];

        return array_unique(array_filter(array_merge($options, $properties)));
    }

    private function loadAll(array $ids, EntitySearchResult $result, Context $context): void
    {
        $criteria = new Criteria($ids);
        $criteria->setLimit(500);
        $criteria->addAssociation('group');
        $criteria->addAssociation('media');
        $criteria->addFilter(new EqualsFilter('group.filterable', true));
        $criteria->setTitle('product-listing::property-filter');
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));

        $merged = new PropertyGroupOptionCollection();

        $repositoryIterator = new RepositoryIterator($this->optionRepository, $context, $criteria);
        while (($loop = $repositoryIterator->fetch()) !== null) {
            $merged->merge($loop->getEntities());
        }

        // group options by their property-group
        $grouped = $merged->groupByPropertyGroups();
        $grouped->sortByPositions();
        $grouped->sortByConfig();

        $aggregations = $result->getAggregations();

        // remove id results to prevent wrong usages
        $aggregations->remove('properties');
        $aggregations->remove('configurators');
        $aggregations->remove('options');
        $aggregations->add(new EntityResult('properties', $grouped));
    }

    private function loadGroups(array $ids, EntitySearchResult $result, Context $context): void
    {
        $tmp = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), LOWER(HEX(property_group_id)) FROM property_group_option WHERE id IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => Connection::PARAM_STR_ARRAY]
        );

        $mapping = [];
        foreach ($tmp as $optionId => $groupId) {
            if (!isset($mapping[$groupId])) {
                $mapping[$groupId] = [];
            }

            $mapping[$groupId][] = $optionId;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('options.id', $ids));
        $criteria->addFilter(new EqualsFilter('filterable', true));

        $this->addSelected($result, $criteria);

        $criteria->setTitle('product-listing::property-group-filter');

        /** @var PropertyGroupCollection $groups */
        $groups = $this->groupRepository->search($criteria, $context)->getEntities();
        $groups->sortByPositions();

        foreach ($groups as $group) {
            $group->setListingOptionIds($mapping[$group->getId()] ?? []);
        }

        $aggregations = $result->getAggregations();

        // remove id results to prevent wrong usages
        $aggregations->remove('properties');
        $aggregations->remove('configurators');
        $aggregations->remove('options');
        $aggregations->add(new EntityResult('properties', $groups));
    }

    private function addSelected(EntitySearchResult $result, Criteria $criteria): void
    {
        $filters = $result->getCriteria()->getExtension('filters');
        if (!$filters instanceof FilterCollection) {
            return;
        }
        if (!$filters->has('properties')) {
            return;
        }

        $selected = $filters->get('properties')->getValues();
        if (!empty($selected)) {
            $criteria->getAssociation('options')->addFilter(new EqualsAnyFilter('id', $selected));
        }
    }
}
