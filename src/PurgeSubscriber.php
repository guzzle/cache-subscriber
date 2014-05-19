<?php
namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\Response;

/**
 * Listens for PURGE requests and purges a URL when a non-idempotent request
 * is made.
 */
class PurgeSubscriber implements SubscriberInterface
{
    /** @var bool */
    private $autoPurge;

    /** @var CacheStorageInterface */
    private $storage;

    /** @var array */
    private static $purgeMethods = [
        'PUT'    => true,
        'POST'   => true,
        'DELETE' => true,
        'PATCH'  => true
    ];

    /**
     * @param CacheStorageInterface $storage   Storage to modify if purging
     * @param bool                  $autoPurge Purge resources when
     *                                         non-idempotent requests are sent
     *                                         to a resource.
     */
    public function __construct($storage, $autoPurge = false)
    {
        $this->storage = $storage;
        $this->autoPurge = $autoPurge;
    }

    public function getEvents()
    {
        return ['before' => ['onBefore', RequestEvents::LATE]];
    }

    public function onBefore(BeforeEvent $event)
    {
        $request = $event->getRequest();
        $method = $request->getMethod();

        if ($this->autoPurge && isset(self::$purgeMethods[$method])) {
            $this->storage->purge($request);
        } elseif ($method === 'PURGE') {
            $this->storage->purge($request);
            $event->intercept(new Response(200, [], 'purged'));
        }
    }
}
