<?php

namespace Hyqo\Cache\Test\Adapter;

use Hyqo\Cache\CacheItem;
use Hyqo\Cache\Adapter\FilesystemAdapter;
use Hyqo\Finder\Finder;
use PHPUnit\Framework\TestCase;

class FilesystemAdapterTest extends TestCase
{
    protected string $cacheFolder = __DIR__ . '/../var/cache';
    protected string $namespaceFolder = __DIR__ . '/../var/cache/@';

    protected FilesystemAdapter $cache;

    protected Finder $finder;

    protected array $files;

    protected function setUp(): void
    {
        $this->files = [
            'foo_corrupted' => "$this->namespaceFolder/6/c/858b6f61f35ecba679598f5359c0e5",
            'bar_missed' => "$this->namespaceFolder/7/f/36619b54c0c861e82452c168549d30",
            'baz_expired' => "$this->namespaceFolder/5/d/dd2d04662de4a76800a586b89d1405",
        ];

        $this->finder = new Finder();

        $this->cache = new FilesystemAdapter('@', $this->cacheFolder);

        foreach (
            [
                'foo_corrupted' => time() + 10,
                'baz_expired' => time() - 10,
            ] as $file => $mtime
        ) {
            $this->finder->save($this->files[$file], $mtime);
            touch($this->files[$file], $mtime);
        }
    }

    protected function tearDown(): void
    {
        $this->finder->removeFolder($this->cacheFolder);
    }

    public function test_create_folder(): void
    {
        $this->assertDirectoryExists($this->namespaceFolder);
    }

    public function test_flush(): void
    {
        $this->assertFalse($this->finder->isEmpty($this->namespaceFolder));

        $result = $this->cache->flush();

        $this->assertTrue($result);
        $this->assertTrue($this->finder->isEmpty($this->namespaceFolder));
    }

    public function test_delete(): void
    {
        $this->assertTrue($this->cache->delete('foo'));
        $this->assertFileDoesNotExist($this->files['foo_corrupted']);

        $this->assertFalse($this->cache->delete('bar'));
    }

    public function test_save(): void
    {
        $item = new CacheItem('bar');

        $this->cache->save($item);
        $this->assertFileExists($this->files['bar_missed']);
    }

    public function test_handle_missed(): void
    {
        $expiresAt = time() + 1000;

        $missed = $this->cache->getItem('bar', fn(CacheItem $item) => $item->set(123)->expiresAt($expiresAt));

        $this->assertFalse($missed->isHit);
        $this->assertFileExists($this->files['bar_missed']);
        $this->assertEquals($expiresAt, filemtime($this->files['bar_missed']));

        $hit = $this->cache->getItem('bar');

        $this->assertTrue($hit->isHit);
        $this->assertEquals(123, $hit->get());
        $this->assertEquals($expiresAt, $hit->getExpiresAt());
    }

    public function test_handle_expired(): void
    {
        $missed = $this->cache->getItem('baz');

        $this->assertFalse($missed->isHit);
        $this->assertFileDoesNotExist($this->files['baz_expired']);
    }

    public function test_has_item(): void
    {
        $this->assertTrue($this->cache->hasItem('foo'));
        $this->assertFalse($this->cache->hasItem('baz'));
        $this->assertFileDoesNotExist($this->files['baz_expired']);
    }

    public function test_get_corrupted(): void
    {
        $item = $this->cache->getItem('foo');

        $this->assertFalse($item->isHit);
        $this->assertFileExists($this->files['foo_corrupted']);
    }
}
