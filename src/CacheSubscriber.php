<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\ResponseInterface;

/**
 * Plugin to enable the caching of GET and HEAD requests.
 *
 * Caching can be done on all requests passing through this plugin or only
 * after retrieving resources with cacheable response headers.
 *
 * This is a simple implementation of RFC 2616 and should be considered a
 * private transparent proxy cache, meaning authorization and private data can
 * be cached.
 *
 * It also implements RFC 5861's `stale-if-error` Cache-Control extension,
 * allowing stale cache responses to be used when an error is encountered
 * (such as a `500 Internal Server Error` or DNS failure).
 */
class CacheSubscriber implements SubscriberInterface
{
    /** @var callable Cache revalidation strategy */
    protected $revalidation;

    /** @var CanCacheStrategyInterface Determines if a request is cacheable */
    protected $canCache;

    /** @var CacheStorageInterface $cache Object used to cache responses */
    protected $storage;

    /** @var bool */
    protected $autoPurge;

    /**
     * @param array $options Array of options used to create the subscriber
     *
     *     - storage: (CacheStorageInterface) Adapter used to cache responses
     *     - revalidate: callable|bool cache revalidation strategy. Set to
     *       false to disable revalidation, or pass a callable to perform
     *       custom revalidation. Omitting this value will utilize a default
     *       revalidation strategy. The callable accepts a request and response,
     *       and returns the response that should be used after revalidation.
     *     - can_cache: callable used to determine if a request can be cached.
     *       The callable accepts a request object and returns true or false if
     *       the request can be cached.
     *     - auto_purge: (bool) Set to true to automatically PURGE resources
     *       when non-idempotent requests are sent to a resource. Defaults to
     *       false.
     *
     * @throws \InvalidArgumentException if no cache is provided
     */
    public function __construct($options = [])
    {
        if (!isset($options['storage'])) {
            throw new \InvalidArgumentException('storage is a required option');
        } else {
            $this->storage = $options['storage'];
        }

        if (!isset($options['can_cache'])) {
            $this->canCache = new DefaultCanCacheStrategy();
        } else {
            $this->canCache = $options['can_cache'];
        }

        $this->autoPurge = isset($options['auto_purge'])
            ? $options['auto_purge']
            : false;

        if (!isset($options['revalidation'])) {
            $this->revalidation = new DefaultRevalidation();
        } elseif ($options['revalidation'] === false) {
            $this->revalidation = function ($req, $res) { return $res; };
        } else {
            $this->revalidation = $options['revalidation'];
        }
    }

    public function getEvents()
    {
        return [
            'before'   => ['onBefore', RequestEvents::LATE],
            'complete' => ['onComplete', RequestEvents::EARLY],
            'error'    => ['onRequestError', RequestEvents::EARLY]
        ];
    }

    public function onBefore(BeforeEvent $event)
    {
        $req = $event->getRequest();
        $this->addViaHeader($req);

        if ($this->canCache->canCacheRequest($req) ||
            $this->handlePurge($req, $event)
        ) {
            return;
        }

        if (!($response = $this->storage->fetch($req))) {
            return;
        }

        $params = $req->getConfig();
        $params['cache.lookup'] = true;
        $response->setHeader(
            'Age',
            time() - strtotime($response->getDate() ?: $response->getLastModified() ?: 'now')
        );

        // Validate that the response satisfies the request
        if ($this->canResponseSatisfyRequest($req, $response)) {
            if (!isset($params['cache.hit'])) {
                $params['cache.hit'] = true;
            }
            $req->setResponse($response);
        }
    }

    public function onComplete(CompleteEvent $event)
    {
        $request = $event['request'];
        $response = $event['response'];

        if ($request->getParams()->get('cache.hit') === null &&
            $this->canCache->canCacheRequest($request) &&
            $this->canCache->canCacheResponse($response)
        ) {
            $this->storage->cache($request, $response);
        }

        $this->addResponseHeaders($request, $response);
    }

    public function onError(ErrorEvent $event)
    {
        $request = $event['request'];

        if (!$this->canCache->canCacheRequest($request)) {
            return;
        }

        if ($response = $this->storage->fetch($request)) {
            $response->setHeader(
                'Age',
                time() - strtotime($response->getLastModified() ?: $response->getDate() ?: 'now')
            );

            if ($this->canResponseSatisfyFailedRequest($request, $response)) {
                $request->getParams()->set('cache.hit', 'error');
                $this->addResponseHeaders($request, $response);
                $event['response'] = $response;
                $event->stopPropagation();
            }
        }
    }

    /**
     * If possible, set a cache response on a cURL exception
     *
     * @param Event $event
     *
     * @return null
     */
    public function onRequestException(Event $event)
    {
        if (!$event['exception'] instanceof CurlException) {
            return;
        }

        $request = $event['request'];
        if (!$this->canCache->canCacheRequest($request)) {
            return;
        }

        if ($response = $this->storage->fetch($request)) {
            $response->setHeader('Age', time() - strtotime($response->getDate() ? : 'now'));
            if (!$this->canResponseSatisfyFailedRequest($request, $response)) {
                return;
            }
            $request->getParams()->set('cache.hit', 'error');
            $request->setResponse($response);
            $this->addResponseHeaders($request, $response);
            $event->stopPropagation();
        }
    }

    /**
     * Check if a cache response satisfies a request's caching constraints
     *
     * @param RequestInterface  $request  Request to validate
     * @param ResponseInterface $response Response to validate
     *
     * @return bool
     */
    public function canResponseSatisfyRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $responseAge = $response->calculateAge();
        $reqc = $request->getHeader('Cache-Control');
        $resc = $response->getHeader('Cache-Control');

        // Check the request's max-age header against the age of the response
        if ($reqc && $reqc->hasDirective('max-age') &&
            $responseAge > $reqc->getDirective('max-age')) {
            return false;
        }

        // Check the response's max-age header
        if ($response->isFresh() === false) {
            $maxStale = $reqc ? $reqc->getDirective('max-stale') : null;
            if (null !== $maxStale) {
                if ($maxStale !== true && $response->getFreshness() < (-1 * $maxStale)) {
                    return false;
                }
            } elseif ($resc && $resc->hasDirective('max-age')
                && $responseAge > $resc->getDirective('max-age')
            ) {
                return false;
            }
        }

        if ($this->revalidation->shouldRevalidate($request, $response)) {
            try {
                return $this->revalidation->revalidate($request, $response);
            } catch (CurlException $e) {
                $request->getParams()->set('cache.hit', 'error');
                return $this->canResponseSatisfyFailedRequest($request, $response);
            }
        }

        return true;
    }

    /**
     * Check if a cache response satisfies a failed request's caching
     * constraints
     *
     * @param RequestInterface  $request  Request to validate
     * @param ResponseInterface $response Response to validate
     *
     * @return bool
     */
    public function canResponseSatisfyFailedRequest(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $reqc = $request->getHeader('Cache-Control');
        $resc = $response->getHeader('Cache-Control');
        $requestStaleIfError = $reqc
            ? $reqc->getDirective('stale-if-error')
            : null;
        $responseStaleIfError = $resc
            ? $resc->getDirective('stale-if-error')
            : null;

        if (!$requestStaleIfError && !$responseStaleIfError) {
            return false;
        }

        if (is_numeric($requestStaleIfError) &&
            $response->getAge() - $response->getMaxAge() > $requestStaleIfError
        ) {
            return false;
        }

        if (is_numeric($responseStaleIfError) &&
            $response->getAge() - $response->getMaxAge() > $responseStaleIfError
        ) {
            return false;
        }

        return true;
    }

    /**
     * Add the plugin's headers to a response
     *
     * @param RequestInterface  $request  Request
     * @param ResponseInterface $response Response to add headers to
     */
    private function addResponseHeaders(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->addViaHeader($response);
        $params = $request->getConfig();
        $lookup = ($params['cache.lookup'] === true ? 'HIT' : 'MISS')
            . ' from GuzzleCache';

        if ($header = $response->getHeader('X-Cache-Lookup')) {
            // Don't add duplicates
            $values = $header->toArray();
            $values[] = $lookup;
            $response->setHeader('X-Cache-Lookup', array_unique($values));
        } else {
            $response->setHeader('X-Cache-Lookup', $lookup);
        }

        if ($params['cache.hit'] === true) {
            $xcache = 'HIT from GuzzleCache';
        } elseif ($params['cache.hit'] == 'error') {
            $xcache = 'HIT_ERROR from GuzzleCache';
        } else {
            $xcache = 'MISS from GuzzleCache';
        }

        if ($header = $response->getHeader('X-Cache', true)) {
            $values[] = $xcache;
            $response->setHeader('X-Cache', array_unique($values));
        } else {
            $response->setHeader('X-Cache', $xcache);
        }

        $this->addFreshnessWarnings($response, $params['cache.hit']);
    }

    private function addFreshnessWarnings(ResponseInterface $response, $hit)
    {
        if ($response->isFresh()) {
            return;
        }

        $response->addHeader(
            'Warning',
            sprintf(
                '110 GuzzleCache/%s "Response is stale"',
                ClientInterface::VERSION
            )
        );

        if ($hit === 'error') {
            $response->addHeader(
                'Warning',
                sprintf(
                    '111 GuzzleCache/%s "Revalidation failed"',
                    ClientInterface::VERSION
                )
            );
        }
    }

    private function addViaHeader(MessageInterface $message)
    {
        $message->addHeader('Via', sprintf(
            '%s GuzzleCache/%s',
            $message->getProtocolVersion(),
            ClientInterface::VERSION
        ));
    }

    /**
     * Handle request purging, and return true if this is a cache PURGE
     */
    private function handlePurge(RequestInterface $req, BeforeEvent $event)
    {
        static $purge = ['PUT' => 1, 'POST' => 1, 'DELETE' => 1, 'PATCH' => 1];

        if ($req->getMethod() == 'PURGE') {
            $this->storage->purge($req);
            $event->intercept(new Response(200, [], 'purged'));
            return true;
        }

        if ($this->autoPurge && isset($purge[$req->getMethod()])) {
            $this->storage->purge($req);
        }

        return false;
    }
}
