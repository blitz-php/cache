<?php

/**
 * This file is part of Blitz PHP framework.
 *
 * (c) 2022 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Cache\Handlers;

use DateInterval;

final class FileVarExportHandler extends BaseHandler
{
	private string $path = FRAMEWORK_STORAGE_PATH . 'cache';

    /**
     * {@inheritDoc}
     */
    public function isSupported(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function init(array $config = []): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
	 *
	 * @param array|bool|float|int|object|string|null $value
     */
    public function set(string $key, mixed $value, null|DateInterval|int $ttl = null): bool
    {
        $value = var_export($value, true);

        // Écrire d'abord dans le fichier temporaire pour assurer l'atomicité
        $tmp = $this->path . "/{$key}." . uniqid('', true) . '.tmp';
        file_put_contents($tmp, '<?php return ' . $value . ';', LOCK_EX);

        return rename($tmp, $this->path . "/{$key}");
    }

    /**
     * {@inheritDoc}
     */
    public function setMultiple(iterable $values, null|DateInterval|int $ttl = null): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
	 *
	 * @return array|bool|float|int|object|string|null
     */
    public function get(string $key, mixed $default = null): mixed
    {
		return @include $this->path . "/{$key}";
	}

    /**
     * {@inheritDoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function increment(string $key, int $offset = 1)
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function decrement(string $key, int $offset = 1)
    {
        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
		return @unlink($this->path . "/{$key}");
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
		if (! is_dir($this->path)) {
			return false;
		}

		$files = glob($this->path . '/*');
		foreach ($files as $file) {
			if (is_file($file)) {
				unlink($file);
			} elseif (is_dir($file)) {
				$this->clear($file);
			}
		}

		return rmdir($this->path);
	}

    /**
     * {@inheritDoc}
     */
    public function clearGroup(string $group): bool
    {
        return true;
    }
}
