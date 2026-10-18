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

		// The one global touch point: JsVars fires exactly once per page from
		// the page head. It carries category ids only (never feed or article
		// data), so the payload cannot grow with subscription count.
		$this->registerHook(Minz_HookType::JsVars, [$this, 'injectJsVars']);

		// Both files are optional by design: the CSS does the hiding from the
		// data attribute, the JS adds convenience (sidebar section, menus). If
		// either fails to load, hiding still works. The CSS is registered here in
		// init() because a stylesheet has to be in <head>, and it is the only
		// global thing this extension needs.
		Minz_View::appendStyle($this->getFileUrl('hidden.css'));
		Minz_View::appendScript($this->getFileUrl('hidden.js', 'js', true));
	}

	/**
	 * The page payload. Consumed by hidden.css (through the data attribute that
	 * hidden.js sets before paint) and by hidden.js.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function injectJsVars($vars = [])
	{
		if (!is_array($vars)) {
			$vars = [];
		}
		$cats = [];
		$counts = [];
		try {
			$cats = $this->store()->hiddenIds();
			foreach ($this->categories() as $c) {
				$counts[$c['id']] = ['name' => $c['name'], 'feeds' => $c['feeds']];
			}
		} catch (Throwable $e) {
			Minz_Log::warning('HiddenCategories: could not build page payload: ' . $e->getMessage());
		}
		$vars['hiddenCategories'] = [
			'cats' => $cats,
			'mode' => $this->store()->mode(),
			'view' => $this->store()->view(),
			'next' => $this->store()->nextView(),
			// names+counts for the hidden ones only — a hidden category with no
			// row in this map is named by its id, never by a guess
			'meta' => array_filter($counts, static fn ($id) => in_array($id, $cats, true), ARRAY_FILTER_USE_KEY),
			// _url() returns an HTML-escaped string ("&amp;"), which is correct
			// in markup and WRONG in a JS string: fetch() would send a parameter
			// literally named "amp;a" and every click would silently do nothing.
			// Decode once, here, so the JS receives a real URL.
			'api' => html_entity_decode((string)_url('extension', 'configure', 'e', urlencode($this->getName())), ENT_QUOTES),
		];
		return $vars;
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

	/**
	 * One category's display name, read from FreshRSS itself. Returns null when
	 * the category no longer exists, which is how a stale page revives a deleted
	 * category without silently hiding a different one that reused the id.
	 */
	public function categoryName(int $catId): ?string
	{
		foreach ($this->categories() as $c) {
			if ($c['id'] === $catId) {
				return $c['name'];
			}
		}
		return null;
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
			case 'hide':
			case 'unhide':
				$catId = Minz_Request::paramInt('cat');
				if ($catId <= 0) {
					Minz_Request::bad('No category was named, so nothing changed.', $this->selfUrl());
					return;
				}
				// Name comes from the database, never from the request: a posted
				// name could label one category while hiding another.
				$name = $this->categoryName($catId);
				if ($name === null) {
					Minz_Request::bad('Category ' . $catId . ' no longer exists — nothing changed.', $this->selfUrl());
					return;
				}
				$result = $act === 'hide'
					? $this->store()->hideCategory($catId, $name)
					: $this->store()->unhideCategory($catId, $name);
				if ($result['ok']) {
					Minz_Request::good($result['message'], $this->selfUrl());
				} else {
					Minz_Request::bad($result['message'], $this->selfUrl());
				}
				return;

			case 'only':
				// Superseded by act=view; kept so an old bookmark or a stale
				// page still does the right thing instead of erroring.
				$want = Minz_Request::paramStringNull('on', true);
				$this->store()->setView($want === '1' ? 'only' : 'hidden');
				Minz_Request::good($want === '1' ? 'Showing only hidden categories.' : 'Showing everything again.', $this->selfUrl());
				return;

			case 'view':
				// One button, three states. Accepts an explicit target so the
				// button can send the state it displays; refuses anything else
				// rather than guessing.
				$to = Minz_Request::paramStringNull('to', true);
				if ($to === null || trim($to) === '') {
					$to = $this->store()->nextView();
				}
				$to = trim($to);
				if (!in_array($to, HiddenCategoriesStore::VIEWS, true)) {
					Minz_Request::bad('Unknown view "' . $to . '": nothing was changed.', $this->selfUrl());
					return;
				}
				if (!$this->store()->setView($to)) {
					Minz_Request::bad('Could not save the view: nothing was changed.', $this->selfUrl());
					return;
				}
				Minz_Request::good(
					$to === 'only' ? 'Showing only hidden categories.'
						: ($to === 'revealed' ? 'Hidden categories shown again (still excluded from the stream).'
							: 'Hidden categories are hidden again.'),
					$this->selfUrl()
				);
				return;

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
