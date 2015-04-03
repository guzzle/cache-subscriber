<?php
namespace GuzzleHttp\Subscriber\Cache;

use Doctrine\Common\Cache\Cache;
use GuzzleHttp\Message\AbstractMessage;
use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Stream;
use GuzzleHttp\Stream\StreamInterface;

/**
 * Default cache storage implementation.
 */
class CacheStorage implements CacheStorageInterface
{
    /** @var string */
    private $keyPrefix;

    /** @var int Default cache TTL */
    private $defaultTtl;

    /** @var Cache */
    private $cache;

    /** @var array Headers are excluded from the caching (see RFC 2616:13.5.1) */
    private static $noCache = [
        'age' => true,
        'connection' => true,
        'keep-alive' => true,
        'proxy-authenticate' => true,
        'proxy-authorization' => true,
        'te' => true,
        'trailers' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
        'set-cookie' => true,
        'set-cookie2' => true,
    ];

    /**
     * @param Cache  $cache      Cache backend.
     * @param string $keyPrefix  (optional) Key prefix to add to each key.
     * @param int    $defaultTtl (optional) The default TTL to set, in seconds.
     */
    public function __construct(Cache $cache, $keyPrefix = null, $defaultTtl = 0)
    {
        $this->cache = $cache;
        $this->keyPrefix = $keyPrefix;
        $this->defaultTtl = $defaultTtl;
    }

    public function cache(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $ctime = time();
        $ttl = $this->getTtl($response);
        $key = $this->getCacheKey($request, $this->normalizeVary($response));
        $headers = $this->persistHeaders($request);
        $entries = $this->getManifestEntries($key, $ctime, $response, $headers);
        $bodyDigest = null;

        // Persist the Vary response header.
        if ($response->hasHeader('vary')) {
            $this->cacheVary($request, $response);
        }

        // Persist the response body if needed
        if ($response->getBody() && $response->getBody()->getSize() > 0) {
            $body = $response->getBody();
            $bodyDigest = $this->getBodyKey($request->getUrl(), $body);
            $this->cache->save($bodyDigest, (string) $body, $ttl);
        }

        array_unshift($entries, [
            $headers,
            $this->persistHeaders($response),
            $response->getStatusCode(),
            $bodyDigest,
            $ctime + $ttl
        ]);

        $this->cache->save($key, serialize($entries));
    }

    public function delete(RequestInterface $request)
    {
        $vary = $this->fetchVary($request);
        $key = $this->getCacheKey($request, $vary);
        $entries = $this->cache->fetch($key);

        if (!$entries) {
            return;
        }

        // Delete each cached body
        foreach (unserialize($entries) as $entry) {
            if ($entry[3]) {
                $this->cache->delete($entry[3]);
            }
        }

        // Delete any cached Vary header responses.
        $this->deleteVary($request);

        $this->cache->delete($key);
    }

    public function purge($url)
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PURGE'] as $m) {
            $this->delete(new Request($m, $url));
        }
    }

    public function fetch(RequestInterface $request)
    {
        $vary = $this->fetchVary($request);
        if ($vary) {
            $key = $this->getCacheKey($request, $vary);
        } else {
            $key = $this->getCacheKey($request);
        }
        $entries = $this->cache->fetch($key);

        if (!$entries) {
            return null;
        }

        $match = $matchIndex = null;
        $headers = $this->persistHeaders($request);
        $entries = unserialize($entries);

        foreach ($entries as $index => $entry) {
            $vary = isset($entry[1]['vary']) ? $entry[1]['vary'] : '';
            if ($this->requestsMatch($vary, $headers, $entry[0])) {
                $match = $entry;
                $matchIndex = $index;
                break;
            }
        }

        if (!$match) {
            return null;
        }

        // Ensure that the response is not expired
        $response = null;
        if ($match[4] < time()) {
            $response = -1;
        } else {
            $response = new Response($match[2], $match[1]);
            if ($match[3]) {
                if ($body = $this->cache->fetch($match[3])) {
                    $response->setBody(Stream\Utils::create($body));
                } else {
                    // The response is not valid because the body was somehow
                    // deleted
                    $response = -1;
                }
            }
        }

        if ($response === -1) {
            // Remove the entry from the metadata and update the cache
            unset($entries[$matchIndex]);
            if ($entries) {
                $this->cache->save($key, serialize($entries));
            } else {
                $this->cache->delete($key);
            }

            return null;
        }

        return $response;
    }

    /**
     * Hash a request URL into a string that returns cache metadata.
     *
     * @param RequestInterface $request The Request to generate the cache key
     *                                  for.
     * @param array            $vary    (optional) An array of headers to vary
     *                                  the cache key by.
     *
     * @return string
     */
    private function getCacheKey(RequestInterface $request, array $vary = [])
    {
        $key = $request->getMethod() . ' ' . $request->getUrl();

        // If Vary headers have been passed in, fetch each header and add it to
        // the cache key.
        foreach ($vary as $header) {
            $key .= " $header: " . $request->getHeader($header);
        }

        return $this->keyPrefix . md5($key);
    }

    /**
     * Create a cache key for a response's body.
     *
     * @param string          $url  URL of the entry
     * @param StreamInterface $body Response body
     *
     * @return string
     */
    private function getBodyKey($url, StreamInterface $body)
    {
        return $this->keyPrefix . md5($url) . Stream\Utils::hash($body, 'md5');
    }

    /**
     * Determines whether two Request HTTP header sets are non-varying.
     *
     * @param string $vary Response vary header
     * @param array  $r1   HTTP header array
     * @param array  $r2   HTTP header array
     *
     * @return bool
     */
    private function requestsMatch($vary, $r1, $r2)
    {
        if ($vary) {
            foreach (explode(',', $vary) as $header) {
                $key = trim(strtolower($header));
                $v1 = isset($r1[$key]) ? $r1[$key] : null;
                $v2 = isset($r2[$key]) ? $r2[$key] : null;
                if ($v1 !== $v2) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Creates an array of cacheable and normalized message headers.
     *
     * @param MessageInterface $message
     *
     * @return array
     */
    private function persistHeaders(MessageInterface $message)
    {
        // Clone the response to not destroy any necessary headers when caching
        $headers = array_diff_key($message->getHeaders(), self::$noCache);

        // Cast the headers to a string
        foreach ($headers as &$value) {
            $value = implode(', ', $value);
        }

        return $headers;
    }

    /**
     * Return the TTL to use when caching a Response.
     *
     * @param ResponseInterface $response The response being cached.
     *
     * @return int The TTL in seconds.
     */
    private function getTtl(ResponseInterface $response)
    {
        $ttl = 0;

        if ($cacheControl = $response->getHeader('Cache-Control')) {
            $maxAge = Utils::getDirective($response, 'max-age');
            if (is_numeric($maxAge)) {
                $ttl += $maxAge;
            }

            // According to RFC5861 stale headers are *in addition* to any
            // max-age values.
            $stale = Utils::getDirective($response, 'stale-if-error');
            if (is_numeric($stale)) {
                $ttl += $stale;
            }
        } elseif ($expires = $response->getHeader('Expires')) {
            $ttl += strtotime($expires) - time();
        }

        return $ttl ?: $this->defaultTtl;
    }

    private function getManifestEntries(
        $key,
        $currentTime,
        ResponseInterface $response,
        $persistedRequest
    ) {
        $entries = [];
        $manifest = $this->cache->fetch($key);

        if (!$manifest) {
            return $entries;
        }

        // Determine which cache entries should still be in the cache
        $vary = $response->getHeader('Vary');

        foreach (unserialize($manifest) as $entry) {
            // Check if the entry is expired
            if ($entry[4] < $currentTime) {
                continue;
            }

            $varyCmp = isset($entry[1]['vary']) ? $entries[1]['vary'] : '';

            if ($vary != $varyCmp ||
                !$this->requestsMatch($vary, $entry[0], $persistedRequest)
            ) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Return a sorted list of Vary headers.
     *
     * While headers are case-insensitive, header values are not. We can only
     * normalize the order of headers to combine cache entries.
     *
     * @param ResponseInterface $response The Response with Vary headers.
     *
     * @return array An array of sorted headers.
     */
    private function normalizeVary(ResponseInterface $response)
    {
        $parts = AbstractMessage::normalizeHeader($response, 'vary');
        sort($parts);

        return $parts;
    }

    /**
     * Cache the Vary headers from a response.
     *
     * @param RequestInterface  $request  The Request that generated the Vary
     *                                    headers.
     * @param ResponseInterface $response The Response with Vary headers.
     */
    private function cacheVary(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $key = $this->getVaryKey($request);
        $this->cache->save($key, $this->normalizeVary($response), $this->getTtl($response));
    }

    /**
     * Fetch the Vary headers associated with a request, if they exist.
     *
     * Only responses, and not requests, contain Vary headers. However, we need
     * to be able to determine what Vary headers were set for a given URL and
     * request method on a future request.
     *
     * @param RequestInterface $request The Request to fetch headers for.
     *
     * @return array An array of headers.
     */
    private function fetchVary(RequestInterface $request)
    {
        $key = $this->getVaryKey($request);
        $varyHeaders = $this->cache->fetch($key);

        return is_array($varyHeaders) ? $varyHeaders : [];
    }

    /**
     * Delete the headers associated with a Vary request.
     *
     * @param RequestInterface $request The Request to delete headers for.
     */
    private function deleteVary(RequestInterface $request)
    {
        $key = $this->getVaryKey($request);
        $this->cache->delete($key);
    }

    /**
     * Get the cache key for Vary headers.
     *
     * @param RequestInterface $request The Request to fetch the key for.
     *
     * @return string The generated key.
     */
    private function getVaryKey(RequestInterface $request)
    {
        $key = $this->keyPrefix . md5('vary ' . $this->getCacheKey($request));

        return $key;
    }
}
