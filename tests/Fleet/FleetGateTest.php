<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Fleet;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/**
 * THE FLEET GATE — the README's install, performed.
 *
 * Every other suite in the fleet tests one package at its own HEAD. This one
 * follows the README from the top, in a directory that did not exist a minute
 * ago: create the project, give it a database, migrate, make the first
 * administrator, sign in over HTTP, and then require every official module one
 * by one, migrating, validating the schema, running the project's own smoke
 * suite and signing in again after each. The step that breaks is the step the
 * report names.
 *
 * TWO MODES, ONE FILE (see docs/fleet-gate.md):
 *
 *   composer fleet-gate          released — every package resolves the way the
 *                                README's own commands resolve it, from the
 *                                published repositories. Run after ANY tag in
 *                                the fleet; the tag is not done until this is
 *                                green.
 *   composer fleet-gate:head     head — the same steps against the branch each
 *                                sibling checkout has out, as last committed, so
 *                                "if I tagged everything right now, would an
 *                                install work?" is answered before the tag.
 *
 * It is a PHPUnit test so that it lives in the project and runs from any
 * checkout with `composer fleet-gate`. It is its own test suite so that
 * `composer test` stays the ten-second smoke it is.
 *
 * Environment:
 *   FLEET_GATE_MODE           released (default) | head
 *   FLEET_GATE_DATABASE_URL   a PostGIS-capable server; the database named in
 *                             it is DROPPED and recreated (default: the local
 *                             test cluster on 5434, database `fleet_gate`)
 *   FLEET_GATE_WORKSPACE      head mode: the directory holding the sibling
 *                             checkouts (default: this checkout's parent)
 *   FLEET_GATE_KEEP           set to keep the project directory for a look
 *
 * WHAT DECIDES THE COMPOSER AND BROWSER MECHANISMS BELOW — docs first, then the
 * tool's own source where the docs stop short:
 *
 *   create-project --repository / --add-repository
 *     "Provide a custom repository to search for the package, which will be
 *      used instead of packagist."
 *     "Add the custom repository in the composer.json. If a lock file is
 *      present, it will be deleted and an update will be run instead of an
 *      install."
 *
 *     @see https://getcomposer.org/doc/03-cli.md#create-project
 *     @see composer/src/Composer/Command/CreateProjectCommand.php —
 *          `if (null === $repositories) { …defaultRepos… } else { …only these… }`
 *          and the composer.json is written ONLY under `$addRepository`. So
 *          `--repository` finds the ROOT package and nothing more: every later
 *          `composer require` needs its own repository, which is what
 *          {@see pointAt()} writes.
 *
 *   create-project --stability
 *     "Minimum stability of package. Defaults to `stable`."
 *     @see https://getcomposer.org/doc/03-cli.md#create-project
 *     @see composer/src/Composer/Command/CreateProjectCommand.php —
 *          `new RepositorySet($stability)`; it bounds the ROOT package's
 *          candidates and is never written into the created project.
 *
 *   composer config repositories.<name> vcs <url>
 *     "php composer.phar config repositories.foo vcs https://github.com/foo/bar"
 *     @see https://getcomposer.org/doc/03-cli.md#config
 *
 *   a version-line branch as a version, and requiring it under a stable floor
 *     "you must specify a version constraint that looks like this: `v1.x-dev`.
 *      The `.x` is an arbitrary string that Composer requires to tell it that
 *      we're talking about the `v1` branch and not a `v1` tag"
 *     @see https://getcomposer.org/doc/articles/versions.md#branches
 *     @see composer/vendor/composer/semver/src/VersionParser.php —
 *          normalizeBranch(); no `extra.branch-alias` is involved.
 *     @see composer/src/Composer/Package/Loader/RootPackageLoader.php —
 *          extractStabilityFlags(): a constraint whose own stability is not
 *          stable sets that package's stability flag, so `0.1.x-dev` installs
 *          under `minimum-stability: stable` with no `@dev` and no edit to the
 *          project's floor.
 *
 *   HttpBrowser
 *     @see https://symfony.com/doc/current/components/browser_kit.html
 *     @see vendor/symfony/browser-kit/AbstractBrowser.php —
 *          `protected bool $followRedirects = true;`, so `submit($form)` lands
 *          on the page the redirect points at and absolute URIs are what a real
 *          HTTP client takes.
 *
 *   doctrine:schema:validate --skip-sync / --skip-mapping
 *     @see vendor/doctrine/orm/src/Tools/Console/Command/ValidateSchemaCommand.php —
 *          "Skip checking if the mapping is in sync with the database" /
 *          "Skip the mapping validation check": the two runs below ask the two
 *          questions separately on purpose.
 *
 *   asset-map:compile
 *     @see https://symfony.com/doc/current/frontend/asset_mapper.html#deploying
 */
final class FleetGateTest extends TestCase
{
    /**
     * THE OFFICIAL MODULES, in install order (a module that requires another
     * comes after it). This list is the README's "Official modules" table; the
     * two are kept in step by {@see testTheReadmeListsEveryOfficialModule}.
     *
     * @var list<string>
     */
    public const array OFFICIAL_MODULES = [
        'storage',
        'patrol',
        'incident',
        'roster',
    ];

    /**
     * THE PRIVATE MODULES of the managed-hosting tier: not in the README's table,
     * because an installer never requires them, but installed by the gate after
     * the official ones so the tier is proven the same way.
     *
     * @var list<string>
     */
    public const array PRIVATE_MODULES = ['telemetry'];

    /**
     * WHAT EACH CAPABILITY MODULE MUST BE LISTED AS in the catalogue after its
     * install. Storage and telemetry are infrastructure and declare no tile.
     * This is the assertion that was missing when a freshly installed module
     * sat in the packages but never reached the catalogue: everything else
     * still booted, signed in and passed.
     */
    private const array CATALOGUE_SLUGS = ['patrol' => 'patrols', 'incident' => 'incidents', 'roster' => 'roster'];

    /**
     * THE AREA THE GATE WORKS IN is the manual's: the worked example's first
     * area, so the gate's data and the book's are one and the same and no
     * invented or real place ever enters a test.
     */
    private const string AREA_NAME = 'Kilimani Crater Conservation Area';

    /**
     * WHERE A MODULE FIRST ANSWERS once it is switched on for the area (`%s`
     * is the area's uuid), and where an infrastructure module answers at all.
     * "Installed" means an administrator can open this, not that a package is
     * in the lock file.
     */
    private const array MODULE_PAGES = [
        'storage' => '/files',
        'patrol' => '/areas/%s/modules/patrols',
        'incident' => '/areas/%s/modules/incidents',
        'roster' => '/areas/%s/modules/roster',
        'telemetry' => '/telemetry',
    ];

    private const string ADMIN_EMAIL = 'gate@example.test';
    private const string ADMIN_PASSWORD = 'fleet-gate-passphrase';

    private static string $project = '';
    private static string $mode = 'released';
    private static ?Process $server = null;
    private static string $baseUrl = '';
    private static string $areaUuid = '';

    public static function setUpBeforeClass(): void
    {
        self::$mode = getenv('FLEET_GATE_MODE') ?: 'released';
        if (!\in_array(self::$mode, ['released', 'head'], true)) {
            self::fail(\sprintf('FLEET_GATE_MODE must be "released" or "head", got "%s".', self::$mode));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        if ('' !== self::$project && !getenv('FLEET_GATE_KEEP')) {
            (new Process(['rm', '-rf', self::$project]))->mustRun();
        } elseif ('' !== self::$project) {
            self::say('kept '.self::$project);
        }
    }

    // ── the README, step by step ────────────────────────────────────────────

    public function testTheReadmeListsEveryOfficialModule(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2).'/README.md');

        foreach (self::OFFICIAL_MODULES as $module) {
            self::assertStringContainsString(
                'uhifadhi/'.$module.'-module',
                $readme,
                \sprintf('README.md must list uhifadhi/%s-module under "Official modules".', $module),
            );
        }
    }

    /** README §1. */
    public function testTheProjectIsCreated(): string
    {
        $skeleton = \dirname(__DIR__, 2);
        self::$project = rtrim(sys_get_temp_dir(), '/').'/fleet-gate-'.bin2hex(random_bytes(4));

        self::say(\sprintf('%s mode → %s', self::$mode, self::$project));

        // Head mode reads the sibling checkouts as git repositories, so the
        // project gets each one's committed HEAD exported the way a tag is —
        // never a working tree, whose var/ and vendor/ would come along and
        // whose copied container would report itself fresh forever.
        $command = ['composer', 'create-project', 'uhifadhi/skeleton', self::$project, '--no-interaction', '--no-progress'];
        if ('head' === self::$mode) {
            $command[] = '--stability=dev';
            $command[] = '--repository='.json_encode(['type' => 'vcs', 'url' => $skeleton], \JSON_THROW_ON_ERROR);
        }
        // Released mode is the README's own line: Packagist, no flags.
        self::shell($command, \dirname(self::$project), 'README §1 create the project');

        if ('head' === self::$mode) {
            // The sibling checkouts stand in for the published repositories:
            // the core now, each module as it is required.
            self::pointAt('uhifadhi/uhifadhi', self::workspace().'/uhifadhi');
            // The branch by name: `@dev` alone would still prefer a tag where
            // one exists, and a tag is exactly what head mode must not test.
            self::shell(['composer', 'require', 'uhifadhi/uhifadhi:'.self::headVersion(self::workspace().'/uhifadhi'), '--no-interaction', '--no-progress'], self::$project, 'head: core from the checkout');
        }

        self::assertFileExists(self::$project.'/config/bundles.php');

        return self::$project;
    }

    /** README §2 and §3. */
    #[Depends('testTheProjectIsCreated')]
    public function testTheDatabaseIsMigrated(string $project): string
    {
        $url = getenv('FLEET_GATE_DATABASE_URL')
            ?: 'postgresql://app:app@127.0.0.1:5434/fleet_gate?serverVersion=17&charset=utf8';

        self::freshDatabase($url);
        file_put_contents($project.'/.env.local', 'DATABASE_URL="'.$url.'"'."\n".'TELEMETRY_DATABASE_URL="'.self::telemetryDatabaseUrl().'"'."\n");

        self::migrateAndCompile($project, 'README §3 core');

        return $project;
    }

    /** README §4: the command ships with the core; no development package is needed. */
    #[Depends('testTheDatabaseIsMigrated')]
    public function testTheFirstAdministratorExists(string $project): string
    {
        $out = self::shell([
            'php', 'bin/console', 'team:user:create', self::ADMIN_EMAIL, 'Ada', 'Mwangi',
            '--tier=super-admin', '--password='.self::ADMIN_PASSWORD, '--no-interaction',
        ], $project, 'README §4 first administrator');
        self::assertStringContainsString(self::ADMIN_EMAIL, $out, 'the command names the account it created');

        return $project;
    }

    /** README §5. */
    #[Depends('testTheFirstAdministratorExists')]
    public function testTheAdministratorSignsIn(string $project): string
    {
        self::serve($project);
        $browser = self::signIn('README §5 sign in');
        self::createTheArea($browser);

        return $project;
    }

    /** README §6, once per official module, in order. */
    #[Depends('testTheAdministratorSignsIn')]
    public function testEveryOfficialModuleInstallsOneByOne(string $project): void
    {
        foreach ([...self::OFFICIAL_MODULES, ...self::PRIVATE_MODULES] as $module) {
            $package = 'uhifadhi/'.$module.'-module';
            self::say('module '.$package);

            if ('head' === self::$mode) {
                self::pointAt($package, self::workspace().'/'.$module.'-module');
                self::shell(['composer', 'require', $package.':'.self::headVersion(self::workspace().'/'.$module.'-module'), '--no-interaction', '--no-progress'], $project, $package.' require (head)');
            } else {
                // The README's own line. Telemetry is private and names its repository first.
                if ('telemetry' === $module) {
                    self::shell(['composer', 'config', 'repositories.telemetry', 'vcs', 'https://github.com/utafitilabs/telemetry-module'], $project, $package.' repository');
                }
                self::shell(['composer', 'require', $package, '--no-interaction', '--no-progress'], $project, $package.' require');
            }

            if ('telemetry' === $module) {
                // Its tables live in a database of their own, created by its
                // own command; the README's row says so.
                self::freshDatabase(self::telemetryDatabaseUrl());
                self::shell(['php', 'bin/console', 'telemetry:migrate', '--no-interaction'], $project, $package.' telemetry:migrate');
            }
            self::migrateAndCompile($project, $package);
            if (isset(self::CATALOGUE_SLUGS[$module])) {
                $slugs = self::shell(['php', 'bin/console', 'dbal:run-sql', 'SELECT slug FROM module ORDER BY slug', '--no-interaction'], $project, $package.' catalogue');
                self::assertMatchesRegularExpression('/\b'.preg_quote(self::CATALOGUE_SLUGS[$module], '/').'\b/', $slugs, $package.' must be in the catalogue after its install — the area\'s module grid reads nothing else');
            }
            self::shell(['composer', 'test'], $project, $package.' project smoke suite');
            self::restartServer($project);
            $browser = self::signIn($package.' sign in');
            self::switchOnAndOpen($browser, $module, $package);
        }

        self::assertTrue(true, 'every official and private module installed');
    }

    // ── the steps' shared moves ─────────────────────────────────────────────

    private static function migrateAndCompile(string $project, string $step): void
    {
        // The README's three commands: clear and warm are split because a
        // clear that warms in-process needs more than PHP's default 128 MB.
        self::shell(['php', 'bin/console', 'cache:clear', '--no-warmup'], $project, $step.' cache:clear --no-warmup');
        self::shell(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $project, $step.' migrate');
        self::shell(['php', 'bin/console', 'cache:warmup'], $project, $step.' cache:warmup');
        // Not one of the README's commands: the production image runs it when it
        // is built. The gate runs it here to prove the build step still works.
        self::shell(['php', 'bin/console', 'asset-map:compile'], $project, $step.' asset-map:compile (the image\'s build step)');
        // The shipped migrations and the shipped entities must agree: a package
        // whose entity moved on without its migration is caught here.
        self::shell(['php', 'bin/console', 'doctrine:schema:validate', '--skip-sync', '--no-interaction'], $project, $step.' mapping valid');
        $out = self::shell(['php', 'bin/console', 'doctrine:schema:validate', '--skip-mapping', '--no-interaction'], $project, $step.' schema in sync', allowFailure: true);
        self::assertStringContainsString('in sync', $out, $step.': after migrating, the schema must need no further change');
    }

    private static function signIn(string $step): HttpBrowser
    {
        $browser = new HttpBrowser(HttpClient::create());

        $crawler = $browser->request('GET', self::$baseUrl.'/login');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), $step.': the sign-in page answers');

        $form = $crawler->filter('form[action$="/login"]')->form([
            '_username' => self::ADMIN_EMAIL,
            '_password' => self::ADMIN_PASSWORD,
        ]);
        // The browser follows the redirect off the form itself.
        $browser->submit($form);

        self::assertSame(200, $browser->getResponse()->getStatusCode(), $step.': signed in and landed');
        $body = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('name="_password"', $body, $step.': the sign-in form must be gone after signing in');
        self::assertStringContainsString('Ada', $body, $step.': the page names the person who signed in');

        return $browser;
    }

    /**
     * THE MANUAL'S AREA, created the way an administrator creates one: the
     * form at /areas/new, boundary to be imported later. The uuid in the
     * address the form lands on is the area's, and every module step below
     * works inside it.
     */
    private static function createTheArea(HttpBrowser $browser): void
    {
        $crawler = $browser->request('GET', self::$baseUrl.'/areas/new');
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'the new-area form answers');

        $form = $crawler->filter('form')->reduce(static fn ($node) => null !== $node->filter('input[name="name"]')->getNode(0))->form();
        $form['name'] = self::AREA_NAME;
        if ($form->has('boundary_mode')) {
            $form['boundary_mode'] = 'later';
        }
        $browser->submit($form);

        $landed = (string) $browser->getRequest()->getUri();
        self::assertSame(200, $browser->getResponse()->getStatusCode(), 'creating the area lands on a page');
        self::assertMatchesRegularExpression('#/areas/([0-9a-f-]{36})#', $landed, 'the area page carries its uuid: '.$landed);
        preg_match('#/areas/([0-9a-f-]{36})#', $landed, $m);
        self::$areaUuid = $m[1];
        self::assertStringContainsString(self::AREA_NAME, (string) $browser->getResponse()->getContent());
    }

    /**
     * SWITCHED ON AND OPENED. A capability module is parked after its install;
     * the administrator switches it on for the area through the grid's own
     * form, and its first page then answers. An infrastructure module has no
     * tile and answers everywhere at once.
     */
    private static function switchOnAndOpen(HttpBrowser $browser, string $module, string $step): void
    {
        if (isset(self::CATALOGUE_SLUGS[$module])) {
            $slug = self::CATALOGUE_SLUGS[$module];
            $crawler = $browser->request('GET', self::$baseUrl.'/areas/'.self::$areaUuid.'/modules/customize');
            self::assertSame(200, $browser->getResponse()->getStatusCode(), $step.': the area\'s module grid answers');
            $forms = $crawler->filter('form')->reduce(static function ($node) use ($slug): bool {
                $input = $node->filter('input[name="module"]')->getNode(0);

                return null !== $input && $slug === $input->getAttribute('value') && str_contains((string) $node->attr('action'), '/install');
            });
            if ($forms->count() > 0) {
                $browser->submit($forms->first()->form());
                self::assertSame(200, $browser->getResponse()->getStatusCode(), $step.': switching the module on lands on a page');
            }
        }

        $page = \sprintf(self::MODULE_PAGES[$module], self::$areaUuid);
        $browser->request('GET', self::$baseUrl.$page);
        self::assertSame(200, $browser->getResponse()->getStatusCode(), $step.': '.$page.' answers for the administrator');
        self::assertStringNotContainsString('name="_password"', (string) $browser->getResponse()->getContent(), $step.': and it is not the sign-in form');
    }

    private static function serve(string $project): void
    {
        self::$baseUrl = 'http://127.0.0.1:'.self::freePort();
        self::$server = new Process(['php', '-S', substr(self::$baseUrl, 7), '-t', 'public'], $project);
        // NOBODY READS THIS SERVER'S OUTPUT, SO IT MUST NOT HAVE ANY. Process
        // always fetches a child's stdout and stderr into pipes, and it drains
        // them only when the parent asks after the process — which this gate
        // never does for the server: it starts it and then talks HTTP to it.
        // The dev-mode server logs several lines per request, the pipe fills,
        // the server blocks on write, and the next request waits on a client
        // timeout instead of an answer.
        //
        //   "As standard output and error output are always fetched from the
        //    underlying process, it might be convenient to disable output in
        //    some cases to save memory. Use disableOutput()"
        //   @see https://symfony.com/doc/current/components/process.html#disabling-output
        //   @see vendor/symfony/process/Process.php — buildDescriptors():
        //        `new UnixPipes($this->isTty(), $this->isPty(), $this->input, !$this->outputDisabled || $hasCallback)`
        //   @see vendor/symfony/process/Pipes/UnixPipes.php — getDescriptors():
        //        with no read support the child is handed `/dev/null`, not a pipe
        //   @see vendor/symfony/process/Process.php — updateStatus(), the only
        //        place readPipes() is called from
        //
        // And the timeout is lifted, because a Process is 60 s by default
        //   "?float $timeout = 60"  @see vendor/symfony/process/Process.php — __construct()
        // while this one is meant to outlive every module's install.
        self::$server->setTimeout(null);
        self::$server->disableOutput();
        self::$server->start();

        $deadline = microtime(true) + 15;
        do {
            usleep(200_000);
            $up = @file_get_contents(self::$baseUrl.'/login', false, stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]));
        } while (false === $up && microtime(true) < $deadline);

        self::assertNotFalse($up, 'the built-in server answers within 15 s');
    }

    private static function restartServer(string $project): void
    {
        self::$server?->stop();
        self::serve($project);
    }

    private static function freshDatabase(string $url): void
    {
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $name = ltrim($parts['path'] ?? '', '/');
        self::assertNotSame('', $name, 'FLEET_GATE_DATABASE_URL must name a database');

        $pdo = new \PDO(\sprintf('pgsql:host=%s;port=%d;dbname=postgres', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 5432), $parts['user'] ?? null, $parts['pass'] ?? null);
        $pdo->exec(\sprintf('DROP DATABASE IF EXISTS "%s"', $name));
        $pdo->exec(\sprintf('CREATE DATABASE "%s"', $name));
    }

    private static function pointAt(string $package, string $checkout): void
    {
        self::assertDirectoryExists($checkout, \sprintf('head mode needs %s checked out at %s', $package, $checkout));
        self::assertDirectoryExists($checkout.'/.git', \sprintf('head mode reads %s as a git repository', $checkout));
        $name = str_replace('/', '-', $package);
        self::shell(['composer', 'config', 'repositories.'.$name, 'vcs', $checkout], self::$project, 'head: '.$package.' from '.$checkout);
    }

    /**
     * THE BRANCH EACH CHECKOUT HAS OUT, as composer names it: a version line
     * such as `0.3` is `0.3.x-dev`, anything else is `dev-<branch>`. Nobody
     * switches branches to run the gate; it tests what is being worked on.
     */
    private static function headVersion(string $checkout): string
    {
        $branch = trim((new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $checkout))->mustRun()->getOutput());
        self::assertNotSame('HEAD', $branch, $checkout.' is on a detached HEAD; check out a branch');

        return preg_match('/^\d+\.\d+$/', $branch) ? $branch.'.x-dev' : 'dev-'.$branch;
    }

    /** The gate's own telemetry database, beside the application's. */
    private static function telemetryDatabaseUrl(): string
    {
        return getenv('FLEET_GATE_TELEMETRY_DATABASE_URL')
            ?: 'postgresql://app:app@127.0.0.1:5434/fleet_gate_telemetry?serverVersion=17&charset=utf8';
    }

    private static function workspace(): string
    {
        return getenv('FLEET_GATE_WORKSPACE') ?: \dirname(__DIR__, 3);
    }

    /** @param list<string> $command */
    private static function shell(array $command, string $cwd, string $step, bool $allowFailure = false): string
    {
        self::say('  '.$step);
        $process = new Process($command, $cwd, ['COMPOSER_MEMORY_LIMIT' => '-1', 'APP_ENV' => 'dev'], timeout: 900);
        $process->run();

        if (!$process->isSuccessful() && !$allowFailure) {
            self::fail(\sprintf(
                "FLEET GATE RED at: %s\n$ %s\n%s\n%s",
                $step,
                implode(' ', $command),
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput().$process->getErrorOutput();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, 'a free port: '.$errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    private static function say(string $line): void
    {
        fwrite(\STDERR, '[fleet-gate] '.$line."\n");
    }
}
