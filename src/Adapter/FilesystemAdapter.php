<?php

namespace Hyqo\Cache\Adapter;

use Generator;
use Hyqo\Cache\CacheItem;
use Hyqo\Cache\CachePoolInterface;

use JetBrains\PhpStorm\ArrayShape;

use const DIRECTORY_SEPARATOR;

class FilesystemAdapter implements CachePoolInterface
{
    protected ?string $folder;

    public function __construct(
        protected string $namespace = '@',
        ?string $directory = null,
        protected int $ttl = 31556952,
    ) {
        $directory ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hyqo-cache';

        $this->folder = $directory . DIRECTORY_SEPARATOR . $this->namespace;

        if (!is_dir($this->folder)) {
            @mkdir($this->folder, 0777, true);
        }
    }

    public function hasItem(string $key): bool
    {
        $path = $this->path($key);

        return is_file($path) && (@filemtime($path) > time() || $this->load($path));
    }

    public function getItem(string $key, ?callable $handle = null): CacheItem
    {
        $path = $this->path($key);

        if (null !== $item = $this->load($path)) {
            return $item;
        }

        $item = new CacheItem($key, false);

        if (null !== $handle) {
            $handle($item);
            $this->save($item);
        }

        return $item;
    }

    public function delete(string $key): bool
    {
        return @unlink($this->path($key));
    }

    public function save(CacheItem $item): bool
    {
        if (null === $item->getExpiresAt()) {
            $item->expiresAt(time() + $this->ttl);
        }

        $path = $this->path($item->getKey());

        $this->doWrite($path, $item);

        return true;
    }

    public function flush(): bool
    {
        return $this->doFlush($this->folder);
    }

    protected function doFlush(string $folder): true
    {
        foreach ($this->scan($folder) as $node) {
            if (is_dir($node)) {
                @rmdir($node);
            } else {
                @unlink($node);
            }
        }

        return true;
    }

    protected function scan(string $folder): Generator
    {
        foreach (glob("$folder/*") as $node) {
            if (is_dir($node)) {
                yield from $this->scan($node);
            }

            yield $node;
        }
    }

    protected function path(string $key): string
    {
        $hash = md5("$this->namespace:$key");
        $folder = $this->folder . DIRECTORY_SEPARATOR . $hash[0] . DIRECTORY_SEPARATOR . $hash[1];

        @mkdir($folder, 0777, true);

        return $folder . DIRECTORY_SEPARATOR . substr($hash, 2);
    }

    protected function load(string $path): ?CacheItem
    {
        if (!is_file($path) || !$res = @fopen($path, 'rb')) {
            return null;
        }

        if (!($expiresAt = (int)@fgets($res)) || $expiresAt <= time()) {
            @unlink($path);
            return null;
        }

        if (null === $data = $this->doRead($res)) {
            return null;
        }

        return (new CacheItem($data['key'], true, $data['value']))->tag($data['tags'])->expiresAt($expiresAt);
    }

    protected function pack(CacheItem $item): string
    {
        return $item->getExpiresAt() . PHP_EOL . $item->getKey() . PHP_EOL . igbinary_serialize($item->get());
    }

    #[ArrayShape(['key' => 'string', 'tags' => 'array', 'value' => 'mixed'])]
    protected function doRead($res): ?array
    {
        $key = rtrim(@fgets($res));

        $value = stream_get_contents($res);
        $value = igbinary_unserialize($value);

        if (false === $value) {
            return null;
        }

        return ['key' => $key, 'tags' => [], 'value' => $value];
    }

    protected function doWrite(string $path, CacheItem $item): bool
    {
        $tmp = $this->folder . DIRECTORY_SEPARATOR . md5($path) . bin2hex(random_bytes(6));

        file_put_contents($tmp, $this->pack($item));

        if (null !== $item->getExpiresAt()) {
            touch($tmp, $item->getExpiresAt());
        }

        return rename($tmp, $path);
    }
}
