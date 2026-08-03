<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Tests;

use Flytachi\Winter\DI\Scanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Finding the classes in a file must not depend on how the file is formatted.
 *
 * The scan is the entry point for everything discovered at boot — DI bindings, routes,
 * health contributors, scope checks. A class the scan misses is not registered anywhere,
 * and nothing reports it: the controller simply has no routes, the service is simply not
 * in the container. Silence is the whole problem, so the parsing has to be exact rather
 * than approximately right.
 *
 * A regular expression cannot be exact here — it cannot tell a declaration from the same
 * words inside a comment, and it cannot see past whatever shares the line. These cases
 * are the ones that were actually wrong.
 */
final class ScannerExtractionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wd-extract-' . getmypid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @return list<string> */
    private function extract(string $source): array
    {
        $path = $this->dir . '/probe.php';
        file_put_contents($path, $source);

        return new ReflectionMethod(Scanner::class, 'extractClasses')
            ->invoke(Scanner::run($this->dir), $path);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function sources(): array
    {
        return [
            'plain' => [
                "<?php\nnamespace App;\n\nclass Foo {}\n",
                ['App\Foo'],
            ],
            'final' => [
                "<?php\nnamespace App;\n\nfinal class Foo {}\n",
                ['App\Foo'],
            ],
            'final readonly' => [
                "<?php\nnamespace App;\n\nfinal readonly class Foo {}\n",
                ['App\Foo'],
            ],
            'attribute on its own line' => [
                "<?php\nnamespace App;\n\n#[Attr]\nclass Foo {}\n",
                ['App\Foo'],
            ],
            'attribute sharing the line' => [
                "<?php\nnamespace App;\n\n#[Attr] class Foo {}\n",
                ['App\Foo'],
            ],
            'attribute and final sharing the line' => [
                "<?php\nnamespace App;\n\n#[Attr] final class Foo {}\n",
                ['App\Foo'],
            ],
            'indented inside a braced namespace' => [
                "<?php\nnamespace App {\n    class Foo {}\n}\n",
                ['App\Foo'],
            ],
            'no namespace' => [
                "<?php\n\nclass Foo {}\n",
                ['Foo'],
            ],
            'two classes in one file' => [
                "<?php\nnamespace App;\n\n#[Attr] class Real {}\n\nclass Helper {}\n",
                ['App\Real', 'App\Helper'],
            ],
            'a class named in a block comment' => [
                "<?php\nnamespace App;\n\n/*\nclass Ghost\n*/\n#[Attr] class Real {}\n",
                ['App\Real'],
            ],
            'a class named in a docblock' => [
                "<?php\nnamespace App;\n\n/**\n * class Ghost\n */\nclass Real {}\n",
                ['App\Real'],
            ],
            'a class named in a string' => [
                "<?php\nnamespace App;\n\n\$sql = 'class Ghost';\n\nclass Real {}\n",
                ['App\Real'],
            ],
            'a class named in a heredoc' => [
                "<?php\nnamespace App;\n\n\$t = <<<'TXT'\nclass Ghost\nTXT;\n\nclass Real {}\n",
                ['App\Real'],
            ],
            'the ::class constant is not a declaration' => [
                "<?php\nnamespace App;\n\nclass Real { public function f() { return Other::class; } }\n",
                ['App\Real'],
            ],
            'an anonymous class has no name' => [
                "<?php\nnamespace App;\n\nclass Real { public function f() { return new class {}; } }\n",
                ['App\Real'],
            ],
            'interfaces, traits and enums are not classes' => [
                "<?php\nnamespace App;\n\ninterface I {}\ntrait T {}\nenum E {}\n\nclass Real {}\n",
                ['App\Real'],
            ],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('sources')]
    public function test_it_finds_every_declared_class(string $source, array $expected): void
    {
        self::assertSame($expected, $this->extract($source));
    }

    public function test_a_file_without_a_class_yields_nothing(): void
    {
        self::assertSame([], $this->extract("<?php\nnamespace App;\n\ninterface I {}\n"));
    }

    /**
     * An interface declared before the class must not shadow it. The regular expression
     * got this right by accident — `interface I` simply did not match `^class` — and the
     * accident is worth pinning down.
     */
    public function test_a_leading_interface_does_not_hide_the_class(): void
    {
        $source = "<?php\nnamespace App;\n\ninterface I {}\n\nclass Real implements I {}\n";

        self::assertSame(['App\Real'], $this->extract($source));
    }
}
