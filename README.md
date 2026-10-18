# Hidden Categories

Keep parts of your feed reading private. Hide categories you don't want on screen — and bring
them back in one click.

This is aimed at **screen lurkers**: someone at the next desk, a person walking past, a friend
picking up your phone. The category stays in your FreshRSS and keeps updating, but it drops off
your main stream and out of the sidebar, so there is nothing on screen for them to read. In
Strict mode it also drops off your phone and every other synced app. One click brings it back
when you're alone.

FreshRSS has no "hide a category" switch. The usual workarounds are to unfollow the feeds (and
lose them) or to collapse the folder (which still floods the main stream). This adds the missing
switch: pick the categories you don't want visible, and the install behaves as if they aren't
there.

Nothing is deleted, renamed or moved, and no subscription is ever touched.

### What "private" does and does not mean here

Read this before relying on it.

**What it does hide:** the category and its articles, from the sidebar, the main stream, and (in
Strict) from the FreshRSS API — so phone apps and reader apps don't show them either. The
sidebar count is a bare number with no wording, so a glance at the screen doesn't advertise that
anything is hidden. That is the core of it; the optional passcode is a small extra on top.

**What it does not hide:**

- **Nothing is encrypted.** The feeds, the category name and the articles are all still there in
  your database, readable to anyone with access to the server or your database file.
- **Someone logged into your FreshRSS can still find everything.** *Subscription management* is a
  stock FreshRSS page that lists every feed and category, and it is not affected. Neither is a
  search for a term in a hidden article while Strict is off, nor the category's own page once
  unhidden.
- **The passcode is a speed bump, not a lock on the data.** It's stored and displayed in plain
  text and has no attempt limit — see [Optional passcode](#optional-passcode).
- **Light mode is browser-only.** It filters what *this browser* draws; a phone or another
  browser still shows everything.

If you need the content itself kept from someone with server or database access, this is not
that tool — that needs encryption at rest, which no FreshRSS extension provides.

## Install

**Option 1 — git clone** (gets updates with `git pull`):

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/xExtension-HiddenCategories.git
```

**Option 2 — download the zip**, for servers without git or if you'd rather not clone:

1. Grab `xExtension-HiddenCategories.zip` from the
   [latest release](https://github.com/dilroopg/xExtension-HiddenCategories/releases/latest).
2. Unpack it inside `FreshRSS/extensions/`. It contains a single
   `xExtension-HiddenCategories/` folder — leave that folder intact rather than copying its
   contents loose into `extensions/`.
3. To update later, unpack a newer zip over the top of the folder. Your settings and hidden set
   live outside it (see [Data it stores](#data-it-stores)) and are left untouched.

Then: **Settings → Extensions → Hidden Categories → enable**.

The directory must be named `xExtension-HiddenCategories`; FreshRSS resolves the class by
directory name. No build step, no config file, no other extension required.

Requires **FreshRSS 1.28.0 or newer** (it uses `Minz_HookType::JsVars`, which doesn't exist in
older releases). Tested on FreshRSS 1.30.1 / PHP 8.4.

## Quick start

1. Hide a category — right-click it in the sidebar → **Hide category**, use the `Hide` button
   next to each category under **Subscription management**, or the list in the extension's
   settings.
2. A button with a green eye appears above the sidebar. It cycles three views.
3. To get everything back, unhide the category — or use **Restore everything now** in the
   settings.

## Choose your mode first

Two modes. Which one you want depends on one question: **should your phone and reader apps see
the hidden categories too?**

| you want | use | what it does to your feeds |
|---|---|---|
| Hiding in this browser only. Zero risk, instantly reversible. | **Light** (default) | writes nothing at all |
| Hiding everywhere, including phone apps and reader apps. | **Strict** | sets FreshRSS's per-feed Visibility, records the old value, restores it later |

Switch with **Mode** on the extension settings page. Switching back to Light undoes every change
Strict made.

| | Light | Strict |
|---|---|---|
| main stream in your browser | filtered | filtered |
| phone / reader app / API | still sees everything | **hidden** |
| the category's own page | still shows its feeds | **empty until you unhide** |
| writes to your feeds | none | Visibility per feed, recorded and restorable |

Hiding does **not** stop fetching. Entries keep arriving and still count as unread; unhiding
brings the whole backlog back.

## The eye button

Once something is hidden, one button above the sidebar cycles three views. The eye *is* the
button; its icon tells you which view you're in. It is green. The red lock button beside it is
described [below](#optional-passcode).

| eye | view | sidebar | stream | feeds |
|---|---|---|---|---|
| closed (dim green) | **hidden** | hidden categories are gone | their articles are gone | hiding applied |
| peeking (green) | **revealed** | everything, hidden ones tagged `HIDDEN` | hidden articles mixed in with the rest | hiding temporarily lifted |
| open (green) | **only** | everything stays listed, non-hidden rows fade back | **only** hidden categories' articles — non-hidden categories are removed from the stream | hiding temporarily lifted |

Be precise about **only**: it doesn't merely narrow the list, it **removes every article from a
category you have not hidden** — in both Light and Strict. In the sidebar those rows are only
dimmed, not removed, so you can still click through to a visible category.

Clicking again from `only` returns you to `hidden`, which re-applies the hiding.

The row at the bottom of the sidebar shows **just the number** of hidden categories, not the word
"hidden" — someone glancing at your screen shouldn't be told there's anything to find. The full
wording is in the row's tooltip; click it to open the list.

## Optional passcode

The passcode is a **speed bump, not security**. It exists for one situation: you've hidden
categories, someone walks up to your unlocked computer, and you don't want them clicking the eye
and seeing what you hid.

It is deliberately weak, and that's a design choice rather than an oversight:

- **Stored in plain text.** Not hashed, not encrypted. Anyone who can read the SQLite file can
  read the passcode.
- **Displayed in plain text** in the extension settings, so you can always see it.
- **You can change or remove it without knowing the current one** — Settings → the lock card.
  You cannot lock yourself out.
- **No attempt limit, no lockout, no cooldown, no timeout.** Nothing slows repeated guessing.
- **It guards only this extension's controls** — the eye button and the count row. It doesn't
  touch FreshRSS login, and it doesn't hide anything from *Subscription management*, a stock
  FreshRSS page that always lists every feed.
- **Unlock lasts for the session** — per browser, cleared at logout. Locking again is one click.

Locking replaces the eye with a passcode field and hides the count row. It does **not** change
which view you were in, and it writes nothing to your feeds — the hiding stays applied the whole
time. Unlock and you're back where you left. Clear the passcode field to remove the lock.

If you want real protection, use FreshRSS's own authentication. This is a curtain, not a door.

## What it costs

Hiding isn't free in Strict, and peeking isn't free when Strict is on. Figures measured on an
install with about 1000 feeds.

- **Hiding or unhiding a category in Strict** writes FreshRSS's Visibility setting for every feed
  in it: **188 feeds in ~1.4 s**, **864 feeds in ~3 s**. A burst at the moment you click, not a
  per-page cost — page loads stay at normal speed.
- **Peeking and only-hidden are the expensive views**, not the cheap ones. In Strict a hidden feed
  sits at *Do not show*, where the server sends none of its articles at all, so showing them
  means lifting that setting for every feed first: **~2.6–3 s each way** for 864 feeds. In Light
  these views are instant.
- **While peeking or in only-hidden, those feeds are genuinely visible to every synced client,
  phone included.** That's how the views work — the hiding is temporarily off. Closing the eye
  hides them again.
- **Hidden feeds keep being fetched.** Hiding changes what's displayed, never what's downloaded,
  so unread counts can climb for categories you can't see.
- **Nothing runs in the background.** No cron job, no external service, no network calls. Cost
  happens when you click, and nowhere else.

## Getting everything back

- **Unhide one category** — `Show` in the settings list, the `Hide`/`Show` button under
  Subscription management, or right-click → *Show category*.
- **Restore everything now** — a button in the settings. Unhides every category and returns every
  feed to its recorded value.
- **Disable the extension** — it runs the same restore first, then disables itself. If the restore
  can't complete, the disable is **refused** and the extension stays enabled with its settings
  page reachable, so you can never end up with hidden feeds and no way to reach the control.

Your settings (view, hidden set, mode, passcode) are kept and come back when you re-enable.
Re-enabling in Strict re-applies the hiding automatically, so it never returns claiming to hide
things that are actually visible.

## What it writes

In **Light**: nothing. Everything it stores lives in its own SQLite file under
`data/users/<user>/extensions/HiddenCategories/`.

In **Strict**: one column on the feeds of hidden categories — `priority`, FreshRSS's own per-feed
Visibility. The previous integer is recorded before every write and put back afterwards. Nothing
else about a feed is touched: no name, URL, category, favicon, or another extension's settings.
Nothing is written to FreshRSS's own database or config.

## Troubleshooting

**Hiding does nothing on my phone.** You're in Light mode; switch to Strict.

**A hidden category is empty when I click it.** Expected in Strict — its feeds are set to *Do not
show*, which also empties the category page. Unhide to read it, or use the peeking view in the
browser.

**Settings won't save, or the page says the store isn't writable.** The web-server user can't
write `data/users/<user>/extensions/HiddenCategories/`. This normally only happens if FreshRSS
has been run as root from the command line, leaving root-owned files behind. Fix ownership, e.g.
`chown -R www-data:www-data data/users/<user>/extensions` — use your own web-server user.

**Right-click doesn't offer Hide.** [RightClickActions](https://github.com/printfuck/xExtension-RightClickActions)
isn't installed. Every other entry point works without it.

**The eye button isn't there.** The row appears once at least one category is hidden — or once
you've set a passcode, so you can lock before hiding anything. While locked, the eye is replaced
by the passcode field.

## Optional: right-click integration

If [RightClickActions](https://github.com/printfuck/xExtension-RightClickActions) is installed,
this extension adds *Hide category* / *Show category* to its sidebar context menu. Without it
there is simply no right-click menu anywhere in FreshRSS, so nothing is missing — every other way
of hiding still works. This extension never modifies that one; it only adds a menu item when the
menu exists.

## Data it stores

Only in `data/users/<user>/extensions/HiddenCategories/state.sqlite`:

| table | holds |
|---|---|
| `meta` | schema version, current view, mode, passcode (plain text), lock state, timestamps |
| `hidden_cat` | which categories are hidden |
| `feed_snap` | per-feed Visibility values recorded before a Strict write, so they can be restored |

Deleting the extension directory leaves that file alone; delete the file itself to forget
everything.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its
public extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
