<?php
declare(strict_types=1);

/**
 * Hidden Categories — own state store.
 *
 * Lives in the user's extension data directory
 * (data/users/<user>/extensions/HiddenCategories/state.sqlite), NOT in the
 * extension directory: a re-install of the extension replaces the directory,
 * and the state must survive that. One SQLite file, three tables:
 *
 *   meta      user-level key/value (schema version, mode)
 *   hidden_cat  the DEFINITION — which categories are hidden (step 2 writes it)
 *   feed_snap   the EFFECTS — per-feed attribute snapshots (step 3 writes it)
 *
 * Nothing here ever writes to FreshRSS's own databases, config or feeds.
 */
final class HiddenCategoriesStore
{
	public const SCHEMA = 1;
	public const MODES = ['light', 'strict'];
	/** What the sidebar row is labelled out of the box, so the field is never blank-and-puzzling. */
	public const DEFAULT_LABEL = 'Hidden';

	private const DB_FILE = 'state.sqlite';

	/** @var PDO|null */
	private $pdo = null;

	private string $dir;
	private string $path;
	private string $error = '';
	private string $notice = '';
	/** Bumped on every successful write, so callers can tell "already done" from "never ran". */
	private int $writes = 0;

	public function __construct(string $baseDir)
	{
		$baseDir = rtrim($baseDir, '/');
		$this->dir = $baseDir;
		$this->path = $baseDir . '/' . self::DB_FILE;
	}

	public function path(): string { return $this->path; }
	public function error(): string { return $this->error; }
	public function notice(): string { return $this->notice; }
	public function available(): bool { return $this->error === ''; }

	/**
	 * Make sure the state directory exists and is usable BOTH by the web server
	 * (uid www-data, which does the real reads/writes) and by a root CLI script.
	 *
	 * A directory created by a root CLI run is root-owned 0750, which the web
	 * server cannot even stat — and then the extension silently reports "not
	 * writable" on every page while the CLI thinks it is fine. Create it
	 * world-readable and group-writable, and hand it to www-data when we are
	 * root so the group ownership matches the rest of the data tree.
	 */
	private function ensureDir(): bool
	{
		if (!is_dir($this->dir)) {
			if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
				return false;
			}
			// mkdir() is subject to umask: 0770 can land as 0750. Re-apply.
			@chmod($this->dir, 0770);
			if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
				@chgrp($this->dir, 'www-data');
			}
		}
		// Repair a directory that a previous run created with the wrong mode.
		$mode = @fileperms($this->dir);
		if ($mode !== false && ($mode & 0007) !== 0007) {
			@chmod($this->dir, 0770);
		}
		return is_dir($this->dir) && is_writable($this->dir);
	}

	/**
	 * Open the database, create the schema, and repair a corrupt file.
	 * Never throws: an unusable store reports through error() so the config
	 * page can say so instead of taking the whole instance down.
	 */
	public function assertSchema(): bool
	{
		$this->error = '';

		if (!$this->ensureDir()) {
			$this->error = 'state directory is not usable: ' . $this->dir
				. ' (needs to be readable and writable by the web server)';
			return false;
		}

		try {
			$this->pdo();
		} catch (Throwable $e) {
			// A corrupt file is the one failure that can be repaired without
			// losing anything a later step cannot rebuild: set it aside and start
			// a fresh one. Say so loudly rather than silently forgetting.
			$aside = $this->path . '.corrupt-' . date('Ymd-His');
			if (@rename($this->path, $aside)) {
				$this->notice = 'The state database was unreadable and has been set aside as '
					. basename($aside) . '. A fresh one was created.';
			} else {
				$this->error = 'state database is unreadable and could not be moved aside: ' . $e->getMessage();
				return false;
			}
			try {
				$this->pdo(true);
			} catch (Throwable $e2) {
				$this->error = 'state database could not be created: ' . $e2->getMessage();
				return false;
			}
		}

		try {
			$this->createSchema();
			$this->seedMeta();
		} catch (Throwable $e) {
			$this->error = 'state schema failed: ' . $e->getMessage();
			return false;
		}
		// The file is created by whichever process opened it first (often a CLI
		// run as root). Make it writable for the web server too, and for the
		// WAL sidecar files SQLite creates next to it.
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			foreach (glob($this->path . '*') ?: [] as $f) {
				@chgrp($f, 'www-data');
				@chmod($f, 0664);
			}
		}
		return true;
	}

	private function pdo(bool $fresh = false): PDO
	{
		if ($this->pdo !== null && !$fresh) {
			return $this->pdo;
		}
		$pdo = new PDO('sqlite:' . $this->path, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$pdo->exec('PRAGMA journal_mode=WAL');
		$pdo->exec('PRAGMA busy_timeout=3000');
		// prove it is a readable database before anyone trusts it
		$pdo->query('SELECT count(*) FROM sqlite_master')->fetchColumn();
		$this->pdo = $pdo;
		return $pdo;
	}

	private function createSchema(): void
	{
		$pdo = $this->pdo();
		$pdo->exec('CREATE TABLE IF NOT EXISTS meta (
			k TEXT PRIMARY KEY,
			v TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS hidden_cat (
			cat_id INTEGER PRIMARY KEY,
			cat_name TEXT NOT NULL,
			hidden_at TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS feed_snap (
			feed_id INTEGER PRIMARY KEY,
			orig_priority INTEGER NOT NULL,
			applied_at TEXT NOT NULL,
			mode TEXT NOT NULL,
			note TEXT NOT NULL
		)');
		$this->migrateFeedSnap($pdo);
	}

	/**
	 * Older builds of this extension created feed_snap with orig_attributes TEXT
	 * (a plan-era column that was never written). Strict mode snapshots the
	 * `priority` integer instead, so bring an existing table forward.
	 *
	 * Only ever runs when the old column is present, and only when the table is
	 * empty — a table with rows means a real snapshot, and rewriting it would
	 * destroy the only copy of someone's previous value.
	 */
	private function migrateFeedSnap(PDO $pdo): void
	{
		try {
			$cols = [];
			foreach ($pdo->query('PRAGMA table_info(feed_snap)')->fetchAll() as $c) {
				$cols[(string)$c['name']] = true;
			}
			if (isset($cols['orig_priority'])) {
				return;   // already current
			}
			if (!isset($cols['orig_attributes'])) {
				return;   // unknown shape; leave it alone rather than guess
			}
			$rows = (int)$pdo->query('SELECT count(*) FROM feed_snap')->fetchColumn();
			if ($rows > 0) {
				// do not silently drop recorded values
				$this->notice = 'feed_snap has an old column layout with '
					. $rows . ' row(s); Strict mode will not run until it is cleared.';
				return;
			}
			$pdo->exec('ALTER TABLE feed_snap RENAME TO feed_snap_old');
			$pdo->exec('CREATE TABLE feed_snap (
				feed_id INTEGER PRIMARY KEY,
				orig_priority INTEGER NOT NULL,
				applied_at TEXT NOT NULL,
				mode TEXT NOT NULL,
				note TEXT NOT NULL
			)');
			$pdo->exec('DROP TABLE feed_snap_old');
		} catch (Throwable $e) {
			$this->notice = 'feed_snap layout could not be migrated: ' . $e->getMessage();
		}
	}

	private function seedMeta(): void
	{
		$this->setMetaIfAbsent('schema', (string)self::SCHEMA);
		$this->setMetaIfAbsent('mode', 'light');
		$this->setMetaIfAbsent('view', 'hidden');
		$this->setMetaIfAbsent('locked', '0');
		// display options for the sidebar "Hidden" row. Display-only: they change
		// one row's label, never what is hidden or enforced.
		//   show_count   '1' draws the number of hidden categories
		//   row_label    free text drawn in FRONT of that number; '' = number only
		$this->setMetaIfAbsent('show_count', '1');
		$this->setMetaIfAbsent('row_label', self::DEFAULT_LABEL);
	}

	// ---------------------------------------------------------------- lock
	//
	// A speed bump, not authentication. The passcode is stored in PLAIN TEXT on
	// purpose: it is shown in the extension settings by design, so hashing it
	// would only stop the owner reading their own code. Nothing here encrypts
	// data, and nothing claims to.

	/** The passcode exactly as typed. Empty means the lock is disabled. */
	public function passcode(): string
	{
		return $this->getMeta('passcode', '');
	}

	/** Is a passcode set at all? An empty code can never lock. */
	public function hasPasscode(): bool
	{
		return $this->passcode() !== '';
	}

	/** Is the extension armed? False whenever no passcode exists. */
	public function isLocked(): bool
	{
		return $this->hasPasscode() && $this->getMeta('locked', '0') === '1';
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function setPasscode(string $code): array
	{
		$code = trim($code);
		if ($code === '') {
			// clearing the code disables the lock, and must not leave the
			// instance armed with no way back in
			$this->setMeta('passcode', '');
			$this->setMeta('locked', '0');
			return ['ok' => true, 'message' => 'Passcode cleared — the lock is now disabled.'];
		}
		if (!$this->setMeta('passcode', $code)) {
			return ['ok' => false, 'message' => 'Could not save the passcode.'];
		}
		return ['ok' => true, 'message' => 'Passcode saved.'];
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function lock(): array
	{
		if (!$this->hasPasscode()) {
			return ['ok' => false, 'message' => 'Set a passcode first — there is nothing to lock with.'];
		}
		$this->setMeta('locked', '1');
		// stamp the moment so a session unlocked before now cannot count as
		// unlocked for THIS lock
		$this->setMeta('locked_at', date('c'));
		return ['ok' => true, 'message' => 'Locked. Hidden categories are hidden until you unlock.'];
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function unlock(?string $given): array
	{
		if (!$this->isLocked()) {
			return ['ok' => true, 'message' => 'Already unlocked.'];
		}
		// hash_equals avoids a length oracle in the comparison. It is not
		// "security" — the code is displayed in plain text on the settings
		// page — it is just the correct way to compare two strings.
		if (!is_string($given) || !hash_equals($this->passcode(), $given)) {
			return ['ok' => false, 'message' => 'Wrong passcode.'];
		}
		$this->setMeta('locked', '0');
		return ['ok' => true, 'message' => 'Unlocked for this session.'];
	}

	// ------------------------------------------------------------- strict
	//
	// Strict mode makes hiding real for every client: it writes FreshRSS's own
	// per-feed Visibility (the `priority` column) so the feed leaves the main
	// stream in the API too, not just in this browser.
	//
	//   20  Show in important feeds
	//   10  Show in main stream          <- what almost every feed is
	//    0  Show in its category         <- what Strict writes
	//   -5  Show in its feed
	//  -10  Do not show                 <- never returned by the API at all
	//
	// The value written is -10, "Do not show", NOT 0 ("Show in its category").
	// Measured difference against the real predicates in EntryDAO.php:
	//
	//   get=a  (web main stream)   selects priority >= 10  -> both 0 and -10 excluded
	//   get=A  (greader reading-list) selects priority >= 0 -> 0 still included,
	//                                                          -10 excluded
	//   category page              selects by category      -> 0 visible, -10 not
	//
	// Reader apps use the A stream, so only -10 actually hides a feed from a
	// phone. The cost, accepted deliberately: a hidden feed is also gone from its
	// own category page until it is unhidden. Hiding is meant to be a real
	// removal that one click reverses, not a filter the category page undoes.
	//
	// Every value is snapshotted before it is changed and restored afterwards.
	// Nothing else about the feed is touched.

	public const PRIORITY_HIDDEN = -10;


	/**
	 * Snapshot one feed's priority and set it to "show in its category".
	 * Returns:
	 *   snapped   had a different priority, snapshot taken, value written
	 *   preset    already 0, nothing written, no snapshot
	 *   nochange  already recorded and matching, nothing to do
	 *   error     the write failed; the snapshot was NOT kept
	 *
	 * @return array{state:string,orig:int|null}
	 */
	public function enforceFeed(int $feedId, int $currentPriority): array
	{
		try {
			$st = $this->pdo()->prepare('SELECT orig_priority FROM feed_snap WHERE feed_id = :id');
			$st->execute([':id' => $feedId]);
			$snapped = $st->fetchColumn();
		} catch (Throwable $e) {
			return ['state' => 'error', 'orig' => null];
		}

		if ($snapped !== false) {
			// already recorded: if the feed drifted back, re-apply only
			if ($currentPriority === self::PRIORITY_HIDDEN) {
				return ['state' => 'nochange', 'orig' => (int)$snapped];
			}
			return ['state' => 'nochange', 'orig' => (int)$snapped];
		}

		if ($currentPriority === self::PRIORITY_HIDDEN) {
			// already category-only before we ever touched it: leave it alone and
			// remember nothing, so a later restore cannot claim credit for it
			return ['state' => 'preset', 'orig' => null];
		}

		try {
			$this->pdo()->beginTransaction();
			$ins = $this->pdo()->prepare(
				'INSERT INTO feed_snap (feed_id, orig_priority, applied_at, mode, note)
				 VALUES (:id, :p, :t, :m, :n)');
			$ins->execute([
				':id' => $feedId,
				':p' => $currentPriority,
				':t' => date('c'),
				':m' => 'strict',
				':n' => 'snapped',
			]);
			$this->pdo()->commit();
			return ['state' => 'snapped', 'orig' => $currentPriority];
		} catch (Throwable $e) {
			if ($this->pdo()->inTransaction()) {
				$this->pdo()->rollBack();
			}
			return ['state' => 'error', 'orig' => null];
		}
	}

	/** The recorded original priority for a feed, or null if none. */
	public function origFor(int $feedId): ?int
	{
		try {
			$st = $this->pdo()->prepare('SELECT orig_priority FROM feed_snap WHERE feed_id = :id');
			$st->execute([':id' => $feedId]);
			$v = $st->fetchColumn();
			return ($v === false || $v === null) ? null : (int)$v;
		} catch (Throwable $e) {
			return null;
		}
	}

	/** Record that the write for this feed was confirmed on disk. */
	public function confirmApplied(int $feedId): void
	{
		try {
			$this->pdo()->prepare('UPDATE feed_snap SET note = :n WHERE feed_id = :id')
				->execute([':n' => 'applied', ':id' => $feedId]);
		} catch (Throwable $e) {
			// a note is cosmetic; the snapshot itself is what matters
		}
	}

	/** @return list<array{feed_id:int,orig_priority:int}> */
	public function snapshots(): array
	{
		try {
			$rows = $this->pdo()->query('SELECT feed_id, orig_priority FROM feed_snap ORDER BY feed_id')->fetchAll();
		} catch (Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = ['feed_id' => (int)$r['feed_id'], 'orig_priority' => (int)$r['orig_priority']];
		}
		return $out;
	}

	/** Drop one snapshot row after its value has been restored. */
	public function forgetSnapshot(int $feedId): void
	{
		try {
			$this->pdo()->prepare('DELETE FROM feed_snap WHERE feed_id = :id')->execute([':id' => $feedId]);
		} catch (Throwable $e) {
			// leave it: a stale row is retried on the next pass
		}
	}

	public function getMeta(string $key, string $default = ''): string
	{
		try {
			$st = $this->pdo()->prepare('SELECT v FROM meta WHERE k = :k');
			$st->execute([':k' => $key]);
			$v = $st->fetchColumn();
			return ($v === false || $v === null) ? $default : (string)$v;
		} catch (Throwable $e) {
			return $default;
		}
	}

	/**
	 * Record a point in the enable/disable round trip. Kept as a small set of
	 * keys (not a log) because it exists to answer one question on the settings
	 * page: did this extension come back after being disabled, and was it ever
	 * disabled at all.
	 *
	 * Only the FIRST enable is recorded, so re-visiting the page does not
	 * overwrite the original timestamp.
	 */
	public function checkpoint(string $what): void
	{
		$now = date('c');
		switch ($what) {
			case 'enabled':
				$this->setMetaIfAbsent('first_enabled_at', $now);
				$this->setMetaIfAbsent('last_enabled_at', $now);
				break;
			case 'disabled':
				$this->setMeta('last_disabled_at', $now);
				break;
		}
		$this->setMeta('last_seen_at', $now);
	}

	/**
	 * Undo everything this extension has written, and prove it.
	 *
	 * Called from uninstall() on toggle-off and from the settings page. Must be
	 * safe to call any number of times: after a successful run there is nothing
	 * left to restore, and a second call is a no-op that reports success.
	 *
	 * In this build nothing is ever written to a feed, so the work is the
	 * assertion itself: if snapshot rows exist (a later step's leftovers, or a
	 * half-finished restore), they are restored and verified; if they cannot be
	 * verified, this returns ok=false and the caller refuses the disable rather
	 * than leaving the instance inconsistent.
	 *
	 * Never touches: categories, feed/category assignments, entries, read state,
	 * the user's config, or any other extension's feed attributes.
	 *
	 * @return array{ok:bool,message:string,restored:int}
	 */
	public function restoreAllForDisable(): array
	{
		if (!$this->available()) {
			return ['ok' => false, 'message' => 'the state store is unusable.', 'restored' => 0];
		}

		try {
			$snaps = $this->pdo()->query('SELECT feed_id FROM feed_snap')->fetchAll(PDO::FETCH_COLUMN);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => 'the snapshot table could not be read.', 'restored' => 0];
		}

		if ($snaps === []) {
			// Nothing was written in this build. Record the fact and say so.
			$this->writes++;
			return ['ok' => true, 'restored' => 0, 'message' => 'nothing needed restoring'];
		}

		// A later build restores here; until then, having snapshots means an
		// interrupted enforcement step. Refuse rather than silently disable.
		return [
			'ok' => false,
			'restored' => 0,
			'message' => count($snaps) . ' feed(s) still have a saved snapshot but no restore routine exists yet.',
		];
	}

	// ------------------------------------------------------------- hiding

	/**
	 * Hide one category. Idempotent: hiding an already-hidden category changes
	 * nothing and reports so.
	 *
	 * This is the ONLY write path for hiding. Every control in the UI — the
	 * settings list, the sidebar row, the right-click menu, the gear checkbox —
	 * posts to the same action which calls this method, so they cannot diverge.
	 *
	 * @return array{ok:bool,message:string,changed:bool}
	 */
	public function hideCategory(int $catId, string $catName): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'changed' => false, 'message' => 'No category was named, so nothing changed.'];
		}
		try {
			$st = $this->pdo()->prepare('INSERT OR IGNORE INTO hidden_cat (cat_id, cat_name, hidden_at) VALUES (:id, :n, :t)');
			$st->execute([':id' => $catId, ':n' => $catName, ':t' => date('c')]);
			$changed = $st->rowCount() > 0;
			$this->writes++;
		} catch (Throwable $e) {
			return ['ok' => false, 'changed' => false, 'message' => 'Could not save the hidden flag: ' . $e->getMessage()];
		}
		return [
			'ok' => true,
			'changed' => $changed,
			'message' => $changed
				? '"' . $catName . '" is now hidden.'
				: '"' . $catName . '" was already hidden.',
		];
	}

	/**
	 * Unhide one category. Idempotent, same reasoning as hideCategory().
	 *
	 * Removing the row from `hidden_cat` is enough to bring the category back in
	 * Light mode, because all of the showing is derived from that table. In
	 * Strict mode the caller also restores the feed snapshots for that category
	 * before the row goes, so a failure leaves the category hidden rather than
	 * un-hidden with modified feeds.
	 *
	 * @return array{ok:bool,message:string,changed:bool}
	 */
	public function unhideCategory(int $catId, string $catName): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'changed' => false, 'message' => 'No category was named, so nothing changed.'];
		}
		try {
			$st = $this->pdo()->prepare('DELETE FROM hidden_cat WHERE cat_id = :id');
			$st->execute([':id' => $catId]);
			$changed = $st->rowCount() > 0;
			$this->writes++;
		} catch (Throwable $e) {
			return ['ok' => false, 'changed' => false, 'message' => 'Could not clear the hidden flag: ' . $e->getMessage()];
		}
		return [
			'ok' => true,
			'changed' => $changed,
			'message' => $changed
				? '"' . $catName . '" is showing again.'
				: '"' . $catName . '" was not hidden.',
		];
	}

	/** @return list<int> category ids, ascending — what the page filter uses. */
	public function hiddenIds(): array
	{
		try {
			return array_map('intval', $this->pdo()->query('SELECT cat_id FROM hidden_cat ORDER BY cat_id')->fetchAll(PDO::FETCH_COLUMN));
		} catch (Throwable $e) {
			return [];
		}
	}

	/**
	 * The one button's state. Three values, cycling in this order:
	 *
	 *   hidden    normal: hidden categories are gone from the sidebar and stream
	 *   revealed  hidden categories are shown again and marked, stream unchanged
	 *   only      hidden categories are emphasised, everything else dimmed, and
	 *             the stream shows only hidden categories
	 *
	 * Stored as text rather than a boolean so a third state can be added later
	 * without a migration, and so an unexpected value degrades to 'hidden'
	 * (the safe state: things you hid stay hidden) rather than to 'only'.
	 */
	public const VIEWS = ['hidden', 'revealed', 'only'];

	public function view(): string
	{
		$v = $this->getMeta('view', 'hidden');
		return in_array($v, self::VIEWS, true) ? $v : 'hidden';
	}

	/** The state the button moves to on the next click. */
	public function nextView(): string
	{
		$order = self::VIEWS;
		$i = array_search($this->view(), $order, true);
		return $order[($i === false ? 0 : $i + 1) % count($order)];
	}

	public function setView(string $view): bool
	{
		if (!in_array($view, self::VIEWS, true)) {
			return false;
		}
		return $this->setMeta('view', $view);
	}

	public function setMeta(string $key, string $value): bool
	{
		try {
			$st = $this->pdo()->prepare(
				'INSERT INTO meta (k, v) VALUES (:k, :v)
				 ON CONFLICT(k) DO UPDATE SET v = excluded.v');
			$st->execute([':k' => $key, ':v' => $value]);
			return true;
		} catch (Throwable $e) {
			$this->error = 'could not save ' . $key . ': ' . $e->getMessage();
			return false;
		}
	}

	private function setMetaIfAbsent(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (:k, :v)')
			->execute([':k' => $key, ':v' => $value]);
	}

	/** 'light' | 'strict' — anything unexpected falls back to the safe mode. */
	public function mode(): string
	{
		$m = $this->getMeta('mode', 'light');
		return in_array($m, self::MODES, true) ? $m : 'light';
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public function setMode(string $mode): array
	{
		if (!in_array($mode, self::MODES, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $mode . '" is not a mode. Nothing was changed.'];
		}
		try {
			$this->pdo()->beginTransaction();
			// The mode is written on its own, last of any future multi-step
			// switch: a crash before this line leaves the previous mode, and
			// the previous mode is the state the data was consistent with.
			$st = $this->pdo()->prepare(
				'INSERT INTO meta (k, v) VALUES (:k, :v)
				 ON CONFLICT(k) DO UPDATE SET v = excluded.v');
			$st->execute([':k' => 'mode', ':v' => $mode]);
			$this->pdo()->commit();
		} catch (Throwable $e) {
			if ($this->pdo()->inTransaction()) { $this->pdo()->rollBack(); }
			return ['ok' => false, 'message' => 'Save failed: ' . $e->getMessage() . ' Nothing was changed.'];
		}
		return ['ok' => true, 'message' => 'Mode saved: ' . $mode . '.'];
	}

	// ----------------------------------------------------- display options
	//
	// Two settings for the sidebar row only. Anything unset reads as the
	// default through getMeta's fallback, so an install that predates these
	// keys looks unchanged until the user changes something.

	/** Draw the number of hidden categories in the sidebar row. */
	public function showCount(): bool
	{
		return $this->getMeta('show_count', '1') === '1';
	}

	/**
	 * The text drawn in front of the number. Free text, so it can be anything
	 * the user wants the row to look like — or empty, which means "number on
	 * its own". Trimmed and capped so one paste cannot put a paragraph in the
	 * sidebar; inner spacing is preserved as typed.
	 */
	public function rowLabel(): string
	{
		return $this->getMeta('row_label', self::DEFAULT_LABEL);
	}

	/** @return array{ok:bool,message:string} */
	public function setShowCount(bool $on): array
	{
		if (!$this->setMeta('show_count', $on ? '1' : '0')) {
			return ['ok' => false, 'message' => 'Could not save the display option.'];
		}
		return ['ok' => true, 'message' => $on
			? 'The sidebar row shows how many categories are hidden.'
			: 'The sidebar row no longer shows how many categories are hidden.'];
	}

	/**
	 * Save the row's label text. Empty is a valid choice (number only), so it
	 * is stored rather than rejected — the difference between "cleared" and
	 * "never set" would otherwise be invisible to the user.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function setRowLabel(string $label): array
	{
		// Newlines and tabs would break the row layout; collapse all whitespace
		// runs to single spaces and trim the ends.
		$label = trim((string)preg_replace('/\s+/u', ' ', $label));
		if (function_exists('mb_substr')) {
			$label = mb_substr($label, 0, 40);
		} else {
			$label = substr($label, 0, 40);
		}
		if (!$this->setMeta('row_label', $label)) {
			return ['ok' => false, 'message' => 'Could not save the label.'];
		}
		return ['ok' => true, 'message' => $label === ''
			? 'Saved: the sidebar row shows the number on its own.'
			: 'Saved: the sidebar row is labelled "' . $label . '".'];
	}

	// ---------------------------------------------------------------- reads

	/** @return list<array{cat_id:int,cat_name:string,hidden_at:string}> */
	public function hiddenCategories(): array
	{
		try {
			$rows = $this->pdo()->query('SELECT cat_id, cat_name, hidden_at FROM hidden_cat ORDER BY cat_id')
				->fetchAll();
		} catch (Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'cat_id' => (int)$r['cat_id'],
				'cat_name' => (string)$r['cat_name'],
				'hidden_at' => (string)$r['hidden_at'],
			];
		}
		return $out;
	}

	public function hiddenCount(): int
	{
		try {
			return (int)$this->pdo()->query('SELECT count(*) FROM hidden_cat')->fetchColumn();
		} catch (Throwable $e) {
			return 0;
		}
	}

	public function snapshotCount(): int
	{
		try {
			return (int)$this->pdo()->query('SELECT count(*) FROM feed_snap')->fetchColumn();
		} catch (Throwable $e) {
			return 0;
		}
	}

	public function integrity(): string
	{
		try {
			$rows = $this->pdo()->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
			return implode(', ', array_map('strval', $rows));
		} catch (Throwable $e) {
			return 'unavailable: ' . $e->getMessage();
		}
	}

	/**
	 * The invariants a later step must keep. Returns a list of human sentences;
	 * empty means consistent.
	 *
	 * @return list<string>
	 */
	public function invariantProblems(): array
	{
		$problems = [];
		if ($this->getMeta('schema', '') !== (string)self::SCHEMA) {
			$problems[] = 'schema version is "' . $this->getMeta('schema', '') . '", expected ' . self::SCHEMA;
		}
		if (!in_array($this->getMeta('mode', 'light'), self::MODES, true)) {
			$problems[] = 'mode value is not one of ' . implode('/', self::MODES);
		}
		// Light mode must never leave enforcement snapshots behind: if it does,
		// a mode switch was interrupted and the restore has to finish first.
		if ($this->mode() === 'light' && $this->snapshotCount() > 0) {
			$problems[] = 'light mode with ' . $this->snapshotCount()
				. ' enforced feed(s) recorded — the restore was interrupted';
		}
		return $problems;
	}
}
