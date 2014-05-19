<?php
namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Revalidates cached responses as needed.
 */
class RevalidationSubscriber implements SubscriberInterface
{
    /** @var CacheStorageInterface Cache object storing cache data */
    protected $storage;

    /** @var callable */
    protected $canCache;

    /**
     * @param CacheStorageInterface $cache    Cache storage
     * @param callable              $canCache Callable used to determine if a
     *                                        request can be cached. Accepts a
     *                                        RequestInterface and returns a
     *                                        boolean value.
     */
    public function __construct(
        CacheStorageInterface $cache,
        callable $canCache
    ) {
        $this->storage = $cache;
        $this->canCache = $canCache;
    }

    public function getEvents()
    {
        return ['complete' => ['onComplete', RequestEvents::EARLY]];
    }

    public function onComplete(CompleteEvent $e)
    {
        $lookup = $e->getRequest()->getConfig()->get('cache_lookup');

        if ($lookup == 'HIT' &&
            $this->shouldRevalidate($e->getRequest(), $e->getResponse())
        ) {
            $this->revalidate($e->getRequest(), $e->getResponse(), $e);
        }
    }

    private function revalidate(
        RequestInterface $request,
        ResponseInterface $response,
        CompleteEvent $e
    ) {
        try {
            $revalidate = $this->createRevalidationRequest($request, $response);
            $validated = $e->getClient()->send($revalidate);
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
            return;
        }

        if ($validated->getStatusCode() == 200) {
            $this->handle200Response($request, $validated, $e);
        } elseif ($validated->getStatusCode() == 304) {
            $this->handle304Response($request, $response, $validated, $e);
        }
    }

    private function shouldRevalidate(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        if ($request->getMethod() != RequestInterface::GET) {
            return false;
        }

        $revalidate = Utils::getDirective($request, 'Pragma') === 'no-cache' ||
            (Utils::getDirective($request, 'no-cache') &&
                Utils::getDirective($request, 'must-revalidate')) ||
            (Utils::getDirective($response, 'no-cache') &&
                Utils::getDirective($response, 'must-revalidate'));

        // Use the strong ETag validator if available and the response contains
        // no Cache-Control directive
        if (!$revalidate &&
            !$response->hasHeader('Cache-Control') &&
            $response->hasHeader('ETag')
        ) {
            $revalidate = true;
        }

        return $revalidate;
    }

    /**
     * Handles a bad response when attempting to revalidate
     *
     * @param BadResponseException $e Exception encountered
     *
     * @throws BadResponseException
     */
    private function handleBadResponse(BadResponseException $e)
    {
        // 404 errors mean the resource no longer exists, so remove from
        // cache, and prevent an additional request by throwing the exception
        if ($e->getResponse()->getStatusCode() == 404) {
            $this->storage->delete($e->getRequest());
            throw $e;
        }
    }

    /**
     * Creates a request to use for revalidation
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response to revalidate
     *
     * @return RequestInterface returns a revalidation request
     */
    private function createRevalidationRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $revalidate = clone $request;
        $revalidate->removeHeader('Pragma');
        $revalidate->removeHeader('Cache-Control');
        $responseDate = $response->getHeader('Last-Modified')
            ?: $response->getHeader('Date');
        $revalidate->setHeader('If-Modified-Since', $responseDate);

        if ($etag = $response->getHeader('ETag')) {
            $revalidate->setHeader('If-None-Match', $etag);
        }

        // Remove any cache plugins that might be on the request to prevent
        // infinite recursive revalidations.
        // @todo

        return $revalidate;
    }

    private function handle200Response(
        RequestInterface $request,
        ResponseInterface $validateResponse,
        CompleteEvent $event
    ) {
        // Store the 200 response in the cache if possible
        if (Utils::canCacheResponse($validateResponse)) {
            $this->storage->cache($request, $validateResponse);
        }

        $event->intercept($validateResponse);
    }

    private function handle304Response(
        RequestInterface $request,
        ResponseInterface $response,
        ResponseInterface $validated,
        CompleteEvent $event
    ) {
        // Make sure that this response has the same ETag
        if ($validated->getHeader('ETag') !== $response->getHeader('ETag')) {
            // Revalidation failed, so remove from cache and retry.
            $this->storage->delete($request);
            $event->intercept($event->getClient()->send($request));
            return;
        }

        static $replaceHeaders = ['Date', 'Expires', 'Cache-Control',
            'ETag', 'Last-Modified'];

        // Replace cached headers with any of these headers from the
        // origin server that might be more up to date
        $modified = false;
        foreach ($replaceHeaders as $name) {
            if ($validated->hasHeader($name)) {
                $modified = true;
                $response->setHeader($name, $validated->getHeader($name));
            }
        }

        // Store the updated response in cache
        if ($modified && call_user_func($this->canCache, $response)) {
            $this->storage->cache($request, $response);
        }
    }
}
