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
     * Duree de vie du cache.
     *
     * @var int seconds
     */
    private int $ttl = 0;

    /**
     * @param bool|list<string> $cacheQueryString S'il faut prendre en compte la chaîne de requête URL lors de la génération des fichiers de cache de sortie.
     *                                            Les options valides sont :
     *                                            false      = Désactivé
     *                                            true       = Activé, prend en compte tous les paramètres de requête.
     *                                            Veuillez noter que cela peut entraîner de nombreux fichiers de cache générés encore et encore pour la même page.
     *                                            array('q') = Activé, mais ne prend en compte que la liste spécifiée de paramètres de requête.
     */
    public function __construct(private CacheInterface $cache, private array|bool $cacheQueryString = false)
    {
    }

    public function setTtl(int $ttl): self
    {
        $this->ttl = $ttl;

        return $this;
    }

    /**
     * Génère la clé de cache à utiliser à partir de la requête actuelle.
     *
     * @internal à des fins de test uniquement
     */
    public function generateCacheKey(RequestInterface $request): string
    {
        $uri = $request->getUri();

		$query = '';
		if ($this->cacheQueryString !== false) {
			parse_str($uri->getQuery(), $queryParams);

			if (is_array($this->cacheQueryString)) {
				// Filtrer seulement les paramètres spécifiés
				$queryParams = array_intersect_key($queryParams, array_flip($this->cacheQueryString));
			}
			// Trier pour garantir l'ordre et éviter les doublons avec paramètres dans un ordre différent
			ksort($queryParams);
			$query = http_build_query($queryParams);
		}

        return md5($uri->withFragment('')->withQuery($query));
    }

    /**
     * Met en cache la réponse.
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
            serialize([
				'headers' => $headers,
				'output'  => $response->getBody()->getContents(),
				'status'  => $response->getStatusCode(),
				'reason'  => $response->getReasonPhrase(),
			]),
            $this->ttl
        );
    }

    /**
     * Obtient la réponse mise en cache pour la demande.
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        if ($cachedResponse = $this->cache->get($this->generateCacheKey($request))) {
            if (!is_string($cachedResponse)) {
				throw new Exception('Données de cache corrompues');
			}

			$cachedResponse = unserialize($cachedResponse, ['allowed_classes' => false]);

			if (!is_array($cachedResponse) || !isset($cachedResponse['output'], $cachedResponse['headers'])) {
				throw new Exception('Structure de cache invalide');
			}

			// Validation des headers
			if (!is_array($cachedResponse['headers'])) {
				throw new Exception('Headers de cache invalides');
			}

            $headers = $cachedResponse['headers'];
            $output  = $cachedResponse['output'];
			$status  = $cachedResponse['status'] ?? 200;
            $reason  = $cachedResponse['reason'] ?? '';

            // Effacer tous les en-têtes par défaut
            foreach (array_keys($response->getHeaders()) as $key) {
                $response = $response->withoutHeader($key);
            }

            // Définir les en-têtes mis en cache
            foreach ($headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response->withBody(to_stream($output))
				->withStatus($status, $reason);
        }

        return null;
    }
}
