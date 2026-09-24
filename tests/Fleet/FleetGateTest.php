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
        'telemetry',
    ];

    private const string ADMIN_EMAIL = 'gate@example.test';
    private const string ADMIN_PASSWORD = 'fleet-gate-passphrase';

    private static string $project = '';
    private static string $mode = 'released';
    private static ?Process $server = null;
    private static string $baseUrl = '';

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
        $repository = 'head' === self::$mode
            ? ['type' => 'vcs', 'url' => $skeleton]
            : ['type' => 'vcs', 'url' => 'https://github.com/utafitilabs/skeleton'];

        self::shell([
            'composer', 'create-project', 'uhifadhi/skeleton', self::$project,
            '--stability=dev', '--repository='.json_encode($repository, \JSON_THROW_ON_ERROR),
            '--no-interaction', '--no-progress',
        ], \dirname(self::$project), 'README §1 create the project');

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
        file_put_contents($project.'/.env.local', 'DATABASE_URL="'.$url.'"'."\n");

        self::migrateAndCompile($project, 'README §3 core');

        return $project;
    }

    /** README §4. */
    #[Depends('testTheDatabaseIsMigrated')]
    public function testTheFirstAdministratorExists(string $project): string
    {
        if ('head' === self::$mode) {
            self::pointAt('uhifadhi/devkit-module', self::workspace().'/devkit-module');
        }
        $devkit = 'head' === self::$mode
            ? 'uhifadhi/devkit-module:'.self::headVersion(self::workspace().'/devkit-module')
            : 'uhifadhi/devkit-module:^0.1';
        self::shell(['composer', 'require', '--dev', $devkit, '--no-interaction', '--no-progress'], $project, 'README §4 devkit');
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
        self::signIn('README §5 sign in');

        return $project;
    }

    /** README §6, once per official module, in order. */
    #[Depends('testTheAdministratorSignsIn')]
    public function testEveryOfficialModuleInstallsOneByOne(string $project): void
    {
        foreach (self::OFFICIAL_MODULES as $module) {
            $package = 'uhifadhi/'.$module.'-module';
            self::say('module '.$package);

            if ('head' === self::$mode) {
                self::pointAt($package, self::workspace().'/'.$module.'-module');
                self::shell(['composer', 'require', $package.':'.self::headVersion(self::workspace().'/'.$module.'-module'), '--no-interaction', '--no-progress'], $project, $package.' require (head)');
            } else {
                // The README's own two lines: name the repository, then require.
                self::shell(['composer', 'config', 'repositories.'.$module, 'vcs', 'https://github.com/utafitilabs/'.$module.'-module'], $project, $package.' repository');
                self::shell(['composer', 'require', $package, '--no-interaction', '--no-progress'], $project, $package.' require');
            }

            self::migrateAndCompile($project, $package);
            self::shell(['composer', 'test'], $project, $package.' project smoke suite');
            self::restartServer($project);
            self::signIn($package.' sign in');
        }

        self::assertTrue(true, 'every official module installed');
    }

    // ── the steps' shared moves ─────────────────────────────────────────────

    private static function migrateAndCompile(string $project, string $step): void
    {
        self::shell(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'], $project, $step.' migrate');
        self::shell(['php', 'bin/console', 'cache:clear'], $project, $step.' cache:clear');
        self::shell(['php', 'bin/console', 'asset-map:compile'], $project, $step.' asset-map:compile');
        // The shipped migrations and the shipped entities must agree: a package
        // whose entity moved on without its migration is caught here.
        self::shell(['php', 'bin/console', 'doctrine:schema:validate', '--skip-sync', '--no-interaction'], $project, $step.' mapping valid');
        $out = self::shell(['php', 'bin/console', 'doctrine:schema:validate', '--skip-mapping', '--no-interaction'], $project, $step.' schema in sync', allowFailure: true);
        self::assertStringContainsString('in sync', $out, $step.': after migrating, the schema must need no further change');
    }

    private static function signIn(string $step): void
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
    }

    private static function serve(string $project): void
    {
        self::$baseUrl = 'http://127.0.0.1:'.self::freePort();
        self::$server = new Process(['php', '-S', substr(self::$baseUrl, 7), '-t', 'public'], $project);
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
