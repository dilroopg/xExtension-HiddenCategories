# Changelog

Newest first. These version numbers match the [releases page](https://github.com/dilroopg/xExtension-HiddenCategories/releases).

**1.5.7** — the README says how another extension can hide categories through this one.

The new `hideCategories()` method had no documentation, so a caller had no way to know it exists,
what it returns, or that a missing or disabled extension means "nothing to do". The README now has a
*Calling it from your own extension* section with the guard and a worked call. Nothing in the
extension's own behaviour changed.

**1.5.5** — the button label names the state you are in.

1.5.3 flipped the labels to name the *destination* — what you would see after clicking. That turned
out to be unreadable in practice: the eye told you where you were going while you were looking at
something else, so the label never matched the page.

The label now names what is on screen **right now**:

| You are looking at | Button reads |
|---|---|
| hidden categories removed | **Hidden** |
| everything back, hidden ones marked | **All shown** |
| just the hidden categories | **Only hidden** |

The tooltip says the same thing in a sentence. Clicking still cycles in the same order.

**1.5.4** — two-word labels wrap instead of being cut off.

The eye button's label was clipped with an ellipsis, so *Only hidden* and *Show all* read as
*Only hid…*. The text now wraps onto two lines at the space, the way the star button in the
companion extension does. Stylesheet and label markup only.

**1.5.3** — the button labels now name the destination.

The labels named the *action*, which read ambiguously next to each other: from the normal state the
button said *Show all* while everything was already showing, and from the peek state it said *Show
all* again while the click actually narrowed things down to only the hidden categories.

Each label now says what you will be looking at **after** the click:

| Showing now | Button says | After clicking |
|---|---|---|
| hidden categories removed | **Show all** | everything back, hidden ones marked |
| everything back, hidden ones marked | **Only hidden** | just the hidden categories |
| just the hidden categories | **Hide** | hidden categories removed again |

Same three states, same cycle, same writes. Labels and tooltips only.

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
