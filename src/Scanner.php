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
 *
 * @link https://winterframe.net/packages/di/scanning-and-autodiscovery Class discovery, collectors and the scan cache
 */
final class Scanner
{
    /** @var CollectorInterface[] */
    private array $collectors = [];

    /** @var string[] */
    private array $exclude = [];

    private function __construct(
        private readonly string $rootDir,
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

        // Both sides of the comparison must be canonical. The paths being tested come
        // from getRealPath(), which resolves symlinks; the exclusions are built from the
        // root as it was handed in, which usually is not resolved. A deploy that serves
        // the application through a symlink — `current -> releases/2026…`, the standard
        // layout — would therefore fail every exclusion, and the scan would walk vendor/
        // in full: thousands of files read, tokenised and required.
        $exclude = array_map(static fn(string $dir): string => realpath($dir) ?: $dir, $this->exclude);

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();

            foreach ($exclude as $ex) {
                if (str_starts_with($realPath, $ex)) {
                    continue 2;
                }
            }

            foreach ($this->extractClasses($realPath) as $class) {
                $pairs[] = [$class, $realPath];
            }
        }

        return $pairs;
    }

    /**
     * Writes the cache in one atomic step.
     *
     * Readers `require` this file without taking a lock, so it must never be visible
     * half-written. Writing in place cannot promise that: the file is truncated when it is
     * opened, and `LOCK_EX` protects writers from each other, not a reader from a writer.
     * A worker booting at that moment would `require` either an empty file — the cache
     * silently stops working — or a partial one, which is a parse error it cannot catch.
     * Not theoretical: with several workers starting on a cold cache they all write at
     * once, and a measured half of concurrent reads saw an incomplete file.
     *
     * Writing to a temporary neighbour and renaming avoids it — `rename()` within one
     * directory is atomic, so a reader sees the old file or the new one, never a mix.
     *
     * @param string[] $classNames
     */
    private function writeCache(array $classNames): void
    {
        $path = (string) $this->cachePath;

        // No is_dir() check first: two workers racing here would both pass it and one would
        // fail; letting mkdir() fail on an existing directory is the same outcome, quieter.
        @mkdir(dirname($path), 0755, true);

        $export = var_export($classNames, true);
        $tmp    = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, "<?php\n\nreturn {$export};\n") === false) {
            return;                       // unwritable cache directory — scanning still works
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);                // another worker won the race; its file is the same
            return;
        }

        // With validate_timestamps=0 — the usual production setting — opcache would keep
        // serving whatever it compiled from this path before the rename.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Every class declared in a file, fully qualified.
     *
     * Tokenised rather than matched with a regular expression, because the regular
     * expression this replaces anchored on `^class` and therefore could not see:
     *
     *   - `#[Attr] class Foo` — anything sharing the line hid the declaration;
     *   - `    class Foo` — a class indented inside a braced `namespace Foo { }`;
     *   - a second class in the file — it returned the first match only;
     *   - the difference between a declaration and the same words in a comment,
     *     a string or a heredoc, so `/* class Ghost *\/` was "found" and the real
     *     class below it was not.
     *
     * A missed class is registered nowhere — no DI binding, no routes, no health
     * contributor — and nothing reports it. That silence is why this is exact now:
     * a pattern can always be defeated by formatting, a token stream cannot.
     *
     * Only `class` counts, as before: interfaces, traits and enums are not collected,
     * and an anonymous class has no name to collect.
     *
     * @return list<string>
     */
    private function extractClasses(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $tokens = token_get_all($content);
        $total = count($tokens);
        $namespace = '';
        $classes = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readNamespace($tokens, $i, $total);
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` is a constant, not a declaration.
            if ($this->previousMeaningful($tokens, $i) === T_DOUBLE_COLON) {
                continue;
            }

            $name = $this->readClassName($tokens, $i, $total);
            if ($name !== null) {
                $classes[] = $namespace === '' ? $name : $namespace . '\\' . $name;
            }
        }

        return $classes;
    }

    /**
     * The namespace declared at $i, without a trailing separator.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function readNamespace(array $tokens, int $i, int $total): string
    {
        $name = '';

        for ($j = $i + 1; $j < $total; $j++) {
            $token = $tokens[$j];

            // `;` ends a plain declaration, `{` a braced one — both end the name.
            if ($token === ';' || $token === '{') {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $name .= $token[1];
            }
        }

        return trim($name, '\\');
    }

    /**
     * The name following the `class` keyword at $i, or null for an anonymous class.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function readClassName(array $tokens, int $i, int $total): ?string
    {
        for ($j = $i + 1; $j < $total; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * The id of the token before $i, ignoring whitespace and comments.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private function previousMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $token[0];
            }

            return null;
        }

        return null;
    }
}
