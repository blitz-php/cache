<?php

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
use InvalidArgumentException;
use UnitEnum;

class Repository implements ArrayAccess, RepositoryInterface
{
    use InteractsWithTime, Macroable {
        __call as macroCall;
    }

    /**
     * The cache interface implementation.
     */
	protected Cache $store;

    /**
     * The default number of seconds to store items.
     */
    protected ?int $default = 3600;

    /**
     * The event manager implementation.
     */
    protected ?EventManagerInterface $events = null;

    /**
     * Create a new cache repository instance.
     */
    public function __construct(protected array $config = [])
    {
		$this->store = new Cache($config);
    }

    /**
     * Determine if an item exists in the cache.
     */
    public function has(UnitEnum|array|string $key): bool
    {
        return null !== $this->get($key);
    }

    /**
     * Determine if an item doesn't exist in the cache.
     */
    public function missing(UnitEnum|string $key): bool
    {
        return ! $this->has($key);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(UnitEnum|array|string $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

		$key = $this->enumValue($key);

        $value = $this->store->get($this->itemKey($key));

        // If we could not find the cache value, we will fire the missed event and get
        // the default value for this cache value. This default could be a callback
        // so we will execute the value function which will resolve it if needed.
        if (is_null($value)) {
            $value = Helpers::value($default);
        }

        return $value;
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys)
    {
        $values = $this->store->getMultiple((new Collection($keys))
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
     * Handle a result for the "many" method.
     */
    protected function handleManyResult(array $keys, string $key, mixed $value): mixed
    {
        // If we could not find the cache value, we will fire the missed event and get
        // the default value for this cache value. This default could be a callback
        // so we will execute the value function which will resolve it if needed.
        if (is_null($value)) {
            return (isset($keys[$key]) && ! array_is_list($keys)) ? Helpers::value($keys[$key]) : null;
        }

        return $value;
    }

    /**
     * Retrieve an item from the cache and delete it.
     */
    public function pull(UnitEnum|array|string $key, mixed $default = null): mixed
    {
		return Helpers::tap($this->get($key, $default), function () use ($key) {
            $this->forget($key);
        });
    }

    /**
     * Retrieve a string item from the cache.
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
                sprintf('Cache value for key [%s] must be a string, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an integer item from the cache.
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
            sprintf('Cache value for key [%s] must be an integer, %s given.', $key, gettype($value))
        );
    }

    /**
     * Retrieve a float item from the cache.
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
            sprintf('Cache value for key [%s] must be a float, %s given.', $key, gettype($value))
        );
    }

    /**
     * Retrieve a boolean item from the cache.
     *
     * @throws InvalidArgumentException
     */
    public function boolean(UnitEnum|string $key, mixed $default = null): bool
    {
        $value = $this->get($key, $default);

        if (! is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('Cache value for key [%s] must be a boolean, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Retrieve an array item from the cache.
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
                sprintf('Cache value for key [%s] must be an array, %s given.', $key, gettype($value))
            );
        }

        return $value;
    }

    /**
     * Store an item in the cache.
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

        return $this->store->write($this->itemKey($key), $value, $seconds);
    }

    /**
     * Store an item in the cache.
     */
    public function set(UnitEnum|array|string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->put($key, $value, $ttl);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
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

        return $this->store->writeMany($values, $seconds);
    }

    /**
     * Store multiple items in the cache indefinitely.
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
     * Store an item in the cache if the key does not exist.
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

            // If the store has an "add" method we will call the method on the store so it
            // has a chance to override this logic. Some drivers better support the way
            // this operation should work with a total "atomic" implementation of it.
            if (method_exists($this->store, 'add')) {
                return $this->store->add(
                    $this->itemKey($key), $value, $seconds
                );
            }
        }

        // If the value did not exist in the cache, we will put the value in the cache
        // so it exists for subsequent requests. Then, we will return true so it is
        // easy to know if the value gets added. Otherwise, we will return false.
        if (is_null($this->get($key))) {
            return $this->put($key, $value, $seconds);
        }

        return false;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, mixed $value = 1): int|bool
    {
        return $this->store->increment($this->enumValue($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, mixed $value = 1): int|bool
    {
        return $this->store->decrement($this->enumValue($key), $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = $this->enumValue($key);

        return $this->store->forever($this->itemKey($key), $value);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
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

        // If the item exists in the cache we will just return this immediately and if
        // not we will execute the given Closure and cache the result of that for a
        // given number of seconds so it's available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, Helpers::value($ttl, $value));

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
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
     * Get an item from the cache, or execute the given Closure and store the result forever.
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

        // If the item exists in the cache we will just return this immediately
        // and if not we will execute the given Closure and cache the result
        // of that forever so it is available for all subsequent requests.
        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Set the expiration of a cached item.
     */
    public function touch(UnitEnum|string $key, DateTimeInterface|DateInterval|int $ttl): bool
    {
		return $this->store->touch($this->itemKey($key), $this->getSeconds($ttl));
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(UnitEnum|array|string $key): bool
    {
        return $this->delete($key);
    }

    /**
     * Remove an item from the cache.
     */
    public function delete(UnitEnum|array|string $key): bool
    {
        $key = $this->enumValue($key);

        return $this->store->delete($this->itemKey($key));
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
        return $this->store->clear();
    }

	/**
     * {@inheritDoc}
     */
    public function clearGroup(string $group): bool
    {
        return $this->store->clearGroup($group);
    }

    /**
     * {@inheritDoc}
     */
    public function info()
    {
        return $this->factory()->info();
    }

	/**
     * Réactivez la mise en cache.
     *
     * Si la mise en cache a été désactivée avec Cache::disable() cette méthode inversera cet effet.
     */
    public static function enable(): void
    {
		Cache::enable();
    }

    /**
     * Désactivez la mise en cache.
     *
     * Lorsqu'il est désactivé, toutes les opérations de cache renverront null.
     */
    public static function disable(): void
    {
		Cache::disable();
    }

    /**
     * Vérifiez si la mise en cache est activée.
     */
    public static function enabled(): bool
    {
        return Cache::enabled();
    }

    /**
     * Format the key for a cache item.
     */
    protected function itemKey(string $key): string
    {
        return $key;
    }

    /**
     * Calculate the number of seconds for the given TTL.
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
     * Get the name of the cache store.
     */
    public function getName(): ?string
    {
        return $this->config['handler'] ?? $this->config['fallback_handler'] ?? null;
    }

    /**
     * Determine if the current store supports tags.
     */
    public function supportsTags(): bool
    {
        return method_exists($this->store, 'tags');
    }

    /**
     * Get the default cache time.
     */
    public function getDefaultCacheTime(): ?int
    {
        return $this->default;
    }

    /**
     * Set the default cache time in seconds.
     */
    public function setDefaultCacheTime(?int $seconds): self
    {
        $this->default = $seconds;

        return $this;
    }

    /**
     * Get the cache store implementation.
     */
    public function getStore(): CacheInterface
    {
        return $this->store;
    }

    /**
     * Set the cache store implementation.
     */
    public function setStore(CacheInterface $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(string|EventInterface $event): void
    {
        $this->events?->emit($event);
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?EventManagerInterface
    {
        return $this->events;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setEventManager(EventManagerInterface $events): void
    {
        $this->events = $events;
    }

    /**
     * Determine if a cached value exists.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Store an item in the cache for the default time.
     *
     * @param UnitEnum|string  $offset
     */
    public function offsetSet($offset, mixed $value): void
    {
        $this->put($offset, $value, $this->default);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  UnitEnum|string  $offset
     */
    public function offsetUnset($offset): void
    {
        $this->forget($offset);
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the store.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->store->$method(...$parameters);
    }

    /**
     * Clone cache repository instance.
     */
    public function __clone(): void
    {
        $this->store = clone $this->store;
    }

	protected function enumValue(mixed $value): mixed
	{
		return $value instanceof UnitEnum ? $value->name : $value;
	}
}
