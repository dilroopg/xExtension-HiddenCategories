# Hidden Categories

A FreshRSS extension that hides categories you're not in the mood for — and brings them
back in one click.

Nothing is deleted, renamed or moved. Your feeds are not modified.

## What it does

Hide a category from wherever you already are:

- **Right-click** a category in the sidebar — *Hide category*. (Needs the
  [Right-Click Actions](https://github.com/printfucks/xExtension-RightClickActions)
  extension installed. Without it there's no right-click menu anywhere on the page, so
  nothing is missing — everything else still works.)
- **Subscription management** — a `Hide` button on each category box.
- **Extension settings** — a list of every category with `Hide` / `Show`.

Once something is hidden, one button above the sidebar cycles three states:

| icon | state | what you see |
|---|---|---|
| closed eye | **hidden** | hidden categories are gone from the sidebar and the stream |
| peeking eye | **revealed** | the hidden ones are back in the sidebar, marked `hidden` |
| open eye | **only** | everything else dimmed, the stream shows only the hidden ones |

A `Hidden (N)` row in the sidebar lists what's hidden, so unhiding never means hunting.

## Optional passcode

For when someone else uses your machine. Set a passcode in the extension settings and a
small lock button appears next to the eye.

- **Locked** → the eye is replaced by a passcode field. Hidden categories stay hidden until
  unlocked, and the `Hidden (N)` row disappears entirely.
- **Unlock** is remembered for your session (per browser, gone at logout), so you unlock
  once rather than on every page.
- **Locking does not change which view you were in.** Unlock and you're back where you left.
- The passcode is shown in plain text in the extension settings, and you can lock, unlock
  or change it there **without knowing it** — you cannot lock yourself out.
- Clear the field to disable the lock.

This is a speed bump against a passer-by clicking the eye. It is **not** security: it
encrypts nothing, and it does not hide feeds from *Subscription management*, which is a
normal FreshRSS page that always lists every feed.

## What it writes

Nothing. In the default mode the extension only filters what your browser displays — no
feed settings, no categories, no FreshRSS config. Everything it stores lives in its own
SQLite file under `data/users/<user>/extensions/HiddenCategories/`.

The one mode that would write to feeds (**Strict**, for filtering on every synced client
such as a phone app) is not implemented yet.

## Turning it off

Disabling is clean: nothing was written, so nothing needs undoing — the page returns to
stock FreshRSS immediately. Your settings (view, hidden set, passcode) stay in the
extension's own database and come back when you re-enable.

FreshRSS calls an extension's `uninstall()` before writing a disable, so the extension also
gets a chance to restore anything it had written; if it can't, the disable is refused and
the extension stays enabled with its settings page available.

## Requirements

- **FreshRSS 1.28.0 or newer.** The extension relies on `Minz_HookType::JsVars`, which does
  not exist in earlier releases; on 1.27 and below it will not work.
- SQLite support in PHP (bundled with stock PHP).
- Tested on FreshRSS 1.30.1, PHP 8.4.

## Install

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/xExtension-HiddenCategories.git
```

The directory must be named `xExtension-HiddenCategories` (FreshRSS resolves the class by
directory name). Then: *Settings → Extensions → Hidden Categories → enable*.

The web-server user needs to be able to write
`data/users/<user>/extensions/HiddenCategories/` — the extension creates it itself and says
so on its settings page if it can't.

## Data it stores

Only in `data/users/<user>/extensions/HiddenCategories/state.sqlite`:

| table | holds |
|---|---|
| `meta` | schema version, current view, passcode, lock state, timestamps |
| `hidden_cat` | which categories are hidden |
| `feed_snap` | per-feed snapshots taken before a write (Strict mode only — unused so far) |

Nothing is stored in FreshRSS's own database or config. Deleting the extension directory
leaves that file alone; delete the file itself to forget everything.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its
public extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
