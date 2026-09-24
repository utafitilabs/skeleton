# Maintaining the skeleton

This repository is the starter `composer create-project` copies. Two audiences
read it: whoever maintains the starter, and whoever runs an installation that
came out of it. They have opposite jobs.

## Contents

- [The copy is frozen; the core is not](#the-copy-is-frozen-the-core-is-not)
- [The version rhythm](#the-version-rhythm)
- [The fleet gate](#the-fleet-gate)
- [The recipe ledger](#the-recipe-ledger)
- [What belongs here, and what does not](#what-belongs-here-and-what-does-not)

## The copy is frozen; the core is not

After `create-project`, the files in an installation are that installation's.
Nothing pushed to this repository ever reaches it. Its `config/bundles.php`,
`config/packages/*.yaml` and `config/routes/*.yaml` are edited by the people who
run it, and a change made here is a change the *next* installation gets.

Updating an installation is therefore one command, and it is not about this
repository at all:

```bash
composer update uhifadhi/uhifadhi
php bin/console doctrine:migrations:migrate --dry-run   # read what will run
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
php bin/console asset-map:compile
```

The core carries its own versions, so an update brings the ones it added and
`migrate` runs them. Back the database up first: that is the step nothing here
replaces.

The core is one package and one version, so there is no combination of bundle
versions to reason about: the five move together or not at all.

`doctrine:migrations:diff` stays the installation's command for the
installation's own entities, and the namespace
`config/packages/doctrine_migrations.yaml` maps —
`'DoctrineMigrations': '%kernel.project_dir%/migrations'` — is where a flagless
`diff` writes. That is not the library's own fallback, which is the first
configured namespace and would be a core bundle's directory under `vendor/`; the
core moves the directory no installed bundle ships to the front. So the mapping
in that file is load-bearing, and an installation that deletes it has a `diff`
that writes into a package again.

## The version rhythm

**The core's minor is the starter's minor.** When the core takes a minor, the
starter that raises its constraint to match takes one too, whether or not a line
of this repository's own source moved. The version here is not a count of edits;
it is the name of the installation a `create-project` produces, and that
installation is a different one.

A patch here says "the same installation, fixed". Raising the core constraint to
a new minor never means that.

## The fleet gate

A tag anywhere in the fleet — this starter, the core, an official module — is
not done until `composer fleet-gate` has run green here, and a change is not
ready to tag until `composer fleet-gate:head` has. Both create a project from
this starter with the README's own commands and install every official module
into it, one by one. [fleet-gate.md](fleet-gate.md) has the steps, the two modes and
how to read a red run.

## The recipe ledger

`symfony.lock` records, for every installed package, which recipe version was
applied, its hash and the files it owns. Change a recipe's bytes in the
[recipes](https://github.com/utafitilabs/recipes) repository, add a recipe
version, or hand-edit a recipe-owned file here, and the ledger has to be
re-synced:

```bash
composer recipes:update <vendor/package>
```

Without it, `composer recipes` reports "update available" with nothing to apply,
on every fresh installation, because the hash it compares no longer matches.

Recipes are also why a file this repository owns can be overwritten by an
install: keep any hand-written addition to a recipe-owned file in one contiguous
block at the end, under a marker. Restoring one hunk is a review; restoring an
interleaved diff is an excavation.

## What belongs here, and what does not

The test: **does an installation own the decision?**

Belongs here — the security file, the bundle list, the per-bundle configuration
files with their defaults commented, the route imports, the database
configuration, the deploy shape.

Does not — anything a bundle can state for itself. A doctrine mapping, a service
definition, a template namespace, a tag: the core states those from inside, and
a copy of one here is a second place for the two to disagree. Neither does
anything a module owns: a module brings its own routes, tables, assets and
permissions, and adding one changes no file this repository ships.
