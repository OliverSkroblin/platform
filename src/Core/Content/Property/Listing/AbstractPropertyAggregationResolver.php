<?php declare(strict_types=1);

namespace Shopware\Core\Content\Property\Listing;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

abstract class AbstractPropertyAggregationResolver
{
    abstract public function getDecorated(): AbstractPropertyAggregationResolver;

    abstract public function resolve(EntitySearchResult $result, Context $context): void;
}
