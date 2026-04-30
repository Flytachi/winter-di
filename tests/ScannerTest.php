<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Collector\DICollector;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use Flytachi\Winter\DI\Scanner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// ── Fixtures ──────────────────────────────────────────────────────────────────

final class RecordingCollector implements CollectorInterface
{
    public array $collected = [];

    public function collect(string $class, ReflectionClass $ref): void
    {
        $this->collected[] = $class;
    }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

final class ScannerTest extends TestCase
{
    private string $tmpDir;
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->tmpDir    = sys_get_temp_dir() . '/winter-di-test-' . uniqid();
        $this->cacheFile = $this->tmpDir . '/scanner.cache.php';
        mkdir($this->tmpDir, 0755, true);
        Container::init();
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tmpDir);
    }

    // ── Basic scan ────────────────────────────────────────────────────────────

    public function test_scanner_discovers_classes_in_directory(): void
    {
        $this->writeClass('Alpha.php', 'Alpha', '#[Singleton]');
        $this->writeClass('Beta.php',  'Beta',  '#[Transient]');

        $collector = new RecordingCollector();

        Scanner::run($this->tmpDir)
            ->collect($collector)
            ->execute();

        $this->assertContains('TestNs\\Alpha', $collector->collected);
        $this->assertContains('TestNs\\Beta',  $collector->collected);
    }

    public function test_scanner_skips_abstract_classes(): void
    {
        $this->writeRaw('Abstract.php', '<?php namespace TestNs; abstract class AbstractThing {}');

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir)->collect($collector)->execute();

        $this->assertNotContains('TestNs\\AbstractThing', $collector->collected);
    }

    public function test_scanner_skips_interfaces(): void
    {
        $this->writeRaw('IFoo.php', '<?php namespace TestNs; interface IFoo {}');

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir)->collect($collector)->execute();

        $this->assertNotContains('TestNs\\IFoo', $collector->collected);
    }

    public function test_scanner_skips_traits(): void
    {
        $this->writeRaw('MyTrait.php', '<?php namespace TestNs; trait MyTrait {}');

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir)->collect($collector)->execute();

        $this->assertNotContains('TestNs\\MyTrait', $collector->collected);
    }

    // ── Exclusions ────────────────────────────────────────────────────────────

    public function test_vendor_dir_is_always_excluded(): void
    {
        $vendor = $this->tmpDir . '/vendor';
        mkdir($vendor);
        file_put_contents($vendor . '/Vendored.php', '<?php namespace TestNs; class Vendored {}');

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir)->collect($collector)->execute();

        $this->assertNotContains('TestNs\\Vendored', $collector->collected);
    }

    public function test_custom_exclusion_is_respected(): void
    {
        $excluded = $this->tmpDir . '/legacy';
        mkdir($excluded);
        file_put_contents($excluded . '/Old.php', '<?php namespace TestNs; class OldClass {}');
        $this->writeClass('Current.php', 'Current', '');

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir)
            ->exclude([$excluded])
            ->collect($collector)
            ->execute();

        $this->assertNotContains('TestNs\\OldClass', $collector->collected);
        $this->assertContains('TestNs\\Current', $collector->collected);
    }

    // ── Multiple collectors ───────────────────────────────────────────────────

    public function test_multiple_collectors_all_receive_same_classes(): void
    {
        $this->writeClass('Gamma.php', 'Gamma', '');

        $a = new RecordingCollector();
        $b = new RecordingCollector();

        Scanner::run($this->tmpDir)
            ->collect($a)
            ->collect($b)
            ->execute();

        $this->assertContains('TestNs\\Gamma', $a->collected);
        $this->assertContains('TestNs\\Gamma', $b->collected);
    }

    // ── DICollector integration ───────────────────────────────────────────────

    public function test_di_collector_registers_singleton(): void
    {
        $this->writeClass('MySingleton.php', 'MySingleton', '#[\\Flytachi\\Winter\\DI\\Attribute\\Singleton]');

        $container = Container::init();
        Scanner::run($this->tmpDir)
            ->collect(new DICollector($container))
            ->execute();

        $a = $container->make('TestNs\\MySingleton');
        $b = $container->make('TestNs\\MySingleton');
        $this->assertSame($a, $b);
    }

    public function test_di_collector_registers_transient(): void
    {
        $this->writeClass('MyTransient.php', 'MyTransient', '#[\\Flytachi\\Winter\\DI\\Attribute\\Transient]');

        $container = Container::init();
        Scanner::run($this->tmpDir)
            ->collect(new DICollector($container))
            ->execute();

        $a = $container->make('TestNs\\MyTransient');
        $b = $container->make('TestNs\\MyTransient');
        $this->assertNotSame($a, $b);
    }

    // ── Cache ─────────────────────────────────────────────────────────────────

    public function test_cache_file_is_created_after_first_scan(): void
    {
        $this->writeClass('Delta.php', 'Delta', '');

        Scanner::run($this->tmpDir, cache: $this->cacheFile)
            ->collect(new RecordingCollector())
            ->execute();

        $this->assertFileExists($this->cacheFile);
    }

    public function test_cache_contains_discovered_classes(): void
    {
        $this->writeClass('Epsilon.php', 'Epsilon', '');

        Scanner::run($this->tmpDir, cache: $this->cacheFile)
            ->collect(new RecordingCollector())
            ->execute();

        $cached = require $this->cacheFile;
        $this->assertContains('TestNs\\Epsilon', $cached);
    }

    public function test_cache_hit_skips_filesystem_walk(): void
    {
        // Pre-populate cache with a class that doesn't exist on disk
        file_put_contents($this->cacheFile, "<?php\nreturn [];\n");

        $collector = new RecordingCollector();
        Scanner::run($this->tmpDir, cache: $this->cacheFile)
            ->collect($collector)
            ->execute();

        // Cache was empty → no classes dispatched, but no FS error either
        $this->assertEmpty($collector->collected);
    }

    public function test_no_cache_does_not_create_file(): void
    {
        $this->writeClass('Zeta.php', 'Zeta', '');

        Scanner::run($this->tmpDir)
            ->collect(new RecordingCollector())
            ->execute();

        $this->assertFileDoesNotExist($this->cacheFile);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function writeClass(string $filename, string $className, string $attribute): void
    {
        $attr = $attribute ? $attribute . "\n" : '';
        $this->writeRaw($filename, "<?php\nnamespace TestNs;\n{$attr}class {$className} {}");
    }

    private function writeRaw(string $filename, string $content): void
    {
        file_put_contents($this->tmpDir . '/' . $filename, $content);
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
