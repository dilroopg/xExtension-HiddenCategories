# Hidden Categories

> **Companion: [Favorite Categories](https://github.com/dilroopg/xExtension-FavoriteCategories)** — the opposite job. The star button filters the sidebar and stream *down to* a few categories you mark as favorites (`Show favorites` → `Hide favorites` → `Show all`). Use it when you have too many categories and want to read only a handful, rather than putting some away.
>
> The two don't conflict and neither needs the other. This extension hides rows; Favorite only dims them, and every rule it writes skips rows already hidden — so a category that is both **hidden and favorited stays hidden**. Installed together, their buttons share one row above the sidebar, splitting the width evenly.
>
> Both have Light and Strict modes, and in Strict neither will ever write to a feed the other owns: Favorite never touches a feed inside a hidden category, so the two can't fight over the same row.

**No creeping on your hidden feeds.** Hide categories from FreshRSS so they aren't on screen —
no sidebar entry, no articles in your main stream — and bring them back in one click.

This is aimed at screen lurkers: someone at the next desk, a person walking past, a friend
picking up your phone. The category stays in your FreshRSS and keeps updating; it just isn't
visible. In Strict mode it also disappears from your phone and every synced reader app.

FreshRSS has no "hide a category" switch — the usual workarounds are to unsubscribe (and lose the
feeds) or collapse the folder (which still floods the main stream). This adds the missing switch.

Nothing is deleted, renamed or moved, and no subscription is ever touched.

## Install

**Option 1 — git clone** (updates with `git pull`):

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/xExtension-HiddenCategories.git
```

**Option 2 — the release zip** (no git needed): download
`xExtension-HiddenCategories.zip` from the
[latest release](https://github.com/dilroopg/xExtension-HiddenCategories/releases/latest) and
unpack it in `FreshRSS/extensions/`. Unpacking a newer zip over the top updates it and leaves
your settings alone.

Then open **Settings → Extensions → Hidden Categories → enable**.

**Requirements:** FreshRSS 1.28.0 or newer. Nothing else — no build step, no config file, no other
extension. Tested on FreshRSS 1.30.1 / PHP 8.4, on a live ~1000-feed install and on a blank one.

## Quick start

1. Click **Hide** next to a category — in the extension's settings, on the category box under
   **Subscription management**, or by right-clicking it in the sidebar (that one needs the
   [RightClickActions](#right-click-item-needs-another-extension) extension).
2. A button with a green eye appears above the sidebar. Click it to cycle through three views.
3. To get everything back: unhide the category, or press **Restore everything now**.

## Pick a mode

One question decides it: **should your phone and reader apps see the hidden categories too?**

| | **Light** (default) | **Strict** |
|---|---|---|
| main stream in your browser | filtered | filtered |
| phone, reader app, API | still show everything | **hidden** |
| the category's own page | still shows its feeds | **empty until you unhide it** |
| what it writes | nothing at all | FreshRSS's per-feed Visibility, old value saved and restored later |
| speed when hiding | instant | a burst at the click (see [costs](#what-it-costs)) |

Set it under **Mode** in the extension settings. Switching back to Light undoes everything Strict
did.

Hiding never stops fetching. Entries keep arriving and still count as unread; unhiding brings the
whole backlog back.

## The green eye

The eye *is* the button, and its icon shows which view you're in. Clicking cycles:

| ![closed eye](docs/eye-hidden.svg) | ![peeking eye](docs/eye-revealed.svg) | ![open eye](docs/eye-only.svg) |
|---|---|---|
| **hidden** — what you hid is gone | **revealed** — everything, hidden ones marked | **only** — just the hidden ones |

| view | sidebar | stream | feeds |
|---|---|---|---|
| **hidden** | hidden categories are gone | their articles are gone | hiding applied |
| **revealed** | everything, hidden rows tagged `HIDDEN` | hidden articles mixed in with the rest | hiding temporarily lifted |
| **only** | everything listed, non-hidden rows fade back, hidden rows tagged `HIDDEN` | **only** the hidden categories' articles | inverse applied: hidden feeds revealed, all others set to *Do not show* |

**"Only" removes the rest, it doesn't just narrow the list** — every article from a category you
haven't hidden disappears from the stream. In the sidebar those rows are only dimmed, so you can
still click through to them.

The `HIDDEN` tag only appears in the **revealed** and **only** views — it is never shown in the
normal `hidden` view, because in that view the categories aren't on screen at all. In those two
views it sits after the category's name, so you can tell at a glance which of the visible ones
are the ones you'd hidden:

![hidden category tag](docs/hidden-tag.svg)

Clicking again from `only` returns you to `hidden`, which re-applies the hiding and undoes the
inverse.

**In Strict, `only` is real for every client.** Showing only the hidden categories means the server
has to hide everything else, so entering the view reveals the hidden feeds *and* sets every
non-hidden feed to *Do not show*. Your phone then shows exactly the hidden categories. Leaving the
view — by the eye, a mode change, unhiding, or disabling the extension — puts all of them back.
Those feeds are tracked separately from your hidden set, so a view never contaminates what you
actually hid. Expect a sweep on entry and exit, sized to your non-hidden feed count.

The row at the bottom of the sidebar labels itself with one of three display states, picked under
**Sidebar** in the settings:

| state | the row shows |
|---|---|
| **Nothing but the caret** | no text at all — the row is just its disclosure arrow |
| **The number only** | `7` |
| **Label and number** | `Hidden 7` (any text; default label is `Hidden`) |

The label under **Label text** is used by the third state and is kept when you switch away from it,
so moving between states never loses what you typed. An empty label in the third state falls back
to the number alone.

All three save with **one Save button** and give the same feedback: **After saving** updates as you
pick a state or type, and an "Unsaved changes" line stays until you commit. Nothing here changes
what is hidden, what Strict enforces, or the eye button — the row itself always stays, because it's
how you open the list. With the first state, someone glancing at the screen learns nothing from it.

<details>
<summary>What "private" covers — and what it doesn't</summary>

Worth reading once, so you know what you're relying on.

**It does:** keep the category and its articles off your screen — out of the sidebar and main
stream, and in Strict out of the API that your phone and reader apps talk to.

**It doesn't:**

- **Encrypt anything.** The feeds, category names and articles are all still in your database,
  readable by anyone with access to the server or the database file.
- **Stop someone logged into FreshRSS.** *Subscription management* is a standard FreshRSS page that
  lists every feed and category, and it's unaffected. So is the category's own page once unhidden.
- **Protect you in Light mode on other devices.** Light filters this browser only; a phone or
  another browser still shows everything.
- **Provide real password security.** The optional passcode below is a speed bump, not a lock.

If you need the content itself kept from someone with server access, this isn't the tool for it —
that needs encryption at rest, which no FreshRSS extension provides.

</details>

<details>
<summary>Optional passcode lock</summary>

For one situation: you've hidden something, someone walks up to your unlocked computer, and you
don't want them clicking the eye. Set a passcode in the settings and a red lock button appears
beside the eye:

![red lock button](docs/lock-button.svg)

It is **deliberately weak**, and that's a choice, not an oversight — stored in **plain text** and
shown in plain text in the settings, with **no attempt limit or lockout**. Anyone who can read the
database file can read it, and guessing isn't slowed down. It guards only this extension's own
controls: it doesn't touch FreshRSS login and doesn't hide anything from *Subscription management*.

Convenient, though: you can always see it, change it, or remove it **without knowing the current
one**, so you can't lock yourself out. Unlock lasts for your browser session; locking again is one
click. Locking hides the eye and the count row but changes nothing about your feeds — the hiding
stays applied throughout.

If you want actual security, use FreshRSS's own authentication. This is a curtain, not a door.

</details>

## What it costs

Measured on an install with about 1000 feeds. All cost is at the moment you click — page loads
stay at normal speed, and nothing runs in the background (no cron, no external service, no network
calls).

- **Hiding or unhiding in Strict** writes FreshRSS's Visibility setting for every feed in the
  category: **188 feeds ≈ 1.4 s**, **864 feeds ≈ 3 s**.
- **The peeking view is expensive.** In Strict a hidden feed is marked *Do not show*, so the server
  doesn't send its articles at all — showing them means lifting that for every feed first:
  **≈ 2.6–3 s each way** for 864 feeds. In Light it is instant.
- **The only-hidden view is the most expensive**, because it has to be true for other clients too:
  entering it reveals the hidden feeds *and* demotes every other feed, and leaving it undoes both.
  That is two sweeps sized to your whole feed list. In Light it is instant.
- **While peeking, your hidden feeds really are visible to every synced client**, phone included —
  that is the only way to show them. Closing the eye hides them again.
- **While showing only hidden, the reverse is true:** every other feed is marked *Do not show* for
  every client, so your phone shows only the hidden categories. Closing the eye puts them back.
- **Hidden feeds keep downloading.** Hiding changes what's displayed, never what's fetched, so
  unread counts can climb for categories you can't see.

## Getting everything back

- **One category** — `Show` in the settings list, the button under Subscription management, or
  right-click → *Show category*.
- **Everything at once** — **Restore everything now** in the settings. Unhides every category and
  returns every feed to its recorded value.
- **Disable the extension** — it restores first, then disables. If the restore can't complete the
  disable is **refused** and the extension stays enabled with its settings reachable, so you can't
  end up with hidden feeds and no way back.

Your settings, hidden set, mode and passcode are kept when you disable, and come back when you
re-enable — where Strict re-applies the hiding automatically, so it never claims to hide things
that are actually visible.

## What it writes

**Light:** nothing. Everything it stores lives in its own SQLite file —
`data/users/<your user>/extensions/HiddenCategories/state.sqlite` (your FreshRSS data folder).

**Strict:** one column on the feeds of hidden categories — `priority`, FreshRSS's own per-feed
Visibility. The previous value is recorded before every write and put back afterwards. Nothing
else about a feed is touched: no name, URL, category, favicon, or another extension's settings.
Nothing is written to FreshRSS's own database or config.

That database holds three tables: `meta` (view, mode, passcode, lock state, the two sidebar display
switches), `hidden_cat` (which categories are hidden) and `feed_snap` (the Visibility values
recorded so they can be restored). Deleting the extension folder leaves the file alone; delete the
file itself to forget everything.

## Changelog

**1.5.2** — the view button says *Show* / *Show all* / *Hide*.

The three labels named each state at length ("Show hidden", "Show only hidden", "Hide them again").
They are now one or two words each — *Show all* rather than a bare *All*, so the label says what
the click does — in the sidebar button, the settings page, and the banner that appears while only
hidden categories are showing. Nothing about the views themselves changed.

**1.5.0** — *show only hidden* now works on every client in Strict.

- It was browser-only, and that was a flaw: entering the view revealed the hidden feeds so their
  articles could render, which meant every synced client saw everything. A server can only say
  "this feed is not shown", not "show only these".
- Strict now enforces the inverse too: entering the view reveals the hidden feeds **and** sets every
  non-hidden feed to *Do not show*. Your phone then shows exactly the hidden categories, and
  leaving the view restores all of them.
- The feeds demoted to make the view work are recorded in their own table, never mixed with your
  hidden set, and are restored on every exit path including disabling the extension.
- Cost: a sweep on entry and exit, sized to your non-hidden feed count.

**1.4.5** — the settings page now says the right-click item needs the RightClickActions extension,
instead of leaving that only in this README.

**1.4.4** — the control bar above the sidebar became shared ground, so a second extension can put a
button beside the eye. Its children size themselves: one control takes the full width, two split it.

**1.4.3** — the sidebar row's three display states, including label-only.

- The on/off "show the count" checkbox is replaced by a three-option choice: **nothing but the
  caret**, **the number only**, or **label and number**. The checkbox could not express "label but
  no number", which is the state that actually obscures the row.
- The label under **Label text** is kept when you switch to another state, so moving to caret-only
  and back does not lose it. An empty label in the third state falls back to the number alone.
- The display state is stored explicitly (`rowmode`) instead of being inferred from two flags.
  Existing settings are migrated automatically on read; nothing is lost.
- 1.4.2 and 1.4.1 are folded in: the label is free text (it was an on/off prefix switch), and both
  settings save with one **Save** button, a live **After saving** preview, and an
  "Unsaved changes" line.

**1.3.5** — the `HIDDEN` tag gained a note on when it appears; the tag illustration was redrawn.

Earlier versions are on the [releases page](https://github.com/dilroopg/xExtension-HiddenCategories/releases).

## Troubleshooting

**Hiding does nothing on my phone.** You're in Light mode — switch to Strict.

**A hidden category is empty when I click it.** Expected in Strict: its feeds are set to *Do not
show*, which empties the category page too. Unhide it to read it, or use the peeking view.

**Settings won't save / the page says the store isn't writable.** The web server can't write
`data/users/<your user>/extensions/HiddenCategories/`. This normally only happens if FreshRSS has
been run as root from a command line, leaving root-owned files behind. Fix ownership with
`chown -R www-data:www-data data/users/<your user>/extensions` (use your own web-server user).

**The eye button isn't there.** It appears once something is hidden — or once you've set a
passcode, so you can lock before hiding anything. While locked, the passcode field replaces it.

**It won't load at all.** You're on FreshRSS below 1.28.0, which doesn't have a feature it needs.

## Right-click item needs another extension

The right-click item depends on
[**Right Click Actions - Feed Settings Panel**](https://github.com/printfuck/xExtension-RightClickActions)
(`xExtension-RightClickActions`). That extension is what puts a menu on the sidebar's
categories in the first place; this one only appends *Hide category* / *Show category* to that
menu.

**Everything else works without it.** The *Hide* buttons in the settings, the button under
*Subscription management*, and the green eye above the sidebar don't need any other extension —
only the right-click route does. With Right Click Actions absent there is no right-click menu
anywhere in FreshRSS, so nothing is missing. This extension never modifies that one; it adds a
menu item only when the menu exists.

## How this was built

Written with AI assistance, then tested against a real FreshRSS 1.30.1 install with about 1000
feeds — enable/disable round trips, Strict writes and restores, the three views, and the lock all
checked against the database rather than assumed — and again on a **blank** FreshRSS 1.30.1, where
it installed from this release zip, enabled, hid a category, cycled the views and restored cleanly.

That's disclosed so you know what you're installing. **Forking and reading the code is
encouraged.** It's about 2,900 lines across six files and none of it is clever — the parts worth
reading are `extension.php` (`sweepFeeds()`, which does every write and the restore),
`store.php` (the SQLite store, the only thing that persists anything) and `static/hidden.js` (the
views). The goal was a working extension with as little code as possible: no framework, no build
step, no dependencies, no background jobs. A PR that prunes it is welcome.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its public
extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
