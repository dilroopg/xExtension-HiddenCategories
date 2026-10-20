# Hidden Categories

Part of [hardlysleeping's FreshRSS extensions](https://github.com/hardlysleeping/freshrss-extensions) — see the hub for everything else.

> **Companion: [Favorite Categories](https://github.com/hardlysleeping/xExtension-FavoriteCategories)** does the opposite job. Its star (or pin) button narrows the sidebar and stream *down to* a few categories that you mark as favorites (`Mixed` → `Just Favorites` → `Hide Favorites`). Use it when you have too many categories and want to read only a handful of them, rather than putting some away.
>
> The two extensions don't conflict, and neither one needs the other. Both hide rows when their filter is on, but they never target the same one: Favorite Categories skips any row that is already hidden, so a category that is both hidden and favorited stays hidden. When you install both, their buttons share one row above the sidebar and split the width evenly.
>
> Both extensions have Light and Strict modes. In Strict, neither one writes to a feed that the other one owns: Favorite Categories never touches a feed inside a hidden category. That way the two can't fight over the same row.

**No creeping on your hidden feeds.** You can hide categories in FreshRSS so they aren't on screen:
no sidebar entry and no articles in your main stream. You can bring them back in one click.

This extension is aimed at people reading in public places: someone at the next desk, a person
walking past, or a friend picking up your phone. The category stays in your FreshRSS and keeps
updating; it just isn't visible. In Strict mode, it also disappears from your phone and from every
synced reader app — including **NewsFlash**, where hidden categories simply don't appear.

FreshRSS has no "hide a category" switch. The usual workarounds are to unsubscribe (and lose the
feeds) or to collapse the folder (which still floods the main stream). This extension adds the
missing switch.

Nothing is deleted, renamed or moved, and no subscription is ever touched.

## Install

**Option 1 — git clone** (updates with `git pull`):

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/hardlysleeping/xExtension-HiddenCategories.git
```

**Option 2 — the release zip** (no git needed): download
`xExtension-HiddenCategories.zip` from the
[latest release](https://github.com/hardlysleeping/xExtension-HiddenCategories/releases/latest) and
unpack it in `FreshRSS/extensions/`. Unpacking a newer zip over the top updates it and leaves
your settings alone.

Then open **Settings → Extensions → Hidden Categories → enable**.

**Requirements:** FreshRSS 1.28.0 or newer. Nothing else: no build step, no config file and no
other extension. It was tested on FreshRSS 1.30.1 with PHP 8.4, on a live install with about 1000
feeds and on an empty one.

## Quick start

1. Click **Hide** next to a category. You can do this in the extension's settings, on the category
   box under **Subscription management**, or by right-clicking the category in the sidebar (that
   last option needs the [RightClickActions](#right-click-item-needs-another-extension) extension).
2. A button with a green eye appears above the sidebar. Click it to cycle through three views.
3. To get everything back, unhide the category or press **Restore everything now**.

## Pick a mode

One question decides the mode: **should your phone and reader apps see the hidden categories
too?**

| | **Light** (default) | **Strict** |
|---|---|---|
| main stream in your browser | filtered | filtered |
| phone, reader app, API | still show everything | **hidden** |
| **NewsFlash** | still shows everything | **hidden categories don't appear** |
| the category's own page | still shows its feeds | **empty until you unhide it** |
| what it writes | nothing at all | FreshRSS's per-feed Visibility; the old value is saved and restored later |
| speed when hiding | instant | a burst of work at the click (see [costs](#what-it-costs)) |

You set the mode under **Mode** in the extension settings. Switching back to Light undoes
everything that Strict did.

### Strict changes a server setting, not just the page

In Strict mode, the hiding is stored in the same place where FreshRSS keeps each feed's visibility.
That makes it one state shared by all of FreshRSS rather than one state per app. Whatever you do
here shows up everywhere, and the reverse is also true.

- **Hide a category here** and it is gone from the sidebar and stream in the web app, and from
  whatever your phone, tablet or reader app syncs — **NewsFlash** included. You don't need to
  install anything on the other side.
- **Unhide it here** and it comes back on the next sync, in every client.
- **Change a feed's Visibility** in FreshRSS's own *Subscription management* (to *Reading list*,
  *Important* or *Do not show*) and this extension sees the change too, because it reads that same
  column. If you unhide the category later, that changed value is what gets restored, not the value
  that was there when you hid it.
- **Use the `only` view** and every other feed is set to *Do not show* for all clients, so your
  phone shows exactly the hidden categories. That is what the view has to do to be true everywhere,
  and it is why the view costs [time](#what-it-costs).

None of this applies in **Light** mode. Nothing leaves the server, so only the web app filters and
your other clients keep showing everything. That difference is the whole point of the two modes.

This was tested on iOS with **FeedFlow** and **Lire**, both syncing through FreshRSS's Google
Reader API, and the two behave differently.

**FeedFlow follows it fully:** the hidden category and its feeds both go, and both come back when
you unhide.

**Lire (FreshRSS backend) keeps the category.** Its feeds stop coming through, so the category
sits there empty rather than disappearing. What reaches the app is a feed list without those feeds
— the server has no way to say "this category is hidden", only "this feed is not shown" — and an
app that stores your category tree locally can hold an empty folder until it rebuilds that cache.
A full refresh, clearing the app's cache, or re-adding the account forces that rebuild; nothing on
the server side can make an app forget a folder.

Two practical notes apply to either app. First, the change reaches the app on a **full refresh**, so
force-quit the app and open it again rather than relying on a pull-to-refresh inside the app.
Second, if the app's **cache is turned on, it may keep showing the old list anyway**. Turn the
cache off, or clear it, to see the change.

Hiding never stops fetching. Entries keep arriving and still count as unread. When you unhide a
category, the whole backlog comes back in every client, not just this browser.

## The green eye

The eye **is** the button, and its icon shows which view you are in. Clicking it cycles through the
three views:

| button | label | view | sidebar | stream | feeds |
|---|---|---|---|---|---|
| ![closed eye](docs/eye-hidden.svg) | `Hidden` | **hidden** | hidden categories are gone | their articles are gone | the hiding is applied |
| ![peeking eye](docs/eye-revealed.svg) | `Shown` | **revealed** | everything, with hidden rows tagged `HIDDEN` | hidden articles mixed in with the rest | the hiding is temporarily lifted |
| ![open eye](docs/eye-only.svg) | `Just Hidden` | **only** | everything listed, non-hidden rows dimmed, hidden rows tagged `HIDDEN` | only the hidden categories' articles | the inverse is applied: hidden feeds are revealed and all others are set to *Do not show* |

The button's label names the view you are **in**, not the one the next click moves to. So when the
button reads `Shown`, you are looking at everything; when it reads `Just Hidden`, only the hidden
categories are on screen.

**The "only" view removes the other categories; it doesn't just narrow the list.** Every article
from a category you haven't hidden disappears from the stream. In the sidebar those rows are only
dimmed, so you can still click through to them.

The `HIDDEN` tag appears only in the **revealed** and **only** views. It never appears in the
normal `hidden` view, because in that view the categories aren't on screen at all. In the other two
views, the tag sits after the category's name so you can tell at a glance which of the visible
categories you had hidden:

![hidden category tag](docs/hidden-tag.svg)

Clicking the eye again from the `only` view returns you to `hidden`, which re-applies the hiding and
undoes the inverse.

**In Strict mode, the `only` view is real for every client.** To show only the hidden categories,
the server has to hide everything else. So entering the view reveals the hidden feeds *and* sets
every non-hidden feed to *Do not show*; your phone then shows exactly the hidden categories.
Leaving the view, whether by the eye, a mode change, unhiding or disabling the extension, puts all
of them back. Those feeds are tracked separately from your hidden set, so a view never contaminates
what you actually hid. Expect a sweep on entry and on exit, sized to your non-hidden feed count.

The row at the bottom of the sidebar labels itself in one of three display states. You pick the
state under **Sidebar** in the settings:

| state | the row shows |
|---|---|
| **Nothing but the caret** | no text at all — the row is just its disclosure arrow |
| **The number only** | `7` |
| **Label and number** | `Hidden 7` (any text; the default label is `Hidden`) |

The third state uses the text you type under **Label text**. That text is kept when you switch to
another state, so moving between states never loses what you typed. If the label is empty in the
third state, the row falls back to the number alone.

All three states save with **one Save button** and give the same feedback: **After saving** updates
as you pick a state or type, and an "Unsaved changes" line stays until you commit. None of this
changes what is hidden, what Strict enforces, or the eye button. The row itself always stays,
because it is how you open the list. With the first state, someone glancing at the screen learns
nothing from it.

<details>
<summary>What "private" covers — and what it doesn't</summary>

Worth reading once, so you know what you're relying on.

**It does:** keep the category and its articles off your screen — out of the sidebar and main
stream, and in Strict out of the API that your phone and reader apps talk to. Each feed inside a
hidden category is marked *Do not show*, which is the strongest thing FreshRSS can say, so apps
that read that setting (feedlist, unread counts, articles) drop the feeds. Apps that keep their own
cached copy of your category tree may keep showing an **empty folder** until they rebuild that
cache — see the troubleshooting note below.

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

These numbers were measured on an install with about 1000 feeds. All of the cost happens at the
moment you click. Page loads stay at normal speed, and nothing runs in the background: no cron job,
no external service and no network calls.

- **Hiding or unhiding in Strict** writes FreshRSS's Visibility setting for every feed in the
  category. That takes about **1.4 s for 188 feeds** and about **3 s for 864 feeds**.
- **The peeking view is expensive.** In Strict a hidden feed is marked *Do not show*, so the server
  doesn't send its articles at all. Showing them means lifting that mark for every feed first,
  which takes about **2.6–3 s each way** for 864 feeds. In Light the view is instant.
- **The only-hidden view is the most expensive**, because it has to be true for other clients too.
  Entering the view reveals the hidden feeds *and* demotes every other feed, and leaving the view
  undoes both. That is two sweeps, each sized to your whole feed list. In Light the view is
  instant.
- **While you are peeking, your hidden feeds really are visible to every synced client**,
  including your phone. That is the only way to show them. Closing the eye hides them again.
- **While you are showing only the hidden categories, the reverse is true:** every other feed is
  marked *Do not show* for every client, so your phone shows only the hidden categories. Closing
  the eye puts them back.
- **Hidden feeds keep downloading.** Hiding changes what is displayed, never what is fetched, so
  unread counts can climb for categories you can't see.

## A note for later: per-category settings

Everything Strict does is a **per-feed** write, because FreshRSS's Visibility setting is per feed:
hiding one category means writing one row for every feed in it. That is where the seconds in the
list above come from, and it is why the only-hidden view scales with your whole feed list.

FreshRSS is heading toward **per-category settings**. When those exist, a category can be marked
*Do not show* in a single write, and this extension should use that instead: hiding becomes a few
writes rather than one per feed, and the peeking and only-hidden views stop scaling with the feed
count.

Nothing about the current behaviour changes until then. This section is a reminder for whoever
works on the extension next — if you are reading it after FreshRSS has shipped per-category
settings and the sweep here is still writing per feed, that is the first thing worth fixing.

## Getting everything back

- **One category:** click `Show` in the settings list, use the button under Subscription
  management, or right-click the category and choose *Show category*.
- **Everything at once:** press **Restore everything now** in the settings. This unhides every
  category and returns every feed to its recorded value.
- **Disable the extension:** it restores everything first and then disables itself. If the restore
  cannot complete, the disable is **refused** and the extension stays enabled with its settings
  reachable, so you can't end up with hidden feeds and no way back.

The extension keeps your settings, hidden set, mode and passcode when you disable it, and they come
back when you re-enable it. Strict then re-applies the hiding automatically, so the extension never
claims to hide things that are actually visible.

## What it writes

**Light:** the extension writes nothing. Everything it stores lives in its own SQLite file,
`data/users/<your user>/extensions/HiddenCategories/state.sqlite`, inside your FreshRSS data
folder.

**Strict:** the extension writes one column on the feeds of hidden categories: `priority`, which is
FreshRSS's own per-feed Visibility setting. It records the previous value before every write and
puts it back afterwards. It touches nothing else about a feed: not the name, URL, category or
favicon, and none of another extension's settings. It writes nothing to FreshRSS's own database or
config.

That SQLite file holds three tables. `meta` stores the view, the mode, the passcode, the lock state
and the two sidebar display switches. `hidden_cat` stores which categories are hidden. `feed_snap`
stores the Visibility values, recorded so that they can be restored. If you delete the extension
folder, the file stays behind; delete the file itself to forget everything.

## What's new

Version 1.5.9 fixes Strict quietly failing after you delete a feed: the restore pass counted a write
to a feed that no longer exists as a failure and stopped before enforcing anything, which left the
*Just Hidden* view doing nothing server-side. The full history is in
[CHANGELOG.md](CHANGELOG.md), and every release is on the
[releases page](https://github.com/hardlysleeping/xExtension-HiddenCategories/releases).

## Troubleshooting

**Hiding does nothing on my phone.** You are in Light mode. Switch to Strict.

**A reader app still shows the category, with no feeds in it.** This is the app's own cache, not
FreshRSS. Strict hides *feeds*, one at a time — it is the only lever a server has here. A fully
hidden category therefore sends no feeds at all, and an app that stores your category tree locally
can keep an empty folder until it rebuilds that tree.

**FeedFlow** follows Strict correctly — the category and its feeds both go and both come back.
**Lire** keeps the category visible, with no feeds inside. A full refresh, clearing the app's cache,
or re-adding the account forces it to rebuild from the current feed list; that is the only thing
that clears it, because there is no setting the extension can send to make an app forget a folder.

**A hidden category is empty when I click it.** This is expected in Strict: its feeds are set to
*Do not show*, which empties the category page too. Unhide the category to read it, or use the
peeking view.

**Settings won't save, or the page says the store isn't writable.** The web server can't write to
`data/users/<your user>/extensions/HiddenCategories/`. This normally only happens if FreshRSS has
been run as root from a command line, which leaves root-owned files behind. Fix the ownership with
`chown -R www-data:www-data data/users/<your user>/extensions`, using your own web-server user.

**The eye button isn't there.** It appears once something is hidden, or once you have set a
passcode so that you can lock the controls before hiding anything. While the controls are locked,
the passcode field replaces the button.

**It won't load at all.** You are on a FreshRSS version below 1.28.0, which doesn't have a feature
this extension needs.

## Right-click item needs another extension

The right-click item depends on
[**Right Click Actions**](https://github.com/printfuck/xExtension-RightClickActions)
(`xExtension-RightClickActions`). That extension is what puts a menu on the sidebar's categories
in the first place. This extension only adds *Hide category* and *Show category* to that menu.

**Everything else works without it.** The *Hide* buttons in the settings, the button under
*Subscription management* and the green eye above the sidebar all work on their own; only the
right-click route needs another extension. Without Right Click Actions, FreshRSS has no right-click
menu anywhere, so nothing is missing. This extension never modifies that one. It adds a menu item
only when the menu exists.

## How this was built

Written with AI assistance, then tested against a real FreshRSS 1.30.1 install with about 1000
feeds — enable/disable round trips, Strict writes and restores, the three views, and the lock all
checked against the database rather than assumed — and again on a **blank** FreshRSS 1.30.1, where
it installed from this release zip, enabled, hid a category, cycled the views and restored cleanly.
Strict was also checked from an iOS reader over the API: one app dropped the hidden category and its
feeds and brought them back on unhide, a second kept an empty folder behind (details under
Troubleshooting). Apps differ; the server-side setting is the same for both.

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
