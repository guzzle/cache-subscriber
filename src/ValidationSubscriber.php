<?php
namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Validates cached responses as needed.
 *
 * @Link http://tools.ietf.org/html/rfc7234#section-4.3
 */
class ValidationSubscriber implements SubscriberInterface
{
    /** @var CacheStorageInterface Cache object storing cache data */
    private $storage;

    /** @var callable */
    private $canCache;

    /** @var array */
    private static $gone = [404 => true, 410 => true];

    /** @var array */
    private static $replaceHeaders = [
        'Date',
        'Expires',
        'Cache-Control',
        'ETag',
        'Last-Modified',
    ];

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
            $this->shouldvalidate($e->getRequest(), $e->getResponse())
        ) {
            $this->validate($e->getRequest(), $e->getResponse(), $e);
        }
    }

    private function validate(
        RequestInterface $request,
        ResponseInterface $response,
        CompleteEvent $event
    ) {
        try {
            $validate = $this->createRevalidationRequest($request, $response);
            $validated = $event->getClient()->send($validate);
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
        }

        if ($validated->getStatusCode() == 200) {
            $this->handle200Response($request, $validated, $event);
        } elseif ($validated->getStatusCode() == 304) {
            $this->handle304Response($request, $response, $validated, $event);
        }
    }

    private function shouldValidate(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        if ($request->getMethod() != 'GET'
            || $request->getConfig()->get('cache.disable')
        ) {
            return false;
        }

        $validate = Utils::getDirective($request, 'Pragma') === 'no-cache'
            || Utils::getDirective($response, 'Pragma') === 'no-cache'
            || Utils::getDirective($request, 'must-revalidate')
            || Utils::getDirective($response, 'must-revalidate')
            || Utils::getDirective($request, 'no-cache')
            || Utils::getDirective($response, 'no-cache')
            || Utils::getDirective($response, 'max-age') === '0'
            || Utils::getDirective($response, 's-maxage') === '0';

        // Use the strong ETag validator if available and the response contains
        // no Cache-Control directive
        if (!$validate
            && !$response->hasHeader('Cache-Control')
            && $response->hasHeader('ETag')
        ) {
            $validate = true;
        }

        return $validate;
    }

    /**
     * Handles a bad response when attempting to validate.
     *
     * If the resource no longer exists, then remove from the cache.
     *
     * @param BadResponseException $e Exception encountered
     *
     * @throws BadResponseException
     */
    private function handleBadResponse(BadResponseException $e)
    {
        if (isset(self::$gone[$e->getResponse()->getStatusCode()])) {
            $this->storage->delete($e->getRequest());
        }

        throw $e;
    }

    /**
     * Creates a request to use for revalidation.
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response to validate
     *
     * @return RequestInterface returns a revalidation request
     */
    private function createRevalidationRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $validate = clone $request;
        $validate->getConfig()->set('cache.disable', true);
        $validate->removeHeader('Pragma');
        $validate->removeHeader('Cache-Control');
        $responseDate = $response->getHeader('Last-Modified')
            ?: $response->getHeader('Date');
        $validate->setHeader('If-Modified-Since', $responseDate);

        if ($etag = $response->getHeader('ETag')) {
            $validate->setHeader('If-None-Match', $etag);
        }

        return $validate;
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

        // Replace cached headers with any of these headers from the
        // origin server that might be more up to date
        $modified = false;
        foreach (self::$replaceHeaders as $name) {
            if ($validated->hasHeader($name)
                && $validated->getHeader($name) != $response->getHeader($name)
            ) {
                $modified = true;
                $response->setHeader($name, $validated->getHeader($name));
            }
        }

        // Store the updated response in cache
        if ($modified) {
            $this->storage->cache($request, $response);
        }
    }
}
