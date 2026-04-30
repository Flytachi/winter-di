<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI;

use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;

/**
 * Unified directory scanner — walks a project tree once and dispatches
 * each discovered class to all registered collectors.
 *
 * Without cache (always scans — use for PPA, Cmd, Db, dev mode):
 * ```
 *   Scanner::run($rootDir)
 *       ->collect(new PpaCollector())
 *       ->collect(new CmdCollector())
 *       ->execute();
 * ```
 *
 * With cache (production — filesystem walk only on first boot):
 * ```
 *   Scanner::run($rootDir, cache: $cachePath)
 *       ->collect(new DICollector($container))
 *       ->collect(new MappingCollector($router))
 *       ->collect(new ExceptionCollector())
 *       ->execute();
 * ```
 *
 * Multiple collectors share a single filesystem pass — no duplicate tree walks.
 * The cache stores only the list of discovered FQCNs, not collector results.
 * vendor/ is always excluded. Additional exclusions via exclude().
 */
final class Scanner
{
    /** @var CollectorInterface[] */
    private array $collectors = [];

    /** @var string[] */
    private array $exclude = [];

    private function __construct(
        private readonly string  $rootDir,
        private readonly ?string $cachePath,
    ) {
        $this->exclude[] = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR . 'vendor';
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Create a scanner for $rootDir.
     *
     * @param string      $rootDir    Project root to scan recursively
     * @param string|null $cache      Path to cache file. Null → no caching (always scans).
     *                                When set and the file exists, the FS walk is skipped.
     *                                When set and the file is missing, the walk runs and
     *                                the result is written for subsequent boots.
     */
    public static function run(string $rootDir, ?string $cache = null): static
    {
        return new static($rootDir, $cache);
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    /**
     * Register a collector that will receive every discovered class.
     * Collectors are called in registration order.
     */
    public function collect(CollectorInterface $collector): static
    {
        $this->collectors[] = $collector;
        return $this;
    }

    /**
     * Add directories to exclude from the scan (in addition to vendor/).
     *
     * @param string[] $dirs Absolute paths
     */
    public function exclude(array $dirs): static
    {
        foreach ($dirs as $dir) {
            $this->exclude[] = rtrim((string) $dir, '/\\');
        }
        return $this;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * Execute the scan — dispatch each class to all registered collectors.
     *
     * With cache:
     *   - Cache hit  → load FQCN list, skip FS walk
     *   - Cache miss → walk FS, save list, dispatch
     *
     * Without cache:
     *   - Always walk FS, never read/write cache
     */
    public function execute(): void
    {
        if ($this->cachePath !== null) {
            $this->executeWithCache();
        } else {
            $this->dispatchPairs($this->scanFilesystem());
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function executeWithCache(): void
    {
        if (is_file((string) $this->cachePath)) {
            $cached = require $this->cachePath;
            if (is_array($cached)) {
                // Cache hit — class names only, no file paths (autoloaded in production)
                foreach ($cached as $class) {
                    if (!class_exists($class)) {
                        continue;
                    }
                    $ref = new ReflectionClass($class);
                    if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                        continue;
                    }
                    foreach ($this->collectors as $collector) {
                        $collector->collect($class, $ref);
                    }
                }
                return;
            }
        }

        $pairs = $this->scanFilesystem();
        $this->writeCache(array_column($pairs, 0));
        $this->dispatchPairs($pairs);
    }

    /**
     * Dispatch [$class, $filePath] pairs to collectors.
     * require_once is used so that classes in non-autoloaded paths (e.g. tests) are loaded.
     *
     * @param array<array{string, string}> $pairs
     */
    private function dispatchPairs(array $pairs): void
    {
        foreach ($pairs as [$class, $filePath]) {
            if (!class_exists($class)) {
                require_once $filePath;
            }
            if (!class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait()) {
                continue;
            }

            foreach ($this->collectors as $collector) {
                $collector->collect($class, $ref);
            }
        }
    }

    /**
     * @return array<array{string, string}>  Each entry: [FQCN, absolute file path]
     */
    private function scanFilesystem(): array
    {
        $pairs   = [];
        $rootDir = rtrim($this->rootDir, '/\\');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            foreach ($this->exclude as $ex) {
                if (str_starts_with($realPath, $ex)) {
                    continue 2;
                }
            }

            $class = $this->extractClass($realPath);
            if ($class !== null) {
                $pairs[] = [$class, $realPath];
            }
        }

        return $pairs;
    }

    /** @param string[] $classNames */
    private function writeCache(array $classNames): void
    {
        $dir = dirname((string) $this->cachePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = var_export($classNames, true);
        file_put_contents(
            (string) $this->cachePath,
            "<?php\n\nreturn {$export};\n",
            LOCK_EX,
        );
    }

    private function extractClass(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $namespace = '';
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $m)) {
            $namespace = trim($m[1]) . '\\';
        }

        if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $content, $m)) {
            return $namespace . $m[1];
        }

        return null;
    }
}
