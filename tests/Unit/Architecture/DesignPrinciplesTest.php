<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use function count;
use function explode;
use function implode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sprintf;
use function str_starts_with;

use Symfony\Component\Finder\Finder;

use function trim;

/**
 * The principles `CLAUDE.md` states in prose, as failures.
 *
 * `LayeringTest` asserts *where* code may point. This asserts the four habits the
 * same document asks for and which nothing checked: a controller that only
 * translates, dependencies that arrive through a constructor, classes closed to
 * inheritance nobody designed for, and no state that outlives a request.
 *
 * All four are read from the source text rather than from reflection, because
 * reflection cannot see a method's length and because a test that boots nothing
 * belongs in the unit suite — the fast one, with no database and no container.
 *
 * The thresholds below are one step above what the codebase does today, not round
 * numbers. A guard set far above the truth guards nothing; a guard set at exactly
 * the truth fails on the next honest line. One step is the compromise, and every
 * time one of these fails the question it asks is real: does this action still
 * only translate, or has a rule moved into it?
 */
final class DesignPrinciplesTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../../src';

    /**
     * Code lines — no blanks, no comments — inside one action method.
     *
     * The longest today is 24, `Admin\PageController::edit()`, which reads a form,
     * catches the two domain exceptions editing a page can raise and renders. Past
     * this, an action is deciding something rather than translating it: the review
     * that produced this file found `PasswordResetController::request()` at 30,
     * composing an email and generating a link, and moved both into
     * `PasswordResetMailer`.
     */
    private const int LONGEST_ACTION = 25;

    /**
     * Constructor dependencies one class may take.
     *
     * Six today, in three admin controllers and two services. A seventh is
     * allowed; an eighth means the class has two jobs, and the fix is to split it
     * rather than to raise this number.
     */
    private const int MOST_DEPENDENCIES = 7;

    /**
     * Layers whose classes are closed unless they are deliberately open.
     *
     * `Entity/` is left out on purpose: `Article` and `Page` extend
     * `PublishableContent`, which is the one inheritance this domain designs for.
     *
     * @return iterable<string, array{string}>
     */
    public static function closedLayerProvider(): iterable
    {
        yield 'Controller' => ['Controller'];
        yield 'Service' => ['Service'];
        yield 'Repository' => ['Repository'];
        yield 'Form' => ['Form'];
        yield 'Search' => ['Search'];
        yield 'Security' => ['Security'];
        yield 'Twig' => ['Twig'];
        yield 'State' => ['State'];
    }

    /**
     * `CLAUDE.md`: controllers are thin and delegate to services.
     *
     * "Thin" is unenforceable as prose, which is how an action grows to thirty
     * lines while every review says the layering is fine. Length is a proxy, and
     * an imperfect one — but the failure it produces is always worth reading,
     * because the way to shorten an action is to move a decision out of it.
     */
    public function testNoActionDecidesMoreThanItTranslates(): void
    {
        $long = [];

        foreach ($this->methodsIn('Controller') as $method) {
            if ('__construct' === $method['name'] || $method['lines'] <= self::LONGEST_ACTION) {
                continue;
            }

            $long[] = sprintf(
                '%s:%d %s() is %d lines of code, over the %d a controller gets',
                $method['file'],
                $method['line'],
                $method['name'],
                $method['lines'],
                self::LONGEST_ACTION,
            );
        }

        self::assertSame([], $long, implode("\n", $long));
    }

    /**
     * A class with eight collaborators is two classes.
     *
     * The single-responsibility rule as something a machine can see. It counts the
     * constructor because `CLAUDE.md` allows no other way in — see
     * {@see testNothingReachesForTheContainer}.
     */
    public function testNoClassTakesMoreDependenciesThanItCanJustify(): void
    {
        $crowded = [];

        foreach ($this->methodsIn('Controller', 'Service') as $method) {
            if ('__construct' !== $method['name'] || $method['parameters'] <= self::MOST_DEPENDENCIES) {
                continue;
            }

            $crowded[] = sprintf(
                '%s:%d takes %d dependencies, over the %d one class gets',
                $method['file'],
                $method['line'],
                $method['parameters'],
                self::MOST_DEPENDENCIES,
            );
        }

        self::assertSame([], $crowded, implode("\n", $crowded));
    }

    /**
     * `CLAUDE.md`: constructor injection — no `ContainerInterface`, no service
     * locators.
     *
     * A class that fetches its collaborators cannot be read to find out what it
     * needs, cannot be constructed in a unit test without a container, and hides
     * a new dependency from every reviewer. This is the rule that keeps the
     * dependency graph visible in the signatures.
     */
    public function testNothingReachesForTheContainer(): void
    {
        $reaches = [];

        foreach ($this->linesUnderSource() as $line) {
            $matched = preg_match(
                '/(ContainerInterface|ServiceLocator|ContainerBagInterface|\$this->container\b)/',
                $line['text'],
            );

            if (1 === $matched) {
                $reaches[] = sprintf('%s:%d %s', $line['file'], $line['line'], trim($line['text']));
            }
        }

        self::assertSame([], $reaches, implode("\n", $reaches));
    }

    /**
     * State that outlives a request.
     *
     * Services in Symfony are shared, and under a worker runtime — FrankenPHP,
     * which this project is a candidate for — the process outlives the response
     * too. A mutable static is then a value from somebody else's request, which is
     * the class of bug that cannot be reproduced. `SitemapBudget` is the shape to
     * copy: it holds what has been spent, so each response makes its own.
     */
    public function testNoClassKeepsMutableStaticState(): void
    {
        $kept = [];

        foreach ($this->linesUnderSource() as $line) {
            $matched = preg_match(
                '/^\s*(?:public|protected|private)\s+static\s+(?!function|readonly)/',
                $line['text'],
            );

            if (1 === $matched) {
                $kept[] = sprintf('%s:%d %s', $line['file'], $line['line'], trim($line['text']));
            }
        }

        self::assertSame([], $kept, implode("\n", $kept));
    }

    /**
     * Every class in a layer is `final`, or `abstract` because something extends
     * it on purpose.
     *
     * Inheritance nobody designed for is the cheapest way to break an invariant:
     * a subclass overriding one method of a service keeps its name, its type and
     * its registration, and changes what it does. `final` makes extending a
     * decision that has to be made in this file rather than in somebody else's.
     */
    #[DataProvider('closedLayerProvider')]
    public function testEveryClassInTheLayerIsClosedOrDeliberatelyOpen(string $layer): void
    {
        $open = [];

        foreach ($this->linesIn($layer) as $line) {
            if (1 !== preg_match('/^(?:final |abstract |readonly )*class /', $line['text'])) {
                continue;
            }

            if (str_starts_with($line['text'], 'final ') || str_starts_with($line['text'], 'abstract ')) {
                continue;
            }

            $open[] = sprintf('%s:%d %s', $line['file'], $line['line'], trim($line['text']));
        }

        self::assertSame([], $open, implode("\n", $open));
    }

    public function testTheParsingFindsSomethingToJudge(): void
    {
        $methods = $this->methodsIn('Controller', 'Service');

        self::assertGreaterThan(
            100,
            count($methods),
            'The text parsing found almost no methods, so the assertions above are asserting nothing.',
        );
    }

    /**
     * Methods declared in the layers named, with their length in code lines and
     * their parameter count.
     *
     * Read from the text, which works because PHP-CS-Fixer formats every file the
     * same way: a method signature starts at four spaces and a method ends at a
     * closing brace at the same indentation. That is a narrow assumption, and it
     * is checked — a layer that yielded no methods at all would mean the parsing
     * broke rather than that nothing is there, which
     * {@see testTheParsingFindsSomethingToJudge} asserts.
     *
     * @return list<array{file: string, line: int, name: string, lines: int, parameters: int}>
     */
    private function methodsIn(string ...$layers): array
    {
        $methods = [];

        foreach ($layers as $layer) {
            $open = null;

            foreach ($this->linesIn($layer) as $line) {
                if (1 === preg_match('/^    (?:final )?public function (\w+)\(/', $line['text'], $matches)) {
                    $open = [
                        'file' => $line['file'],
                        'line' => $line['line'],
                        'name' => $matches[1],
                        'lines' => 0,
                        'parameters' => 0,
                    ];

                    continue;
                }

                if (null === $open) {
                    continue;
                }

                if ('    }' === $line['text']) {
                    $methods[] = $open;
                    $open = null;

                    continue;
                }

                $text = trim($line['text']);

                if ('' === $text || 1 === preg_match('#^(//|\#|/\*|\*)#', $text)) {
                    continue;
                }

                ++$open['lines'];

                // A promoted property or a plain parameter, one per line, which is
                // how this project writes any constructor with more than one.
                if (1 === preg_match('/\$\w+/', $text) && 1 !== preg_match('/^\)/', $text)) {
                    ++$open['parameters'];
                }
            }
        }

        return $methods;
    }

    /**
     * @return list<array{file: string, line: int, text: string}>
     */
    private function linesUnderSource(): array
    {
        return $this->readFrom(self::SOURCE, 'src');
    }

    /**
     * @return list<array{file: string, line: int, text: string}>
     */
    private function linesIn(string $layer): array
    {
        return $this->readFrom(self::SOURCE.'/'.$layer, 'src/'.$layer);
    }

    /**
     * @return list<array{file: string, line: int, text: string}>
     */
    private function readFrom(string $directory, string $label): array
    {
        $lines = [];

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $relative = sprintf('%s/%s', $label, $file->getRelativePathname());

            foreach (explode("\n", $file->getContents()) as $index => $text) {
                $lines[] = ['file' => $relative, 'line' => $index + 1, 'text' => $text];
            }
        }

        return $lines;
    }
}
