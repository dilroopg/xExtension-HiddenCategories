# Hidden Categories — a FreshRSS extension

Keeps a set of categories out of your way, with a switch for *how* much it enforces.

Nothing here is destructive: the extension never deletes or renames a category, never
moves a feed between categories, never marks anything read, and never touches feed
settings owned by another extension. Switching it off returns fresh FreshRSS
behaviour, and switching it back on returns your settings.

## Status

**Early.** What works today:

- the extension installs, enables and disables;
- its settings page lists every category with feed counts;
- the mode choice (`Light` / `Strict`) is stored in its own database and survives a
  disable/enable round trip;
- switching it off runs its own shutdown routine first (see *Turning it off*).

Hiding a category, the sidebar section, the right-click menu and the gear control are the
next steps and are not in this build.

## How it behaves while enabled

On a normal page the extension does **nothing**: it registers no JavaScript, no CSS and no
hook outside its own settings page. Enabling it cannot change how your stream, sidebar or
feeds look — not until you actually hide a category.

## The two modes

| | Light (default) | Strict |
|---|---|---|
| What filters the main stream | the browser | FreshRSS core, before rendering |
| Writes to your feeds | none | `hide` added per feed, previous value saved |
| API / no-JS clients | see everything | filtered |
| Switching the extension off undoes it | nothing to undo | restores every saved value |

Light is the safe mode to try first. Strict is the one that filters for every client,
at the cost of temporarily changing a real setting on the feeds of the hidden
categories — with the previous value recorded so it can be put back exactly.

## Turning it off

FreshRSS calls an extension's `uninstall()` right before it writes the disable to the
user's config, and a non-`true` return blocks the disable. This extension uses that:

1. its settings page offers **Restore everything now**;
2. switching it off in *Settings → Extensions* runs the same routine first;
3. if that routine cannot complete, the extension stays enabled and tells you why,
   so you keep a page to fix it from.

Your settings (mode, hidden set) live in the extension's own SQLite file under
`data/users/<user>/extensions/HiddenCategories/`, not in FreshRSS's config, so they
survive being off and come back when you re-enable.

## Install

Copy the directory into your extensions folder and enable it on the Extensions page:

```sh
git clone https://github.com/dilroopg/xExtension-HiddenCategories.git
cp -r xExtension-HiddenCategories /path/to/FreshRSS/extensions/
```

The folder name must be `xExtension-HiddenCategories` (it matches the `entrypoint` in
`metadata.json`). Then *Settings → Extensions → Hidden Categories* → enable.

Permissions: the web-server user must be able to read the extension directory and to
create/write `data/users/<user>/extensions/HiddenCategories/state.sqlite`. The extension
creates that directory itself and reports on its settings page if it cannot.

## Requirements

- FreshRSS 1.20 or newer (`Minz_Extension::getExtensionUserPath()`, `getUserConfigurationValue`).
- SQLite support in PHP (bundled with stock PHP).
- Tested on FreshRSS 1.30.1, PHP 8.4.

## Data it stores

Only in `data/users/<user>/extensions/HiddenCategories/state.sqlite`:

| table | holds |
|---|---|
| `meta` | schema version, mode, first-enabled / last-disabled timestamps |
| `hidden_cat` | which categories are hidden |
| `feed_snap` | per-feed attribute snapshots taken before a write (Strict mode only) |

Nothing is stored in FreshRSS's own database or config. Deleting the extension directory
leaves that state file alone; delete the file itself to forget everything.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

Note: FreshRSS itself is AGPL-3.0. This extension is an independent work that talks to it
over its public extension API; if you plan to distribute a modified bundle of both,
check the AGPL's terms for that combination.
