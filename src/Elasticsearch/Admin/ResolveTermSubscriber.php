<?php declare(strict_types=1);

namespace Shopware\Elasticsearch\Admin;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class ResolveTermSubscriber implements EventSubscriberInterface
{
    private AdminSearcher $searcher;

    public function __construct(AdminSearcher $searcher)
    {
        $this->searcher = $searcher;
    }

    public static function getSubscribedEvents()
    {
        return [
            RequestEvent::class => 'request',
            ResponseEvent::class => 'response',
        ];
    }

    public function request(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->headers->has('admin-module')) {
            return;
        }

        $module = $request->headers->get('admin-module');

        $elastic = true;
        $term = $request->get('term');
        if (!$term || !$elastic) {
            return;
        }

        $request->request->remove('queries');
        $request->request->remove('term');

        $page = $request->request->get('page', 1);
        $limit = $request->request->get('limit', 50);

        $ids = $this->searcher->resolveTerm($term, $module, $page, $limit);

        $request->request->set('ids', $ids['ids']);
        $request->request->set('elastic-total', $ids['total']);
    }

    public function response(ResponseEvent $event): void
    {
        if (!$event->getRequest()->request->has('elastic-total')) {
            return;
        }

        $total = $event->getRequest()->request->get('elastic-total');

        $content = \json_decode($event->getResponse()->getContent(), true);

        if (isset($content['meta'])) {
            $content['meta']['total'] = $total;
        }

        $event->getResponse()->setContent(\json_encode($content));
    }
}
