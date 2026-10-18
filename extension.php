<?php
declare(strict_types=1);

/**
 * Hidden Categories
 *
 * Keep a set of categories out of the way.
 *
 *   Light  (default) — nothing is written to the feeds. The sidebar and the
 *                      article list are filtered in the browser.
 *   Strict           — feeds in a hidden category get FreshRSS's own
 *                      "only show in this category" setting, with the previous
 *                      value snapshotted so it can be restored exactly.
 *
 * This extension never deletes or renames a category, never moves a feed
 * between categories and never touches another extension's feed attributes.
 * Enabling or disabling it writes nothing to FreshRSS's own data.
 *
 * Step 1 of the build: skeleton + store + config page + mode switch. Hiding,
 * the sidebar and feed enforcement arrive in the next steps; nothing in this
 * file writes to a feed.
 */
class HiddenCategoriesExtension extends Minz_Extension
{
	private const MODE_LABELS = [
		'light' => 'Light — visual only',
		'strict' => 'Strict — enforce on feeds',
	];

	/** @var HiddenCategoriesStore|null */
	private $store = null;

	/**
	 * Two entry points, both registered from getConfigureView().
	 *
	 * The extension is deliberately INERT on normal pages: init() registers no
	 * hook, no script and no style, so enabling it cannot change how any page
	 * renders. Everything this extension does is either scoped to its own
	 * settings page or (from step 3) a feed attribute it has a snapshot for.
	 * That keeps "disabling it restores the previous state" true by
	 * construction rather than by cleanup.
	 */
	public function init(): void
	{
		require_once __DIR__ . '/store.php';

		// A broken state store must never take the instance down: report and
		// carry on with the safe defaults.
		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: state store unavailable: ' . $e->getMessage());
		}
	}

	/**
	 * Runs on every render of this extension's settings page (GET and POST),
	 * before the page is built. This is where the two page-load-only jobs live:
	 * finish an interrupted restore, and record "this extension was enabled"
	 * once so the round trip can be proven.
	 *
	 * It is deliberately NOT in init(): init() runs on every page of the
	 * instance, and this extension must stay inert outside its own settings.
	 * It cannot live in getConfigureView() either — that method is final in
	 * Minz_Extension and overriding it is a fatal error.
	 */
	public function handleConfigureAction(): void
	{
		Minz_View::appendScript($this->getFileUrl('config.js', 'js', true));

		try {
			$this->store()->assertSchema();
			$this->store()->checkpoint('enabled');
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: store check failed: ' . $e->getMessage());
		}

		$this->handleConfigurePost();
	}

	/**
	 * FreshRSS calls this from disableAction — before it writes
	 * extensions_enabled — and a non-true return BLOCKS the disable.
	 *
	 * This is the only code path that exists on toggle-off, so the whole
	 * "put it back" guarantee hangs off it:
	 *
	 *   1. every feed attribute this extension wrote is restored from its
	 *      snapshot (nothing to do in light mode, where nothing was written);
	 *   2. the state store keeps the user's own settings (mode, hidden set) so
	 *      re-enabling comes back exactly as it was;
	 *   3. if any restore could not be completed, return a string and REFUSE the
	 *      disable — FreshRSS then leaves the extension enabled and the user
	 *      still has the settings page to press Restore on. Leaving the
	 *      extension enabled is the safe failure: it can always be fixed from
	 *      the UI, whereas a half-restored instance with the extension gone is
	 *      only fixable from a shell.
	 *
	 * @return string|true
	 */
	public function uninstall()
	{
		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			return 'Hidden Categories: could not open its state store, so nothing was restored. '
				. 'The extension was left enabled. ' . $e->getMessage();
		}
		if (!$this->store()->available()) {
			return 'Hidden Categories: state store is unusable (' . $this->store()->error() . '), '
				. 'so nothing was restored. The extension was left enabled.';
		}

		$result = $this->store()->restoreAllForDisable();
		if (!$result['ok']) {
			return 'Hidden Categories: ' . $result['message'] . ' Nothing was changed and the '
				. 'extension was left enabled so it can be fixed from its settings page.';
		}
		$this->store()->checkpoint('disabled');
		return true;
	}

	/** Lazily built store, rooted in this user's own extension data directory. */
	public function store(): HiddenCategoriesStore
	{
		if ($this->store === null) {
			require_once __DIR__ . '/store.php';
			$this->store = new HiddenCategoriesStore($this->getExtensionUserPath());
		}
		return $this->store;
	}

	/** @return array<string,string> */
	public function modeLabels(): array
	{
		return self::MODE_LABELS;
	}

	/**
	 * Every category with its feed count, for the config table.
	 *
	 * @return list<array{id:int,name:string,feeds:int}>
	 */
	public function categories(): array
	{
		$out = [];
		try {
			$counts = [];
			$st = FreshRSS_Factory::createFeedDao()->selectAll();
			foreach ($st as $feed) {
				$cid = (int)$feed['category'];
				$counts[$cid] = ($counts[$cid] ?? 0) + 1;
			}
			foreach (FreshRSS_Factory::createCategoryDao()->selectAll() as $row) {
				$id = (int)$row['id'];
				$out[] = [
					'id' => $id,
					'name' => (string)$row['name'],
					'feeds' => (int)($counts[$id] ?? 0),
				];
			}
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: could not list categories: ' . $e->getMessage());
		}
		return $out;
	}

	/** POST handling only. Everything that must happen on ANY settings-page
	 *  render lives in handleConfigureAction() above. */
	private function handleConfigurePost(): void
	{
		if (!Minz_Request::isPost()) {
			return;
		}

		// paramStringNull distinguishes "field absent" from "field present but
		// empty". An absent action field is a malformed request, not an
		// instruction to change something — refuse it and change nothing.
		$act = Minz_Request::paramStringNull('act', true);
		if ($act === null || trim($act) === '') {
			Minz_Request::bad('Save failed: the form did not say what to change, so nothing was changed.', $this->selfUrl());
			return;
		}
		$act = trim($act);

		switch ($act) {
			case 'mode':
				$mode = Minz_Request::paramStringNull('mode', true);
				if ($mode === null || trim($mode) === '') {
					Minz_Request::bad('Save failed: no mode was sent, so nothing was changed.', $this->selfUrl());
					return;
				}
				$result = $this->store()->setMode(trim($mode));
				if ($result['ok']) {
					Minz_Request::good($result['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($result['message'], $this->selfUrl());
				}
				return;

			/**
			 * Put back everything this extension has changed. Same routine that
			 * runs on toggle-off, so the button and the toggle can never
			 * disagree. Safe to press repeatedly.
			 */
			case 'restore':
				$result = $this->store()->restoreAllForDisable();
				if ($result['ok']) {
					Minz_Request::good(
						'Restore finished: ' . $result['message'] . '. No feeds were changed by this extension.',
						$this->selfUrl()
					);
				} else {
					Minz_Request::bad(
						'Restore could not be completed: ' . $result['message'] . ' Nothing was changed.',
						$this->selfUrl()
					);
				}
				return;

			default:
				Minz_Request::bad('Unknown action "' . $act . '": nothing was changed.', $this->selfUrl());
				return;
		}
	}

	/**
	 * Redirect target for feedback messages.
	 *
	 * The extension name MUST sit under 'params': Minz_Request::forward() only
	 * carries 'params', so a top-level 'e' is dropped and the redirect lands on
	 * a nameless configure URL (404).
	 *
	 * @return array<string,mixed>
	 */
	private function selfUrl(): array
	{
		return ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
	}
}
