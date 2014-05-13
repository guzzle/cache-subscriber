<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\AbstractMessage;
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

    /** @var callable Determines if a request is cacheable */
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
            $this->revalidation = new DefaultRevalidation(
                $this->storage,
                $this->canCache
            );
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
            'error'    => ['onError', RequestEvents::EARLY]
        ];
    }

    /**
     * Checks if a request can be cached, and if so, intercepts with a cached
     * response is available.
     */
    public function onBefore(BeforeEvent $event)
    {
        $req = $event->getRequest();
        $this->addViaHeader($req);

        // If the request cannot be cached or this is a PURGE request
        if (call_user_func($this->canCache, $req) ||
            $this->handlePurge($req, $event)
        ) {
            return;
        }

        if (!($response = $this->storage->fetch($req))) {
            return;
        }

        $config = $req->getConfig();
        $config['cache_lookup'] = true;
        $response->setHeader('Age', self::getResponseAge($response));

        // Validate that the response satisfies the request
        if ($validated = $this->validate($req, $response)) {
            if ($validated === $response) {
                $config['cache_hit'] = true;
            }
            $event->intercept($validated);
        }
    }

    /**
     * Checks if the request and response can be cached, and if so, store it
     */
    public function onComplete(CompleteEvent $event)
    {
        $request = $event->getRequest();
        $response = $event->getResponse();

        // Cache the response if it can be cached and isn't already
        if (call_user_func($this->canCache, $request) &&
            $this->canCacheResponse($response)
        ) {
            $this->storage->cache($request, $response);
        }

        $this->addResponseHeaders($request, $response);
    }

    /**
     * If the request failed, then check if a cached response would suffice
     */
    public function onError(ErrorEvent $event)
    {
        $request = $event->getRequest();

        if (!call_user_func($this->canCache, $request)) {
            return;
        }

        if (!($response = $this->storage->fetch($request))) {
            return;
        }

        // Intercept the failed response if possible
        if ($this->validateFailed($request, $response)) {
            $request->getConfig()->set('cache_hit', 'error');
            $response->setHeader('Age', self::getResponseAge($response));
            $this->addResponseHeaders($request, $response);
            $event->intercept($response);
        }
    }

    /**
     * Get a cache control directive from a message
     *
     * @param MessageInterface $message Message to retrieve
     * @param string           $part    Cache directive to retrieve
     *
     * @return mixed|bool|null
     */
    public static function getDirective(MessageInterface $message, $part)
    {
        $parts = AbstractMessage::parseHeader($message, 'Cache-Control');

        if (isset($parts[$part])) {
            return $parts[$part];
        } elseif (in_array($part, $parts)) {
            return true;
        } else {
            return null;
        }
    }

    /**
     * Gets the age of a response in seconds.
     *
     * @param ResponseInterface $response
     *
     * @return int
     */
    public static function getResponseAge(ResponseInterface $response)
    {
        if ($response->hasHeader('Age')) {
            return (int) $response->getHeader('Age');
        }

        $lastMod = strtotime($response->getHeader('Last-Modified') ?: 'now');

        return time() - $lastMod;
    }

    /**
     * Gets the number of seconds from the current time in which this response
     * is still considered fresh.
     *
     * @param ResponseInterface $response
     *
     * @return int|null Returns the number of seconds
     */
    public static function getMaxAge(ResponseInterface $response)
    {
        $parts = AbstractMessage::parseHeader($response, 'Cache-Control');

        if (isset($parts['s-maxage'])) {
            return $parts['s-maxage'];
        } elseif (isset($parts['max-age'])) {
            return $parts['max-age'];
        } elseif ($response->hasHeader('Expires')) {
            return strtotime($response->getHeader('Expires') - time());
        }

        return null;
    }

    /**
     * Get the freshness of the response by returning the difference of the
     * maximum lifetime of the response and the age of the response.
     *
     * Freshness values less than 0 mean that the response is no longer fresh
     * and is ABS(freshness) seconds expired. Freshness values of greater than
     * zero is the number of seconds until the response is no longer fresh.
     * A NULL result means that no freshness information is available.
     *
     * @param ResponseInterface $response Response to get freshness of
     *
     * @return int
     */
    public function getFreshness(ResponseInterface $response)
    {
        $maxAge = self::getMaxAge($response);
        $age = self::getResponseAge($response);

        return $maxAge && $age ? ($maxAge - $age) : null;
    }

    private function validate(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $responseAge = self::getResponseAge($response);

        // Check the request's max-age header against the age of the response
        if ($responseAge > self::getDirective($response, 'max-age')) {
            return false;
        }

        // Check the response's max-age header against the fresness level
        $freshness = self::getFreshness($response);
        if ($freshness === null) {
            $maxStale = self::getDirective($request, 'max-stale');
            $maxAge = self::getDirective($response, 'max-age');
            if ($maxStale !== null) {
                if ($freshness < (-1 * $maxStale)) {
                    return false;
                }
            } elseif ($maxAge !== null && $responseAge > $maxAge) {
                return false;
            }
        }

        try {
            return call_user_func($this->revalidation, $request, $response);
        } catch (RequestException $e) {
            if ($e->getRequest()) {
                throw $e;
            }
            $request->getConfig()->set('cache.hit', 'error');
            return $this->validateFailed($request, $response);
        }
    }

    private function validateFailed(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $req = self::getDirective($request, 'stale-if-error');
        $res = self::getDirective($response, 'stale-if-error');

        if (!$req && !$res) {
            return false;
        }

        $responseAge = self::getResponseAge($response);
        $maxAge = self::getMaxAge($response);

        if (($req && $responseAge - $maxAge > $req) ||
            ($responseAge - $maxAge > $res)
        ) {
            return false;
        }

        return $response;
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

        if ($header = $response->getHeader('X-Cache-Lookup', true)) {
            // Don't add duplicates
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
        if (self::getFreshness($response) >= 0) {
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

    private function canCacheResponse(ResponseInterface $response)
    {
        static $cacheCodes = [200, 203, 206, 300, 301, 410];

        // Check if the response is cacheable based on the code
        if (!in_array((int) $response->getStatusCode(), $cacheCodes)) {
            return false;
        }

        // Make sure a valid body was returned and can be cached
        $body = $response->getBody();
        if (!$body || (!$body->isReadable() || !$body->isSeekable())) {
            return false;
        }

        // Never cache no-store resources (this is a private cache, so private
        // can be cached)
        if (self::getDirective($response, 'no-store')) {
            return false;
        }

        $freshness = self::getFreshness($response);

        return $freshness === null ||
            $freshness >= 0 ||
            $response->hasHeader('ETag') ||
            $response->hasHeader('Last-Modified');
    }
}
