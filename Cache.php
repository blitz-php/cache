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

use ArrayAccess;
use BlitzPHP\Contracts\Cache\CacheInterface;
use BlitzPHP\Contracts\Cache\RepositoryInterface;
use BlitzPHP\Contracts\Event\EventInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Traits\Support\InteractsWithTime;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection;
use Closure;
use DateInterval;
use DateTimeInterface;
use UnitEnum;
/**
 * Dépôt de cache principal de l'application BlitzPHP.
 *
 * Cette classe implémente le pattern Repository pour le système de cache,
 * offrant une couche d'abstraction au-dessus des différents pilotes de cache
 * (fichiers, Redis, Memcached, etc.) via le gestionnaire {@see Manager}.
 *
 * @credit <a href="http://www.laravel.com">Laravel - Illuminate\Cache\Repository</a>
 */
class Cache implements ArrayAccess, RepositoryInterface
{
    use InteractsWithTime, Macroable {
        __call as macroCall;
    }

    /**
     * L'implémentation de l'interface de cache.
     */
	protected Manager $manager;

    /**
     * Le nombre de secondes par défaut pour stocker les éléments.
     */
    protected ?int $default = 3600;

    /**
     * L'implémentation du gestionnaire d'événements.
     */
    protected ?EventManagerInterface $events = null;

    /**
     * Crée une nouvelle instance du dépôt de cache.
     */
    public function __construct(protected array $config = [])
    {
		$this->manager = new Manager($config);
    }

	/**
     * Modifie les configuration du cache pour la fabrique actuelle
     */
    public function setConfig(array $config): self
    {
		$this->manager->setConfig($config);

        return $this;
    }

	/**
     * Réactive la mise en cache.
     *
     * Si la mise en cache a été désactivée avec Cache::disable(), cette méthode inversera cet effet.
     */
    public static function enable(): void
    {
		Manager::enable();
    }

    /**
     * Désactive la mise en cache.
     *
     * Lorsqu'elle est désactivée, toutes les opérations de cache renverront null.
     */
    public static function disable(): void
    {
		Manager::disable();
    }

    /**
     * Vérifie si la mise en cache est activée.
     */
    public static function enabled(): bool
    {
        return Manager::enabled();
    }

    /**
     * Détermine si un élément existe dans le cache.
     */
    public function has(UnitEnum|array|string $key): bool
    {
        return null !== $this->get($key);
    }

    /**
     * Détermine si un élément n'existe pas dans le cache.
     */
    public function missing(UnitEnum|string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Récupère un élément du cache par sa clé.
     */
    public function get(UnitEnum|array|string $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

		$key = $this->enumValue($key);

        $value = $this->manager->get($this->itemKey($key));

        // Si nous ne trouvons pas la valeur du cache, nous déclenchons l'événement "missed" et récupérons
        // la valeur par défaut pour cette valeur de cache. Cette valeur par défaut peut être un callback
        // donc nous exécutons la fonction de valeur qui la résoudra si nécessaire.
        if (is_null($value)) {
            $value = Helpers::value($default);
        }

        return $value;
    }

    /**
     * Récupère plusieurs éléments du cache par leurs clés.
     *
     * Les éléments non trouvés dans le cache auront une valeur nulle.
     */
    public function many(array $keys)
    {
        $values = $this->manager->getMultiple((new Collection($keys))
			->map(fn ($value, $key) => is_string($key) ? $key : $this->enumValue($value))
			->values()
			->all()
		);

        return (new Collection($values))
            ->map(fn ($value, $key) => $this->handleManyResult($keys, $key, $value))
            ->all();
    }

    /**
     * {@inheritdoc}
     */
	public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $defaults = [];

        foreach ($keys as $key) {
            $defaults[$this->enumValue($key)] = $default;
        }

        return $this->many($defaults);
    }

    /**
     * Traite un résultat pour la méthode "many".
     */
    protected function handleManyResult(array $keys, string $key, mixed $value): mixed
    {
        // Si nous ne trouvons pas la valeur du cache, nous déclenchons l'événement "missed" et récupérons
        // la valeur par défaut pour cette valeur de cache. Cette valeur par défaut peut être un callback
        // donc nous exécutons la fonction de valeur qui la résoudra si nécessaire.
        if (is_null($value)) {
            return (isset($keys[$key]) && ! array_is_list($keys)) ? Helpers::value($keys[$key]) : null;
        }

        return $value;
    }

    /**
     * Récupère un élément du cache et le supprime.
     */
    public function pull(UnitEnum|array|string $key, mixed $default = null): mixed
    {
		return Helpers::tap($this->get($key, $default), function () use ($key) {
            $this->forget($key);
        });
    }

    /**
     * Récupère un élément de type chaîne de caractères depuis le cache.
     *
     * @param  (\Closure():(string|null))|string|null  $default
     *
     * @throws InvalidArgumentException
     */
    public function string(UnitEnum|string $key, mixed $default = null): string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                sprintf('La valeur du cache pour la clé [%s] doit être une chaîne de caractères, %s fourni.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Récupère un élément de type entier depuis le cache.
     *
     * @param  (\Closure():(int|null))|int|null  $default
     *
     * @throws InvalidArgumentException
     */
    public function integer(UnitEnum|string $key, mixed $default = null): int
    {
        $value = $this->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            sprintf('La valeur du cache pour la clé [%s] doit être un entier, %s fourni.', $key, gettype($value))
        );
    }

    /**
     * Récupère un élément de type nombre flottant depuis le cache.
     *
     * @param  (\Closure():(float|null))|float|null  $default
     *
     * @throws InvalidArgumentException
     */
    public function float(UnitEnum|string $key, mixed $default = null): float
    {
        $value = $this->get($key, $default);

        if (is_float($value)) {
            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
            return (float) $value;
        }

        throw new InvalidArgumentException(
            sprintf('La valeur du cache pour la clé [%s] doit être un nombre flottant, %s fourni.', $key, gettype($value))
        );
    }

    /**
     * Récupère un élément de type booléen depuis le cache.
     *
     * @throws InvalidArgumentException
     */
    public function boolean(UnitEnum|string $key, mixed $default = null): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('La valeur du cache pour la clé [%s] doit être un booléen, %s fourni.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Récupère un élément de type tableau depuis le cache.
     *
     * @param  (\Closure():(array<array-key, mixed>|null))|array<array-key, mixed>|null  $default
	 *
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function array(UnitEnum|string $key, $default = null): array
    {
        $value = $this->get($key, $default);

        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf('La valeur du cache pour la clé [%s] doit être un tableau, %s fourni.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Stocke un élément dans le cache.
     */
    public function put(UnitEnum|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = $this->enumValue($key);

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        return $this->manager->write($this->itemKey($key), $value, $seconds);
    }

    /**
     * Stocke un élément dans le cache.
     */
    public function set(UnitEnum|array|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * Stocke plusieurs éléments dans le cache pour un nombre de secondes donné.
     */
    public function putMany(array $values, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->putManyForever($values);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->deleteMultiple(array_keys($values));
        }

        return $this->manager->writeMany($values, $seconds);
    }

    /**
     * Stocke plusieurs éléments dans le cache indéfiniment.
     */
    protected function putManyForever(array $values): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (! $this->forever($key, $value)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(iterable $values, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->putMany(is_array($values) ? $values : iterator_to_array($values), $ttl);
    }

    /**
     * Stocke un élément dans le cache si la clé n'existe pas.
     */
    public function add(UnitEnum|array|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        $key = $this->enumValue($key);

        $seconds = null;

        if ($ttl !== null) {
            $seconds = $this->getSeconds($ttl);

            if ($seconds <= 0) {
                return false;
            }

            // Si le magasin dispose d'une méthode "add", nous l'appellerons sur le magasin afin qu'il
            // ait la possibilité de remplacer cette logique. Certains pilotes supportent mieux
            // cette opération avec une implémentation totalement "atomique".
            if (method_exists($this->manager, 'add')) {
                return $this->manager->add(
                    $this->itemKey($key), $value, $seconds
                );
            }
        }

        // Si la valeur n'existait pas dans le cache, nous la stockons dans le cache
        // afin qu'elle existe pour les requêtes ultérieures. Ensuite, nous retournons true
        // pour savoir facilement si la valeur a été ajoutée. Sinon, nous retournons false.
        if (is_null($this->get($key))) {
            return $this->put($key, $value, $seconds);
        }

        return false;
    }

    /**
     * Incrémente la valeur d'un élément dans le cache.
     */
    public function increment(UnitEnum|string $key, mixed $value = 1): int|bool
    {
        return $this->manager->increment($this->enumValue($key), $value);
    }

    /**
     * Décrémente la valeur d'un élément dans le cache.
     */
    public function decrement(UnitEnum|string $key, mixed $value = 1): int|bool
    {
        return $this->manager->decrement($this->enumValue($key), $value);
    }

    /**
     * Stocke un élément dans le cache indéfiniment.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = $this->enumValue($key);

        return $this->manager->forever($this->itemKey($key), $value);
    }

    /**
     * Récupère un élément du cache, ou exécute la Closure donnée et stocke le résultat.
     *
     * @template TCacheValue
     *
     * @param  \Closure(): TCacheValue  $callback
	 *
     * @return TCacheValue
     */
    public function remember(UnitEnum|string $key, callable|DateTimeInterface|DateInterval|int|null $ttl, ?callable $callback = null): mixed
    {
        $value = $this->get($key);

        // Si l'élément existe dans le cache, nous le retournons immédiatement.
        // Sinon, nous exécutons la Closure donnée et mettons en cache le résultat
        // pour un nombre de secondes donné afin qu'il soit disponible pour les requêtes ultérieures.
        if (! is_null($value)) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, Helpers::value($ttl, $value));

        return $value;
    }

    /**
     * Récupère un élément du cache, ou exécute la Closure donnée et stocke le résultat indéfiniment.
     *
     * @template TCacheValue
     *
     * @param  \Closure(): TCacheValue  $callback
     *
	 * @return TCacheValue
     */
    public function sear(UnitEnum|string $key, Closure $callback): mixed
    {
        return $this->rememberForever($key, $callback);
    }

    /**
     * Récupère un élément du cache, ou exécute la Closure donnée et stocke le résultat indéfiniment.
     *
     * @template TCacheValue
     *
     * @param  \Closure(): TCacheValue  $callback
     *
	 * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed
    {
        $value = $this->get($key);

        // Si l'élément existe dans le cache, nous le retournons immédiatement.
        // Sinon, nous exécutons la Closure donnée et mettons en cache le résultat
        // indéfiniment afin qu'il soit disponible pour les requêtes ultérieures.
        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Définit la date d'expiration d'un élément mis en cache.
     */
    public function touch(UnitEnum|string $key, DateTimeInterface|DateInterval|int $ttl): bool
    {
		return $this->manager->touch($this->itemKey($key), $this->getSeconds($ttl));
    }

    /**
     * Supprime un élément du cache.
     */
    public function forget(UnitEnum|array|string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Supprime un élément du cache.
     */
    public function delete(UnitEnum|array|string $key): bool
    {
        $key = $this->enumValue($key);

        return $this->manager->delete($this->itemKey($key));
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        $result = true;

        foreach ($keys as $key) {
            if (! $this->forget($key)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        return $this->manager->clear();
    }

	/**
     * {@inheritDoc}
     */
    public function clearGroup(string $group): bool
    {
        return $this->manager->clearGroup($group);
    }

    /**
     * {@inheritDoc}
     */
    public function info()
    {
        return $this->factory()->info();
    }

    /**
     * Formate la clé pour un élément du cache.
     */
    protected function itemKey(string $key): string
    {
        return $key;
    }

	protected function enumValue(mixed $value): mixed
	{
		return $value instanceof UnitEnum ? $value->name : $value;
	}

    /**
     * Calcule le nombre de secondes pour le TTL donné.
     */
    protected function getSeconds(DateTimeInterface|DateInterval|int $ttl): int
    {
        $duration = $this->parseDateInterval($ttl);

        if ($duration instanceof DateTimeInterface) {
            $duration = Date::now()->diffInSeconds($duration);
        }

        return (int) ($duration > 0 ? $duration : 0);
    }

    /**
     * Récupère le nom du magasin de cache.
     */
    public function getName(): ?string
    {
        return $this->config['handler'] ?? $this->config['fallback_handler'] ?? null;
    }

    /**
     * Détermine si le magasin actuel supporte les tags.
     */
    public function supportsTags(): bool
    {
        return method_exists($this->manager, 'tags');
    }

    /**
     * Récupère le temps de cache par défaut.
     */
    public function getDefaultCacheTime(): ?int
    {
        return $this->default;
    }

    /**
     * Définit le temps de cache par défaut en secondes.
     */
    public function setDefaultCacheTime(?int $seconds): self
    {
        $this->default = $seconds;

        return $this;
    }

	/**
	 * {@inheritDoc}
	 */
	public function getStore(): CacheInterface
	{
		return $this->getManager();
	}

    /**
     * Récupère l'implémentation du gestionnaire de cache.
     */
    public function getManager(): CacheInterface
    {
        return $this->manager;
    }

    /**
     * Définit l'implémentation du gestionnaire de cache.
     */
    public function setManager(CacheInterface $manager): static
    {
        $this->manager = $manager;

        return $this;
    }

    /**
     * Déclenche un événement pour cette instance de cache.
     */
    protected function event(string|EventInterface $event): void
    {
        $this->events?->emit($event);
    }

    /**
     * Récupère le répartiteur d'événements.
     */
    public function getEventDispatcher(): ?EventManagerInterface
    {
        return $this->events;
    }

    /**
     * Définit le répartiteur d'événements.
     */
    public function setEventManager(EventManagerInterface $events): void
    {
        $this->events = $events;
    }

    /**
     * Détermine si une valeur mise en cache existe.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Récupère un élément du cache par sa clé.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Stocke un élément dans le cache pour le temps par défaut.
     *
     * @param UnitEnum|string  $offset
     */
    public function offsetSet($offset, mixed $value): void
    {
        $this->put($offset, $value, $this->default);
    }

    /**
     * Supprime un élément du cache.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetUnset($offset): void
    {
        $this->forget($offset);
    }

    /**
     * Gère les appels dynamiques vers les macros ou transmet les méthodes manquantes au magasin.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->manager->$method(...$parameters);
    }

    /**
     * Clone l'instance du dépôt de cache.
     */
    public function __clone(): void
    {
        $this->manager = clone $this->manager;
    }
}
