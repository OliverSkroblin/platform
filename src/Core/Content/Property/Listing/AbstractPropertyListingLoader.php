<?php declare(strict_types=1);

namespace Shopware\Core\Content\Property\Listing;

use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Framework\Context;

abstract class AbstractPropertyListingLoader
{
    abstract public function getDecorated(): self;

    abstract public function load(string $groupId, Context $context): PropertyGroupOptionCollection;
}
