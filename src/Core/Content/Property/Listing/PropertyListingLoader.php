<?php declare(strict_types=1);

namespace Shopware\Core\Content\Property\Listing;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class PropertyListingLoader extends AbstractPropertyListingLoader
{
    protected EntityRepositoryInterface $repository;

    public function __construct(EntityRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function getDecorated(): self
    {
        throw new DecorationPatternException(self::class);
    }

    public function load(string $groupId, Context $context): PropertyGroupOptionCollection
    {
        $criteria = new Criteria([$groupId]);
        $criteria->addAssociation('options.media');
        $criteria->setTitle('product-listing::property-loader');

        /** @var PropertyGroupCollection $groups */
        $groups = $this->repository->search($criteria, $context)->getEntities();

        $groups->sortByConfig();

        $group = $groups->first();

        if (!$group) {
            throw new \RuntimeException(sprintf('Property group with id "%s" not found', $groupId));
        }
        if (!$group->getOptions()) {
            throw new \RuntimeException(sprintf('Property group with id "%s" has no options', $groupId));
        }

        return $group->getOptions();
    }
}
