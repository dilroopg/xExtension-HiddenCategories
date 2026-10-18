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

## Two modes

**Light** (default) — nothing is written. Hidden categories and their articles are filtered
in your browser only. Your phone, your reader app and the API still show everything.

**Strict** — hiding becomes real everywhere. Feeds in a hidden category get FreshRSS's own
Visibility setting *Do not show*, so they leave the main stream in every client, including
phone apps and reader apps. The previous value is saved for each feed and restored when you
unhide, switch back to Light, or disable the extension.

| | Light | Strict |
|---|---|---|
| browser | filtered | filtered |
| phone / reader app | sees everything | **hidden** |
| category page | shows the hidden category | **empty until unhidden** |
| writes to your feeds | none | Visibility per feed, restorable |
| cost of hiding a big category | instant | ~1.5 s per 188 feeds |

Hiding in Strict does **not** stop fetching: entries keep arriving and still count as
unread. Unhiding brings the whole backlog back.

## Three views, one button

Clicking the eye in the sidebar cycles:

| eye | what you see | what happens to your feeds |
|---|---|---|
| closed | hidden categories gone | enforcement applied |
| peeking | hidden ones **mixed back in** with everything else, tagged `HIDDEN` | **enforcement suspended** |
| open | **only** hidden categories | **enforcement suspended** |

Closing the eye re-applies the hiding.

## What it costs

Hiding is not free in Strict, and peeking is not free in any mode where Strict is on.

- **Hiding or unhiding a category in Strict** writes FreshRSS's Visibility setting for every
  feed in it. Measured: **188 feeds in ~1.4 s**, **864 feeds in ~3 s**. It is a burst at the
  moment you click, not a per-page cost — page loads stay at their normal speed.
- **Peeking and only-hidden are the expensive views**, not the cheap ones. At *Do not show*
  the server never sends those articles, so the only way to show them is to lift the setting
  for every feed first: **~2.6–3 s each way** for 864 feeds.
- **While peeking or in only-hidden, your feeds are genuinely visible to every synced
  client**, phone included. Those views work by turning the hiding off temporarily, so
  anything syncing during that window sees them. Closing the eye hides them again.
- **Hidden feeds keep being fetched.** Hiding changes what is displayed, never what is
  downloaded. Unread counts can climb for categories you cannot see, and unhiding brings the
  whole backlog back.
- **Nothing runs in the background.** No cron, no external service, no network calls. Cost
  happens when you click, and nowhere else.

## What it writes

In **Light** (the default): nothing. Everything it stores lives in its own SQLite file
under `data/users/<user>/extensions/HiddenCategories/`.

In **Strict**: one column on the feeds of hidden categories — `priority`, FreshRSS's own
per-feed Visibility. The previous integer is recorded before every write and put back
afterwards. Nothing else about a feed is touched: no name, URL, category, favicon or another
extension's settings.

## Turning it off

Switching it off runs the extension's own restore first, then disables it: any feed value it
changed goes back to what it was before (Strict), and the page returns to stock FreshRSS
(always true in Light). If the restore cannot complete, the disable is refused and the
extension stays enabled with its settings page available — so you cannot end up with feeds
left hidden and no way to reach the control.

Your settings (view, hidden set, mode, passcode) stay in the extension's own database and
come back when you re-enable. Re-enabling in Strict re-applies the hiding automatically.

## Requirements

**FreshRSS 1.28.0 or newer.** The extension relies on `Minz_HookType::JsVars`, which does not
exist in earlier releases; on 1.27 and below it will not work. Nothing else is needed: no PHP
extensions beyond what FreshRSS already requires (SQLite support, bundled with stock PHP), no
build step, no network access, no other extension. Tested on FreshRSS 1.30.1, PHP 8.4.

It also integrates with
[RightClickActions](https://github.com/printfuck/xExtension-RightClickActions) if that is
installed (right-click a category to hide it) and behaves normally without it.

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
