<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\Service\Account\PasswordPolicy;

use function array_keys;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;

use function explode;
use function implode;
use function in_array;
use function iterator_to_array;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Finder\Finder;

/**
 * The dependency direction, asserted rather than described.
 *
 * `CLAUDE.md` says dependencies point inwards only and that **the domain must not
 * know about HTTP or Twig** — that rule is what makes the same content serveable
 * through Twig and through the JSON API. Until this file existed, nothing checked
 * it: PHPStan at level max is happy to analyse an entity that renders a template,
 * and a reviewer notices a boundary crossing only if they happen to read the
 * `use` statements at the top of the file that crossed it.
 *
 * Written against imports rather than against a parsed dependency graph. That is
 * a deliberate limit: this catches the crossing that is *declared*, which is how
 * a boundary is actually broken in practice, and it needs no new dependency in a
 * project that has kept its tooling to five tools. A container fetched through a
 * fully-qualified name inside a method body would pass this test, which is why
 * `.claude/agents/architecture-guardian.md` exists as well and says so.
 *
 * Each row of the matrix below is one rule, one failure and one reason. Adding a
 * layer to `src/` with no row here fails
 * {@see testEveryDirectoryUnderSourceIsClassified} rather than silently
 * inheriting nothing.
 */
final class LayeringTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../../src';

    /**
     * Layers with no import rule, and the reason each has none.
     *
     * - `ApiResource`, `State`, `Twig`, `EventSubscriber`, `Command` are delivery
     *   boundaries like `Controller`: they are *allowed* to know about HTTP,
     *   Twig and the console, because translating between those and the layers
     *   below is the whole of their job.
     * - `Factory`, `DataFixtures`, `Story` are test support, which `CLAUDE.md`
     *   keeps in `src/` on purpose. They may reach anywhere; what matters is that
     *   production code does not reach *them*, which every rule below asserts by
     *   forbidding `App\Factory`.
     *
     * @var list<string>
     */
    private const array UNRULED = [
        'ApiResource',
        'Command',
        'DataFixtures',
        'EventSubscriber',
        'Factory',
        'State',
        'Story',
        'Twig',
    ];

    /**
     * The layer matrix: what each layer may not import, and the exceptions.
     *
     * Every entry is a **prefix**, so `Doctrine\ORM\EntityManager` covers the
     * interface as well as the class. Where the prefix happens to be a real
     * symbol it is written as `::class` — Rector rewrites it there anyway, and it
     * is the better form: a misspelt string would silently match nothing, while a
     * misspelt class name does not compile.
     *
     * An exception is a prefix that wins over a forbidden one. There are five, and
     * each is a decision rather than an oversight — they are commented where they
     * are made.
     *
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function layerProvider(): iterable
    {
        $http = 'Symfony\Component\HttpFoundation';
        $inward = ['App\Service', 'App\Form', 'App\Controller', 'App\Factory', 'App\Twig', 'App\State'];

        yield 'Entity' => [
            'Entity',
            [
                ...$inward,
                $http,
                'Twig\\',
                EntityManager::class,
                QueryBuilder::class,
                'Symfony\Component\Security\Core\Authorization',
                'Symfony\Bundle',
            ],
            [
                // Doctrine's #[ORM\Entity(repositoryClass: …)] names the class
                // that queries the entity, so the mapping points outwards where
                // nothing else does. Accepted because the alternative is
                // configuring every mapping somewhere the entity cannot be read
                // beside it — and because it is a class-string in an attribute,
                // not a call.
                'App\Repository',
            ],
        ];

        yield 'Exception' => [
            'Exception',
            [
                ...$inward,
                'App\Repository',
                $http,
                'Twig\\',
                'Doctrine\\',
                'Symfony\\',
            ],
            [],
        ];

        yield 'Repository' => [
            'Repository',
            [...$inward, $http, 'Twig\\', 'Symfony\Component\Security'],
            [],
        ];

        yield 'Search' => [
            'Search',
            ['App\Controller', 'App\Form', 'App\Twig', 'App\Factory', 'App\Service', $http, 'Twig\\'],
            [
                // SiteSearch borrows PlainText to build the snippet a result
                // shows. A text utility is not a layer crossing worth a rule;
                // what Search must not know is who asked, which is what the HTTP
                // and Twig entries above forbid.
                'App\Service\Seo',
            ],
        ];

        yield 'Service' => [
            'Service',
            [
                'App\Controller',
                'App\Twig',
                'App\State',
                'App\Factory',
                'App\Form',
                $http,
                'Twig\\',
                'Symfony\Component\Form',
                // The bridges and the bundles are where the framework's delivery
                // layer lives: `Symfony\Bridge\Twig\Mime\BodyRenderer` renders a
                // template, `Symfony\Bundle\FrameworkBundle\Controller\AbstractController`
                // is a controller. Both used to pass this row, which the audit
                // before the release pointed out — a rule that forbids
                // `Twig\Environment` and admits the bridge that wraps it is a rule
                // with a door beside it.
                'Symfony\Bridge',
                'Symfony\Bundle',
            ],
            [
                // A form's command object is what a service is *given* — plain
                // data carrying what somebody filled in, which `CLAUDE.md`
                // requires never to be an entity. The form type itself stays out.
                'App\Form\Command',
                // The three exceptions ADR 13 records, and nothing else. Each is
                // pinned to the file that may hold it by
                // testTheExceptionsRecordedInAdr13HaveNotGrown().
                TemplatedEmail::class,
                Security::class,
                // An upload arrives as HttpFoundation's File. Wrapping it in a
                // type of our own would buy the appearance of independence and
                // nothing else: the bytes still come from a request, and every
                // caller would translate. This is the one place the application
                // layer admits where its input came from.
                'Symfony\Component\HttpFoundation\File',
            ],
        ];

        yield 'Security' => [
            'Security',
            [
                ...$inward,
                // A voter that queries is how a listing becomes N+1: it is asked
                // once per row. Every voter here decides from the subject it was
                // handed. If one genuinely needs a repository, this failure is
                // the conversation about caching it, not a rule to delete
                // quietly.
                'App\Repository',
                $http,
                'Twig\\',
            ],
            [],
        ];

        yield 'Form' => [
            'Form',
            [
                'App\Controller',
                'App\Twig',
                'App\State',
                'App\Factory',
                // A form may *read* to offer choices — a section list, a parent
                // page, the images somebody uploaded — so App\Repository and
                // Doctrine's QueryBuilder are not on this list. Deciding anything
                // is a service's job, and writing is the entity manager's, and
                // neither belongs in a form type.
                'App\Service',
                EntityManager::class,
                $http,
                'Twig\\',
            ],
            [
                // PasswordPolicy, for the length its own constraint announces.
                // See testAFormCommandCarriesDataAndNothingElse.
                PasswordPolicy::class,
            ],
        ];

        yield 'Controller' => [
            'Controller',
            [
                // The delivery boundary may know everything below it. What it may
                // not do is write queries — see
                // testNoControllerBuildsItsOwnQuery — or borrow test support.
                'App\Factory',
                'App\Story',
                'App\DataFixtures',
            ],
            [],
        ];
    }

    /**
     * @param list<string> $forbidden
     * @param list<string> $exceptions
     */
    #[DataProvider('layerProvider')]
    public function testALayerReachesNoFurtherOutThanItsRow(
        string $layer,
        array $forbidden,
        array $exceptions,
    ): void {
        $crossings = [];

        foreach ($this->importsIn($layer) as $import) {
            if ($this->isAllowed($import['import'], $forbidden, $exceptions)) {
                continue;
            }

            $crossings[] = sprintf('%s:%d imports %s', $import['file'], $import['line'], $import['import']);
        }

        self::assertSame([], $crossings, sprintf(
            "src/%s reaches outwards, which the layer matrix in CLAUDE.md forbids:\n%s",
            $layer,
            implode("\n", $crossings),
        ));
    }

    /**
     * `CLAUDE.md`: repositories return typed collections and never leak a
     * `QueryBuilder`.
     *
     * A leaked builder moves the decision about what "published" means to
     * whoever finished the query, which is the one thing the published scope
     * exists to prevent. Private helpers may return one — `publishedQuery()` is
     * how the scope is shared — so only the public surface is checked.
     */
    public function testNoRepositoryLeaksAQueryBuilder(): void
    {
        $leaks = [];

        foreach ($this->linesIn('Repository') as $line) {
            if (1 === preg_match('/^\s*public function \w+\([^)]*\)\s*:\s*\??(QueryBuilder|Query)\b/', $line['text'])) {
                $leaks[] = sprintf('%s:%d %s', $line['file'], $line['line'], trim($line['text']));
            }
        }

        self::assertSame([], $leaks, implode("\n", $leaks));
    }

    /**
     * A query built in an action is a business rule written at the boundary: the
     * next caller that needs the same list writes it again, slightly differently.
     */
    public function testNoControllerBuildsItsOwnQuery(): void
    {
        $built = [];

        foreach ($this->linesIn('Controller') as $line) {
            if (1 === preg_match('/->(createQueryBuilder|createQuery|createNativeQuery)\(/', $line['text'])) {
                $built[] = sprintf('%s:%d %s', $line['file'], $line['line'], trim($line['text']));
            }
        }

        self::assertSame([], $built, implode("\n", $built));
    }

    /**
     * `Form/Command/` carries what a form collected, and can do nothing with it.
     *
     * A command is the one object in this application that crosses from the
     * boundary into the application layer, so it is worth being strict about what
     * it is allowed to be: properties, validation constraints, and a static
     * factory that reads an entity to fill the form. No repository, no entity
     * manager, no service — a command that could persist itself would let a
     * rejected submission reach the database before validation ran.
     *
     * It *may* name an entity. `ArticleCommand::$category` is the section somebody
     * picked from a list, and Symfony's EntityType hands over the entity rather
     * than its identifier; carrying an identifier instead would mean every service
     * looking it up again with no rule to apply while doing so. What the rule
     * forbids is a command *doing* something with the domain, which is what the
     * imports below would be needed for.
     */
    public function testAFormCommandCarriesDataAndNothingElse(): void
    {
        $doing = [];
        $forbidden = [
            'App\Repository',
            'App\Service',
            'App\Controller',
            'App\Form\\',
            'Doctrine\ORM',
            'Doctrine\Persistence',
            'Symfony\Component\Form',
            'Symfony\Component\HttpFoundation',
        ];

        // AccountCommand's #[Assert\Length] reads PasswordPolicy::MINIMUM_LENGTH.
        // The alternative is the number twelve written in two places, one of which
        // is the rule and the other the message somebody reads — and they would
        // disagree the first time the policy changed. A constant is not a
        // collaborator.
        $exceptions = [PasswordPolicy::class];

        foreach ($this->importsIn('Form/Command') as $import) {
            if ($this->isAllowed($import['import'], $forbidden, $exceptions)) {
                continue;
            }

            $doing[] = sprintf('%s:%d imports %s', $import['file'], $import['line'], $import['import']);
        }

        self::assertSame([], $doing, implode("\n", $doing));
    }

    /**
     * ADR 13 named the places where the domain knows about delivery. This asserts
     * the list has not grown.
     *
     * That ADR exists because an audit found four such imports that had been there
     * for features with nobody saying so, and its stated gain is precisely this:
     * "an audit can check that the list has not grown; it could not check a rule
     * everybody believed was absolute while four files broke it." A rule with three
     * named exceptions is checkable. A rule with an unknown number is a sentence
     * in a document.
     *
     * The third exception was found by the architecture pass before this release:
     * extracting `PasswordResetMailer` moved a Twig *template name* into the
     * application layer. The ADR was amended rather than the list quietly
     * extended — which is the whole point of it being a list.
     *
     * A fourth file taking an `UploadedFile`, a second reading the signed-in
     * account from the session, or a second naming a template fails here — and the
     * fix is either to not do it or to amend the ADR and this list together.
     */
    public function testTheExceptionsRecordedInAdr13HaveNotGrown(): void
    {
        $domain = ['Entity', 'Repository', 'Search', 'Service'];

        self::assertSame(
            [
                'src/Service/Media/MediaStorage.php',
                'src/Service/Media/MediaUploader.php',
                'src/Service/Media/UploadedFileValidator.php',
            ],
            $this->filesImporting('Symfony\Component\HttpFoundation', ...$domain),
            'The media services are the only ones ADR 13 allows to know what an upload is.',
        );

        self::assertSame(
            ['src/Service/Audit/AuditLog.php'],
            $this->filesImporting('Symfony\Bundle\SecurityBundle', ...$domain),
            'ADR 13 allows one class to read the actor from the session, because for the log a '
            .'missing actor and nobody at all must stay distinguishable.',
        );

        self::assertSame(
            ['src/Service/Account/PasswordResetMailer.php'],
            $this->filesImporting('Symfony\Bridge\Twig', ...$domain),
            'ADR 13 allows one class to name a template, and only through TemplatedEmail — which '
            .'is a message that says which template, not a renderer.',
        );
    }

    /**
     * The matrix cannot be complete unless adding a directory forces a decision
     * about it. A new layer with no row and no reason fails here, naming itself.
     */
    public function testEveryDirectoryUnderSourceIsClassified(): void
    {
        $classified = [...$this->ruledLayers(), ...self::UNRULED];
        $unclassified = [];

        foreach (Finder::create()->directories()->depth(0)->in(self::SOURCE) as $directory) {
            if (!in_array($directory->getFilename(), $classified, true)) {
                $unclassified[] = $directory->getFilename();
            }
        }

        sort($unclassified);

        self::assertSame([], $unclassified, sprintf(
            'src/%s has no row in the layer matrix. Add one, or record why it needs none.',
            implode(', src/', $unclassified),
        ));
    }

    /**
     * Every ruled layer is also a layer that exists, so a renamed directory
     * cannot leave a rule quietly asserting nothing about nothing.
     */
    public function testEveryRuledLayerStillExists(): void
    {
        foreach ($this->ruledLayers() as $layer) {
            self::assertDirectoryExists(
                self::SOURCE.'/'.$layer,
                sprintf('The matrix rules on src/%s, which is not there any more.', $layer),
            );
        }
    }

    /**
     * The layers the matrix rules on, read from the matrix itself so the two
     * cannot drift.
     *
     * @return list<string>
     */
    private function ruledLayers(): array
    {
        return array_keys(iterator_to_array(self::layerProvider()));
    }

    /**
     * Which files in the named layers import anything under a prefix, sorted so
     * the assertion reads as a list rather than as an order.
     *
     * @return list<string>
     */
    private function filesImporting(string $prefix, string ...$layers): array
    {
        $files = [];

        foreach ($layers as $layer) {
            foreach ($this->importsIn($layer) as $import) {
                if (str_starts_with($import['import'], $prefix)) {
                    $files[$import['file']] = true;
                }
            }
        }

        $found = array_keys($files);
        sort($found);

        return $found;
    }

    /**
     * @param list<string> $forbidden
     * @param list<string> $exceptions
     */
    private function isAllowed(string $import, array $forbidden, array $exceptions): bool
    {
        foreach ($exceptions as $exception) {
            if (str_starts_with($import, $exception)) {
                return true;
            }
        }

        return array_all($forbidden, static fn (string $prefix): bool => !str_starts_with($import, $prefix));
    }

    /**
     * The imports declared at the top of every file in a layer.
     *
     * Anchored at the start of the line, which is what separates an import from a
     * trait `use` inside a class body — that one is indented, always, because
     * PHP-CS-Fixer says so.
     *
     * @return list<array{file: string, line: int, import: string}>
     */
    private function importsIn(string $layer): array
    {
        $imports = [];

        foreach ($this->linesIn($layer) as $line) {
            $matched = preg_match('/^use\s+(?:function\s+|const\s+)?([A-Za-z0-9_\\\\]+)/', $line['text'], $matches);

            if (1 !== $matched) {
                continue;
            }

            $imports[] = ['file' => $line['file'], 'line' => $line['line'], 'import' => $matches[1]];
        }

        return $imports;
    }

    /**
     * @return list<array{file: string, line: int, text: string}>
     */
    private function linesIn(string $path): array
    {
        $lines = [];

        foreach (Finder::create()->files()->in(self::SOURCE.'/'.$path)->name('*.php') as $file) {
            // Finder reports a path with the platform's separator, so on Windows
            // this would otherwise read src/Service/Media\MediaUploader.php — fine
            // in a message, wrong in an assertion comparing whole paths.
            $relative = sprintf('src/%s/%s', $path, str_replace('\\', '/', $file->getRelativePathname()));

            foreach (explode("\n", $file->getContents()) as $index => $text) {
                $lines[] = ['file' => $relative, 'line' => $index + 1, 'text' => $text];
            }
        }

        return $lines;
    }
}
