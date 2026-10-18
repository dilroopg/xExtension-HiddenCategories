# Hidden Categories

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
unpack it in `FreshRSS/extensions/`. It contains one folder, `xExtension-HiddenCategories/` —
leave the folder intact. Unpacking a newer zip over the top updates it and leaves your settings
alone.

Running FreshRSS in Docker? The official `freshrss/freshrss` image has **no `unzip`**, so extract
on the host and copy the folder in, or run `unzip` from inside using the image's PHP:

```sh
unzip xExtension-HiddenCategories.zip -d .
docker cp xExtension-HiddenCategories freshrss:/var/www/FreshRSS/extensions/
```

Then open **Settings → Extensions → Hidden Categories → enable**.

**Requirements:** FreshRSS 1.28.0 or newer. Nothing else — no build step, no config file, no other
extension. Tested on FreshRSS 1.30.1 / PHP 8.4, on a live ~1000-feed install and on a blank one.

## Quick start

1. Click **Hide** next to a category — in the extension's settings, on the category box under
   **Subscription management**, or by right-clicking it in the sidebar (that one needs the
   optional [RightClickActions](#optional-right-click-menu) extension).
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

<table>
<tr>
<td align="center" width="33%">

<img alt="closed eye" src="docs/eye-hidden.svg">

**hidden**

</td>
<td align="center" width="33%">

<img alt="peeking eye" src="docs/eye-revealed.svg">

**revealed**

</td>
<td align="center" width="33%">

<img alt="open eye" src="docs/eye-only.svg">

**only**

</td>
</tr>
</table>

| view | sidebar | stream | feeds |
|---|---|---|---|
| **hidden** | hidden categories are gone | their articles are gone | hiding applied |
| **revealed** | everything, hidden ones tagged below | hidden articles mixed in with the rest | hiding temporarily lifted |
| **only** | everything listed, non-hidden rows fade back | **only** the hidden categories' articles | hiding temporarily lifted |

**"Only" removes the rest, it doesn't just narrow the list** — every article from a category you
haven't hidden disappears from the stream. In the sidebar those rows are only dimmed, so you can
still click through to them.

In the **revealed** and **only** views each hidden category in the sidebar carries a small
`HIDDEN` label after its name, so you can tell at a glance which ones are the hidden ones:

<p>
<img alt="hidden category tag" src="docs/hidden-tag.svg">
</p>

Clicking again from `only` returns you to `hidden`, which re-applies the hiding.

The row at the bottom of the sidebar shows **just a number** — how many categories are hidden, with
no wording. Someone glancing at the screen isn't told there's anything to find. The full text is in
the row's tooltip; click it to open the list.

## What "private" covers — and what it doesn't

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

## Optional passcode

For one situation: you've hidden something, someone walks up to your unlocked computer, and you
don't want them clicking the eye. Set a passcode in the settings and a red lock button appears
beside the eye:

<p>
<img alt="red lock button" src="docs/lock-button.svg">
</p>

It is **deliberately weak**, and that's a choice, not an oversight — stored in **plain text** and
shown in plain text in the settings, with **no attempt limit or lockout**. Anyone who can read the
database file can read it, and guessing isn't slowed down. It guards only this extension's own
controls: it doesn't touch FreshRSS login and doesn't hide anything from *Subscription management*.

Convenient, though: you can always see it, change it, or remove it **without knowing the current
one**, so you can't lock yourself out. Unlock lasts for your browser session; locking again is one
click. Locking hides the eye and the count row but changes nothing about your feeds — the hiding
stays applied throughout.

If you want actual security, use FreshRSS's own authentication. This is a curtain, not a door.

## What it costs

Measured on an install with about 1000 feeds. All cost is at the moment you click — page loads
stay at normal speed, and nothing runs in the background (no cron, no external service, no network
calls).

- **Hiding or unhiding in Strict** writes FreshRSS's Visibility setting for every feed in the
  category: **188 feeds ≈ 1.4 s**, **864 feeds ≈ 3 s**.
- **The peeking and only views are the expensive ones.** In Strict a hidden feed is marked *Do not
  show*, so the server doesn't send its articles at all — showing them means lifting that for every
  feed first: **≈ 2.6–3 s each way** for 864 feeds. In Light these views are instant.
- **While in those two views, your feeds really are visible to every synced client**, phone
  included. That's the only way to show them. Closing the eye hides them again.
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

That database holds three tables: `meta` (view, mode, passcode, lock state), `hidden_cat` (which
categories are hidden) and `feed_snap` (the Visibility values recorded so they can be restored).
Deleting the extension folder leaves the file alone; delete the file itself to forget everything.

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

## Optional: right-click menu

With [RightClickActions](https://github.com/printfuck/xExtension-RightClickActions) installed,
this adds *Hide category* / *Show category* to the sidebar's right-click menu. Without it there's
no right-click menu anywhere in FreshRSS, so nothing is missing — every other way of hiding still
works. This extension never modifies that one; it only adds a menu item when the menu exists.

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
