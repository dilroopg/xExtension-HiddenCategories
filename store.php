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
			orig_attributes TEXT NOT NULL,
			applied_at TEXT NOT NULL,
			mode TEXT NOT NULL,
			note TEXT NOT NULL
		)');
	}

	private function seedMeta(): void
	{
		$this->setMetaIfAbsent('schema', (string)self::SCHEMA);
		$this->setMetaIfAbsent('mode', 'light');
		$this->setMetaIfAbsent('view', 'hidden');
	}

	// ---------------------------------------------------------------- meta

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
