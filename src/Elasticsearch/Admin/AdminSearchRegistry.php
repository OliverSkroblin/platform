<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin;

use Elasticsearch\Client;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\Event\ProgressAdvancedEvent;
use Shopware\Core\Framework\Event\ProgressFinishedEvent;
use Shopware\Core\Framework\Event\ProgressStartedEvent;
use Shopware\Core\Framework\MessageQueue\Handler\AbstractMessageHandler;
use Shopware\Elasticsearch\Admin\Indexer\AdminSearchIndexer;
use Shopware\Elasticsearch\Exception\ElasticsearchIndexingException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AdminSearchRegistry extends AbstractMessageHandler implements EventSubscriberInterface
{
    public const ADMIN_SEARCH_INDEX = 'admin_search';

    /**
     * @var AdminSearchIndexer[]
     */
    private iterable $indexer;

    private MessageBusInterface $queue;

    private EventDispatcherInterface $dispatcher;

    private Client $client;

    public function __construct(iterable $indexer, MessageBusInterface $queue, EventDispatcherInterface $dispatcher, Client $client)
    {
        $this->indexer = $indexer;
        $this->queue = $queue;
        $this->dispatcher = $dispatcher;
        $this->client = $client;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => [
                ['refresh', -1000],
            ],
        ];
    }

    public static function getHandledMessages(): iterable
    {
        return [
            AdminSearchIndexingMessage::class,
        ];
    }

    public function iterate(): void
    {
        if ($this->indexExists()) {
            $this->client->indices()->delete(['index' => self::ADMIN_SEARCH_INDEX]);
        }

        $this->createIndex();

        foreach ($this->indexer as $indexer) {
            $iterator = $indexer->getIterator();

            $this->dispatcher->dispatch(new ProgressStartedEvent($indexer->getEntityName(), $iterator->fetchCount()));

            while ($ids = $iterator->fetch()) {
                $this->queue->dispatch(new AdminSearchIndexingMessage($indexer->getEntityName(), $ids));
                $this->dispatcher->dispatch(new ProgressAdvancedEvent(\count($ids)));
            }

            $this->dispatcher->dispatch(new ProgressFinishedEvent($indexer->getEntityName()));
        }
    }

    public function refresh(EntityWrittenContainerEvent $event): void
    {
        if (!$this->indexExists()) {
            $this->createIndex();
        }

        foreach ($this->indexer as $indexer) {
            $ids = $event->getPrimaryKeys($indexer->getEntityName());

            if (empty($ids)) {
                continue;
            }
            $documents = $indexer->fetch($ids);

            $this->push($indexer->getEntityName(), $documents, $ids);
        }
    }

    public function handle($message): void
    {
        if (!$message instanceof AdminSearchIndexingMessage) {
            return;
        }

        $indexer = $this->getIndexer($message->getEntity());

        $documents = $indexer->fetch($message->getIds());

        $this->push($message->getEntity(), $documents, $message->getIds());
    }

    private function getIndexer(string $entity): AdminSearchIndexer
    {
        foreach ($this->indexer as $indexer) {
            if ($indexer->getEntityName() === $entity) {
                return $indexer;
            }
        }

        throw new \RuntimeException(\sprintf('Indexer for entity %s not found', $entity));
    }

    private function push(string $type, array $data, array $ids): void
    {
        $toRemove = array_filter($ids, static fn (string $id) => !isset($data[$id]));

        $documents = [];
        foreach ($data as $id => $document) {
            $documents[] = ['index' => ['_id' => self::id($type, $id)]];
            $document['entity_name'] = $type;
            $documents[] = $document;
        }

        foreach ($toRemove as $id) {
            $documents[] = ['delete' => ['_id' => self::id($type, $id)]];
        }

        $arguments = [
            'index' => self::ADMIN_SEARCH_INDEX,
            'body' => $documents,
        ];

        $result = $this->client->bulk($arguments);

        if (\is_array($result) && isset($result['errors']) && $result['errors']) {
            $errors = $this->parseErrors($result);

            throw new ElasticsearchIndexingException($errors);
        }
    }

    private function createIndex(): void
    {
        $mapping = [
            'properties' => [
                'id' => ['type' => 'keyword'],
                'entity_id' => ['type' => 'keyword'],
                'entity_name' => ['type' => 'keyword'],
                'text' => ['type' => 'text'],
            ],
        ];

        $this->client->indices()->create([
            'index' => self::ADMIN_SEARCH_INDEX,
            'body' => ['mappings' => $mapping],
        ]);
    }

    private function indexExists(): bool
    {
        return $this->client->indices()->exists(['index' => self::ADMIN_SEARCH_INDEX]);
    }

    private function parseErrors(array $result): array
    {
        $errors = [];
        foreach ($result['items'] as $item) {
            $item = $item['index'] ?? $item['delete'];

            if (\in_array($item['status'], [200, 201], true)) {
                continue;
            }

            $errors[] = [
                'index' => $item['_index'],
                'id' => $item['_id'],
                'type' => $item['error']['type'] ?? $item['_type'],
                'reason' => $item['error']['reason'] ?? $item['result'],
            ];
        }

        return $errors;
    }

    private static function id(string $type, string $id): string
    {
        return $type . '_' . $id;
    }
}
