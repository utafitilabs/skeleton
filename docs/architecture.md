# The architecture

**Uhifadhi is a starter, a core and a set of modules.** The starter
(`uhifadhi/skeleton` — this repository) is copied once by
`composer create-project` and then it belongs to the installation. The core
(`uhifadhi/uhifadhi`) is one package, one version, and is updated forever through
composer. Everything a deployment can *do* — patrols, incidents, rosters —
arrives as a module, each its own package.

## Contents

- [The three layers](#the-three-layers)
- [What is in this repository](#what-is-in-this-repository)
- [What is deliberately not in it](#what-is-deliberately-not-in-it)
- [Why the starter carries the security file](#why-the-starter-carries-the-security-file)
- [Enabling the core's Stimulus controllers](#enabling-the-cores-stimulus-controllers)
- [Where a module fits](#where-a-module-fits)

## The three layers

**The starter** is a bare Symfony application: a kernel, a public entry point, a
console, the configuration files, and one test suite that asks whether a fresh
installation boots and enforces its one rule. It contains no bundle of its own,
and nothing in it is a placeholder — after `create-project` it is an ordinary
application that nobody updates from here again.

**The core** is one composer package holding five bundles under one version:

| Bundle | What it is |
|---|---|
| `RegistryBundle` | the module catalogue, the per-area install record, parking, the permissions modules declare |
| `ShellBundle` | the document, the page frame, navigation, the theme, widget surfaces |
| `TeamBundle` | people: the account, positions, departments, the sign-in and invitation screens |
| `AtlasBundle` | maps, charts and the chrome every one of them wears |
| `AreaBundle` | the ground: areas, their boundaries, the zones inside them and the overview |

One tag on the core is the platform version. An installation takes a new core
with `composer update`, and the five move together — there is no combination of
versions to reason about.

**A module** registers with the registry, renders in the shell, declares its own
permissions and is switched on per area. Adding one touches no core code and no
configuration file this repository ships.

## What is in this repository

- A Symfony 8.1 application shell: `src/Kernel.php`, `public/index.php`,
  `bin/console`, `config/`, `assets/`, `importmap.php`.
- `config/bundles.php`, pre-filled: the framework, doctrine, migrations, twig,
  security, PostGIS, the UX bundles, and the five core bundles.
- One `config/packages/<name>.yaml` per core bundle, commented, with the
  defaults an installation is most likely to change.
- `config/packages/security.yaml` — the installation's one rule.
- `config/routes/` — the shell's welcome page, the team's screens, the area
  screens.
- `compose.yaml` and `compose.override.yaml`: the services the project talks
  to in development — PostgreSQL with PostGIS, the Mercure hub, a mail catcher —
  started with `docker compose up -d` on any machine that runs Docker.
- A test suite: the container compiles with the whole core on it, the core's
  screens are addressable, and everything is behind sign-in.

## What is deliberately not in it

No bundle, no entity, no controller, no template, no fixture, no module, and no
hosting configuration: no image, no web-server file, no deploy tool's settings.
Where and how an installation is hosted is its own decision. A line
added here is a line every future installation is stuck with, so the file stays
out unless an installation genuinely owns the decision it encodes.

`src/Entity/`, `src/Controller/`, `src/Repository/` and `templates/` are empty
directories on purpose: they are where the application's own code goes if it
ever grows any, and `config/packages/doctrine.yaml` already maps the first of
them so an entity written there is seen.

## Why the starter carries the security file

Firewalls and access rules belong to the application, because only the
application knows which of its paths are public. So `security.yaml` ships here, filled
in, rather than being a paste step in a guide — a fresh installation is closed
from the moment it exists.

It names the account the team owns as its user provider, which is why the core
is a hard requirement of this package rather than an optional extra: a security
file naming a class that is not installed cannot compile.

A module never adds a rule to it. What a signed-in person may do is per action,
per object and per area, which a path rule cannot express; the module asks that
question in its own controller against the permissions it declared.

## Enabling the core's Stimulus controllers

The core ships its front-end behaviour as Stimulus controllers, and
`assets/controllers.json` is what switches them on — under **one** key, the
package's own:

```json
"@uhifadhi/uhifadhi": {
    "theme": { "enabled": true, "fetch": "eager" }
}
```

That key is the only one that resolves. StimulusBundle strips the `@`, asks
Composer where `uhifadhi/uhifadhi` is installed and reads the `assets/package.json`
underneath it; the five bundle names the core `replace`s have no install path of
their own. The identifiers stay the bundles' either way — the manifest declares
each controller's `name`, so the markup asks for `uhifadhi--shell-bundle--theme`
whichever manifest answered.

The list is the installation's. Set one to `"enabled": false` and that control
goes inert: the markup stays correct and simply has no behaviour behind it.

## Where a module fits

A module is `composer require`, a migration, and an administrator switching it
on for an area. It brings its own routes, its own tables, its own assets and its
own permissions. Nothing in this repository changes when one arrives — which is
the whole reason it is this small.
