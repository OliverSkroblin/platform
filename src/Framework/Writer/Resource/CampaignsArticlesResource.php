<?php declare(strict_types=1);

namespace Shopware\Framework\Write\Resource;

use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Write\Field\IntField;
use Shopware\Framework\Write\Field\StringField;
use Shopware\Framework\Write\Flag\Required;
use Shopware\Framework\Write\Resource;

class CampaignsArticlesResource extends Resource
{
    protected const PARENTID_FIELD = 'parentID';
    protected const ARTICLEORDERNUMBER_FIELD = 'articleordernumber';
    protected const NAME_FIELD = 'name';
    protected const TYPE_FIELD = 'type';
    protected const POSITION_FIELD = 'position';

    public function __construct()
    {
        parent::__construct('s_campaigns_articles');

        $this->fields[self::PARENTID_FIELD] = new IntField('parentID');
        $this->fields[self::ARTICLEORDERNUMBER_FIELD] = new StringField('articleordernumber');
        $this->fields[self::NAME_FIELD] = (new StringField('name'))->setFlags(new Required());
        $this->fields[self::TYPE_FIELD] = (new StringField('type'))->setFlags(new Required());
        $this->fields[self::POSITION_FIELD] = new IntField('position');
    }

    public function getWriteOrder(): array
    {
        return [
            \Shopware\Framework\Write\Resource\CampaignsArticlesResource::class,
        ];
    }

    public static function createWrittenEvent(array $updates, TranslationContext $context, array $errors = []): ?\Shopware\Framework\Event\CampaignsArticlesWrittenEvent
    {
        if (empty($updates) || !array_key_exists(self::class, $updates)) {
            return null;
        }

        $event = new \Shopware\Framework\Event\CampaignsArticlesWrittenEvent($updates[self::class] ?? [], $context, $errors);

        unset($updates[self::class]);

        $event->addEvent(\Shopware\Framework\Write\Resource\CampaignsArticlesResource::createWrittenEvent($updates, $context));

        return $event;
    }
}
