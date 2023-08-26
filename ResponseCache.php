<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Cache;

use Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Web Page Caching
 */
final class ResponseCache
{
    /**
     * Cache time to live.
     *
     * @var int seconds
     */
    private int $ttl = 0;

    /**
     * @param bool|string[] $cacheQueryString Whether to take the URL query string into consideration when generating output cache files. 
	 * 		Valid options are:
     *			false      = Disabled
     *    		true       = Enabled, take all query parameters into account.
	 *          		        Please be aware that this may result in numerous cache
     *                 			files generated for the same page over and over again.
     *    		array('q') = Enabled, but only take into account the specified list of query parameters.
     */
    public function __construct(private CacheInterface $cache, private bool|array $cacheQueryString = false)
    {
    }

    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Generates the cache key to use from the current request.
     *
     * @internal for testing purposes only
     */
    public function generateCacheKey(RequestInterface $request): string
    {
        $uri = clone $request->getUri();

		// @todo implementation de la recuperation des queriestring
        /* $query = $this->cacheQueryString
            ? $uri->getQuery(is_array($this->cacheQueryString) ? ['only' => $this->cacheQueryString] : [])
            : ''; */

		$query = '';

        return md5($uri->withFragment('')->withQuery($query));
    }

    /**
     * Caches the response.
     */
    public function make(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        if ($this->ttl === 0) {
            return true;
        }

		$headers = [];

        foreach (array_keys($response->getHeaders()) as $header) {
            $headers[$header] = $response->getHeaderLine($header);
        }

        return $this->cache->set(
            $this->generateCacheKey($request),
            serialize(['headers' => $headers, 'output' => $response->getBody()->getContents()]),
            $this->ttl
        );
    }

    /**
     * Gets the cached response for the request.
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
		if ($cachedResponse = $this->cache->get($this->generateCacheKey($request))) {
            $cachedResponse = unserialize($cachedResponse);

			if (! is_array($cachedResponse) || ! isset($cachedResponse['output']) || ! isset($cachedResponse['headers'])) {
                throw new Exception('Erreur lors de la désérialisation du cache de page');
            }

            $headers = $cachedResponse['headers'];
            $output  = $cachedResponse['output'];

			// Effacer tous les en-têtes par défaut
            foreach (array_keys($response->getHeaders()) as $key) {
                $response = $response->withoutHeader($key);
            }

            // Définir les en-têtes mis en cache
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
			
			return $response->withBody(to_stream($output));
        }

        return null;
    }
}
