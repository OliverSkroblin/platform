<?php declare(strict_types=1);

namespace Shopware\Product\Event;

use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Event\NestedEvent;
use Shopware\Framework\Event\NestedEventCollection;

class ProductAttachmentWrittenEvent extends NestedEvent
{
    const NAME = 'product_attachment.written';

    /**
     * @var string[]
     */
    protected $productAttachmentUuids;

    /**
     * @var NestedEventCollection
     */
    protected $events;

    /**
     * @var array
     */
    protected $errors;

    /**
     * @var TranslationContext
     */
    protected $context;

    public function __construct(array $productAttachmentUuids, TranslationContext $context, array $errors = [])
    {
        $this->productAttachmentUuids = $productAttachmentUuids;
        $this->events = new NestedEventCollection();
        $this->context = $context;
        $this->errors = $errors;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getContext(): TranslationContext
    {
        return $this->context;
    }

    /**
     * @return string[]
     */
    public function getProductAttachmentUuids(): array
    {
        return $this->productAttachmentUuids;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function addEvent(?NestedEvent $event): void
    {
        if ($event === null) {
            return;
        }
        $this->events->add($event);
    }

    public function getEvents(): NestedEventCollection
    {
        return $this->events;
    }
}
