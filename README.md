# Hidden Categories

A FreshRSS extension that hides categories you're not in the mood for — and brings them
back in one click.

Nothing is deleted, renamed or moved. In the default (Light) mode nothing is written to your
feeds at all. In Strict mode the only thing changed is FreshRSS's own per-feed Visibility
setting, which is recorded first so it can be put back exactly.

## What it does

Hide a category from wherever you already are:

- **Right-click** a category in the sidebar — *Hide category*. Needs the
  [RightClickActions](https://github.com/printfuck/xExtension-RightClickActions) extension
  installed. Without it there is simply no right-click menu, here or anywhere else in
  FreshRSS, so nothing is missing — every other way of hiding still works.
- **Subscription management** — a `Hide` button on each category box.
- **Extension settings** — a list of every category with `Hide` / `Show`.

## The eye button

Once something is hidden, one button above the sidebar cycles three views. The eye *is* the
button; its icon tells you which view you're in. It is green. The lock button beside it is red.

| eye | view | sidebar | stream | what happens to your feeds |
|---|---|---|---|---|
| closed (dim green) | **hidden** | hidden categories are gone | hidden categories' articles are gone | hiding applied |
| peeking (green) | **revealed** | everything, hidden ones tagged `HIDDEN` | hidden articles mixed in with the rest | hiding temporarily lifted |
| open (green) | **only** | everything stays listed, but the non-hidden rows fade back | **only** hidden categories' articles — every normally-visible (non-hidden) category is hidden from the stream | hiding temporarily lifted |

The stream column matters for `only`: it does not just narrow the list, it **removes everything
that is not hidden**. A category you have not hidden has no articles in the stream in that view
— the filter keeps the hidden ones and drops the rest, in both Light and Strict. In the sidebar
those rows are only dimmed, not removed, so you can still click through to a visible category
while you're there.

Clicking again from `only` returns you to `hidden`, which re-applies the hiding.

A row at the bottom of the sidebar shows **just the number** of hidden categories — not the
word "hidden". That is deliberate: someone glancing at your screen shouldn't be told there is
anything hidden to find. The full wording lives in the row's tooltip. Click the row to open
the list.

## Optional passcode — read this before using it

The passcode is a **speed bump, not security**. It exists for one situation: you have hidden
categories, someone walks up to your unlocked computer, and you don't want them clicking the
eye and seeing what you hid.

**It is deliberately weak, and that is a design choice rather than an oversight:**

- **Stored in plain text.** Not hashed, not encrypted. Anyone who can read the SQLite file can
  read the passcode directly.
- **Displayed in plain text** in the extension settings, so you can always see what it is.
- **You can change or remove it without knowing the current one.** Settings → the lock card.
  You cannot lock yourself out.
- **No attempt limit, no lockout, no cooldown, no timeout.** Nothing slows down repeated
  guessing.
- **It guards only this extension's own controls** — the eye button and the count row. It does
  not touch FreshRSS login, and it does not hide anything from *Subscription management*, a
  stock FreshRSS page that always lists every feed.
- **Unlock lasts for the session** — per browser, cleared at logout. Locking again is one
  click.

What it actually does:

- **Locked** → the eye is replaced by a passcode field, and the count row disappears.
- **Locking does not change which view you were in.** Unlock and you're back where you left.
- **Locking writes nothing to your feeds.** The hiding stays applied the whole time; locking
  and unlocking involve no writes at all.
- **Clear the passcode field** to disable the lock entirely.

If you want real protection, use FreshRSS's own authentication. This is a curtain, not a door.

## Two modes

**Light** (default) — nothing is written anywhere. Hidden categories and their articles are
filtered in your browser only. Your phone, your reader app and the API still show everything.
Every view above works, using only what the page already loaded.

**Strict** — hiding becomes real everywhere. Feeds in a hidden category get FreshRSS's own
Visibility setting *Do not show*, so they leave the main stream in every client, including
phone apps and reader apps. The previous value is recorded for each feed and restored when you
unhide, switch back to Light, or disable the extension.

| | Light | Strict |
|---|---|---|
| browser | filtered | filtered |
| phone / reader app | sees everything | **hidden** |
| category page | shows the hidden category | **empty until unhidden** |
| writes to your feeds | none | Visibility per feed, recorded and restorable |
| cost of hiding a category | instant | ~1.5 s per 188 feeds, ~3 s per 864 |

Hiding does **not** stop fetching. Entries keep arriving and still count as unread. Unhiding
brings the whole backlog back.

## What it costs

Hiding is not free in Strict, and peeking is not free when Strict is on.

- **Hiding or unhiding a category in Strict** writes FreshRSS's Visibility setting for every
  feed in it. Measured: **188 feeds in ~1.4 s**, **864 feeds in ~3 s**. It is a burst at the
  moment you click, not a per-page cost — page loads stay at their normal speed.
- **Peeking and only-hidden are the expensive views**, not the cheap ones. In Strict a hidden
  feed sits at *Do not show*, where the server never sends its articles at all — so the only
  way to show them is to lift that setting for every feed first. Measured **~2.6–3 s each way**
  for 864 feeds. In Light these views are instant, because nothing was ever suppressed.
- **While peeking or in only-hidden, your feeds are genuinely visible to every synced
  client, phone included.** Those views work by temporarily turning the hiding off, so
  anything that syncs during that window sees them. Closing the eye hides them again.
- **Hidden feeds keep being fetched.** Hiding changes what is displayed, never what is
  downloaded. Unread counts can climb for categories you cannot see.
- **Nothing runs in the background.** No cron job, no external service, no network calls. Cost
  happens when you click, and nowhere else.

## What it writes

In **Light**: nothing. Everything it stores lives in its own SQLite file under
`data/users/<user>/extensions/HiddenCategories/`.

In **Strict**: one column on the feeds of hidden categories — `priority`, FreshRSS's own
per-feed Visibility. The previous integer is recorded before every write and put back
afterwards. Nothing else about a feed is touched: no name, URL, category, favicon, or another
extension's settings.

## Turning it off

Switching it off runs the extension's own restore first, then disables it: any feed setting it
changed goes back to what it was before (Strict), and the page returns to stock FreshRSS (which
was always true in Light). If the restore cannot complete, the disable is **refused** and the
extension stays enabled with its settings page available — so you cannot end up with feeds left
hidden and no way to reach the control.

Your settings (view, hidden set, mode, passcode) stay in the extension's own database and come
back when you re-enable. Re-enabling in Strict re-applies the hiding automatically, so the
extension never comes back claiming to hide things that are actually visible.

## Requirements

**FreshRSS 1.28.0 or newer.** The extension relies on `Minz_HookType::JsVars`, which does not
exist in earlier releases; on 1.27 and below it will not work. Nothing else is needed: no PHP
extensions beyond what FreshRSS already requires (SQLite support, bundled with stock PHP), no
build step, no network access, no other extension. Tested on FreshRSS 1.30.1, PHP 8.4.

RightClickActions is optional: if installed you get right-click-to-hide, if not everything
else works unchanged.

## Install

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/xExtension-HiddenCategories.git
```

The directory must be named `xExtension-HiddenCategories` (FreshRSS resolves the class by
directory name). Then: *Settings → Extensions → Hidden Categories → enable*.

The web-server user needs to be able to write
`data/users/<user>/extensions/HiddenCategories/` — the extension creates it itself and says so
on its settings page if it can't.

## Data it stores

Only in `data/users/<user>/extensions/HiddenCategories/state.sqlite`:

| table | holds |
|---|---|
| `meta` | schema version, current view, mode, passcode (plain text), lock state, timestamps |
| `hidden_cat` | which categories are hidden |
| `feed_snap` | per-feed Visibility values recorded before a Strict write, so they can be restored |

Nothing is stored in FreshRSS's own database or config. Deleting the extension directory
leaves that file alone; delete the file itself to forget everything.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its
public extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
