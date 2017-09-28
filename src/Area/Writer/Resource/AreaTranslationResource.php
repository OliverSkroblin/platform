<?php declare(strict_types=1);

namespace Shopware\Area\Writer\Resource;

use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Write\Field\FkField;
use Shopware\Framework\Write\Field\ReferenceField;
use Shopware\Framework\Write\Field\StringField;
use Shopware\Framework\Write\Flag\Required;
use Shopware\Framework\Write\Resource;

class AreaTranslationResource extends Resource
{
    protected const NAME_FIELD = 'name';

    public function __construct()
    {
        parent::__construct('area_translation');

        $this->fields[self::NAME_FIELD] = (new StringField('name'))->setFlags(new Required());
        $this->fields['area'] = new ReferenceField('areaUuid', 'uuid', \Shopware\Area\Writer\Resource\AreaResource::class);
        $this->primaryKeyFields['areaUuid'] = (new FkField('area_uuid', \Shopware\Area\Writer\Resource\AreaResource::class, 'uuid'))->setFlags(new Required());
        $this->fields['language'] = new ReferenceField('languageUuid', 'uuid', \Shopware\Shop\Writer\Resource\ShopResource::class);
        $this->primaryKeyFields['languageUuid'] = (new FkField('language_uuid', \Shopware\Shop\Writer\Resource\ShopResource::class, 'uuid'))->setFlags(new Required());
    }

    public function getWriteOrder(): array
    {
        return [
            \Shopware\Area\Writer\Resource\AreaResource::class,
            \Shopware\Shop\Writer\Resource\ShopResource::class,
            \Shopware\Area\Writer\Resource\AreaTranslationResource::class,
        ];
    }

    public static function createWrittenEvent(array $updates, TranslationContext $context, array $errors = []): ?\Shopware\Area\Event\AreaTranslationWrittenEvent
    {
        if (empty($updates) || !array_key_exists(self::class, $updates)) {
            return null;
        }

        $event = new \Shopware\Area\Event\AreaTranslationWrittenEvent($updates[self::class] ?? [], $context, $errors);

        unset($updates[self::class]);

        $event->addEvent(\Shopware\Area\Writer\Resource\AreaResource::createWrittenEvent($updates, $context));
        $event->addEvent(\Shopware\Shop\Writer\Resource\ShopResource::createWrittenEvent($updates, $context));
        $event->addEvent(\Shopware\Area\Writer\Resource\AreaTranslationResource::createWrittenEvent($updates, $context));

        return $event;
    }
}
