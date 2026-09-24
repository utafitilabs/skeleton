# The fleet gate

The last test before a release is done: `composer fleet-gate` creates a
project from this starter with the README's own commands and installs the whole
platform into it, one official module at a time.

## Contents

- [What it proves, and why the other suites cannot](#what-it-proves-and-why-the-other-suites-cannot)
- [The two modes](#the-two-modes)
- [The release rhythm](#the-release-rhythm)
- [Running it](#running-it)
- [What it does, step by step](#what-it-does-step-by-step)
- [Reading a red run](#reading-a-red-run)
- [Adding an official module](#adding-an-official-module)

## What it proves, and why the other suites cannot

Every repository in the fleet tests itself at its own `HEAD`: the core's suite,
each module's suite, the core's fleet-conformance job that runs every module
against the core's next commit. None of them performs the README's install.
A module can be pushed green and never tagged, and the tag `composer require`
then resolves names classes the core no longer has. Every build was
green while the install was broken, because no build ever performed the install.

The fleet gate performs the install. It answers one question — *does the
released fleet install and run as one product?* — and it is the only suite whose
answer depends on tags rather than commits.

## The two modes

| | `composer fleet-gate` | `composer fleet-gate:head` |
|---|---|---|
| resolves packages from | the published repositories, exactly as the README's own commands do | the branch each sibling checkout has out (`../uhifadhi`, `../patrol-module`, …), read as git repositories at its last commit — commit before you run it, but never switch branches for it |
| answers | does the released fleet install and run | would the fleet install and run if everything were tagged right now |
| runs | **after any tag** in the fleet — core, starter or module | **before a tag**, while the work is still on a branch |

The steps are identical; only where the packages come from differs.

## The release rhythm

1. Finish the change in the core, the starter or a module. That repository's
   own `composer check` is green.
2. `composer fleet-gate:head` here. Green means the fleet at `HEAD` installs as
   one product with your change in it.
3. Tag.
4. `composer fleet-gate` here. Green means what the repositories now hand out
   installs as one product. **The tag is not done until this is green.**

Step 4 runs after *any* tag in the fleet and installs *every* official module,
never only the one that was tagged: a tag on the core is what flushes out a
module that was adapted on its branch but never released.

## Running it

Needs: PHP, composer with access to the fleet's repositories (the same
`auth.json` the README's commands need), and a PostgreSQL server with PostGIS
available. The local test cluster on port 5434 is the default.

```bash
composer fleet-gate                 # released mode
composer fleet-gate:head            # head mode
```

| Variable | Meaning | Default |
|---|---|---|
| `FLEET_GATE_MODE` | `released` or `head` (the two scripts set it) | `released` |
| `FLEET_GATE_DATABASE_URL` | the server to use; **the database it names is dropped and recreated** | `postgresql://app:app@127.0.0.1:5434/fleet_gate?serverVersion=17&charset=utf8` |
| `FLEET_GATE_WORKSPACE` | head mode: the directory holding the sibling checkouts | this checkout's parent |
| `FLEET_GATE_KEEP` | set to keep the project directory afterwards, for a look | unset |

It is a PHPUnit suite of its own (`--testsuite fleet`), kept out of
`composer test` so the starter's smoke stays the ten-second check it is.

## What it does, step by step

Each step is a test, and each depends on the one before it, so a red run stops
at the first failing step and names it.

1. **README §1** — `composer create-project uhifadhi/skeleton` into a fresh
   temporary directory, with the README's own flags.
2. **README §2–3** — a fresh database, `doctrine:migrations:migrate`,
   `cache:clear`, `asset-map:compile`; then `doctrine:schema:validate` twice,
   for the mapping and for the schema, so a package whose entities moved on
   without their migration is caught before anything is served.
3. **README §4** —
   `team:user:create` for the first administrator; the command ships with the core.
4. **README §5** — the project is served with PHP's built-in server and the
   administrator signs in over HTTP: the sign-in page answers, the form is
   submitted, the redirect is followed, and the page that lands names them.
   Then the administrator creates the manual's area, Kilimani Crater
   Conservation Area, through the form at `/areas/new`.
5. **README §6, once per official module, in order** — the README's
   `composer require`, the three console commands, both schema checks, the
   catalogue listing the module, the project's own `composer test`, the sign-in
   again, the module switched on for the area through the grid's own form, and
   the module's first page answering for the administrator. Storage and
   telemetry, which have no tile, answer at the files hub and the console.

The README's official-modules table and the list the gate installs are the same
list; a test in the suite fails when they drift.

## Reading a red run

The failure message starts with `FLEET GATE RED at:` and the step, then the
exact command and its output. Two shapes recur:

- **red in released mode, green in head mode** — a package is behind on its
  tags. Tag it and run released mode again.
- **red in both** — the fleet does not fit together at `HEAD`. That is a code
  change in one of the repositories, made with its own suite first.

Set `FLEET_GATE_KEEP=1` to keep the project directory and look inside it.

## Adding an official module

Add it to `OFFICIAL_MODULES` in `tests/Fleet/FleetGateTest.php`, after any
module it requires, and to the README's *Official modules* table. The gate's own
list test fails until both are done. A private module of the managed-hosting
tier goes into `PRIVATE_MODULES` instead and stays out of the README.
