<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Module\ModuleSidebarTrait;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleSidebarInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\RequestHandlers\LoginPage;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\RelationshipsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

require_once __DIR__ . '/ReminderSchema.php';
require_once __DIR__ . '/ReminderSubscriptions.php';
require_once __DIR__ . '/ReminderFollows.php';
require_once __DIR__ . '/ReminderRelationships.php';
require_once __DIR__ . '/ReminderService.php';
require_once __DIR__ . '/ReminderSender.php';
require_once __DIR__ . '/ReminderSettings.php';
require_once __DIR__ . '/ReminderCron.php';

use Mitalteli\Webtrees\Util\EventReminders\ReminderSettings;
use Mitalteli\Webtrees\Util\EventReminders\ReminderRelationships;

/**
 * An event-reminder module (birthdays and wedding anniversaries) for webtrees.
 *
 * SAFETY: boot() registers the view namespace ONLY. Every fragile operation
 * (DB, relationship BFS, mail) runs inside a per-request handler, so a failure
 * can only break this module's own page — never the whole site.
 *
 * ROUTING: the module registers no routes of its own. Every page is a module
 * action served by webtrees' built-in module route
 * (/module/{module}/{action}[/{tree}] -> get<Action>Action / post<Action>Action),
 * which is the part of the routing API that is stable across webtrees 2.2 and 2.3
 * (the route-registration API itself changes in 2.3).
 */
class RemindersModule extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, ModuleSidebarInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleSidebarTrait;
    use ViewResponseTrait;
    use ModuleConfigTrait;
    use ReminderSettings;
    use ReminderCron;

    /** Sorts the module next to the author's other modules in the module list. */
    private const TITLE_PREFIX = "\u{2696} ";

    // Tree used by the (guest-accessible, tree-less) cron route. Empty means
    // "use the first/only tree" — see resolveTree().
    private const DEFAULT_TREE = '';

    /**
     * Register the view namespace. Nothing else may live here.
     */
    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function title(): string
    {
        return self::TITLE_PREFIX . I18N::translate('Event reminders');
    }

    public function description(): string
    {
        return I18N::translate('Event reminders (birthdays and wedding anniversaries) for relatives in the family tree, based on the work made by jbh4x82 at https://github.com/jbh4x82/wt_reminders.');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function customModuleAuthorName(): string
    {
        return 'elysch';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/elysch/webtrees-mitalteli-reminders/issues';
    }

    /**
     * URL of one of this module's actions, through webtrees' built-in module route.
     *
     * @param array<string,string> $parameters
     */
    public function moduleUrl(string $action, array $parameters = []): string
    {
        return route('module', ['module' => $this->name(), 'action' => $action] + $parameters);
    }

    /** The member's reminder settings page (also the POST target of the follow toggle). */
    public function editUrl(Tree $tree): string
    {
        return $this->moduleUrl('Edit', ['tree' => $tree->name()]);
    }

    /** The follow-a-person autocomplete (JSON). */
    public function searchUrl(Tree $tree): string
    {
        return $this->moduleUrl('Search', ['tree' => $tree->name()]);
    }

    /**
     * Translations live in resources/lang/<language>.php (one file per language,
     * e.g. ca, de, es, fr, it, nl). The cron calls I18N::init() with each
     * subscriber's language before rendering, and webtrees merges these into the
     * active translator (see I18N::init -> customTranslations). A regional tag
     * such as "fr-CA" falls back to its primary language; languages without a
     * file fall back to the English source strings (core content — dates,
     * relationship labels — still localises for any webtrees language).
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        $language = str_replace('_', '-', $language);

        foreach ([$language, strtolower(substr($language, 0, 2))] as $code) {
            // The tag ends up in a file name: accept only a plain language tag.
            if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $code) !== 1) {
                continue;
            }

            $file = $this->resourcesFolder() . 'lang/' . $code . '.php';

            if (is_file($file)) {
                $translations = require $file;

                if (is_array($translations)) {
                    return $translations;
                }
            }
        }

        return [];
    }

    /**
     * Set the active locale (translator + Carbon date locale) for the digest
     * about to be rendered. I18N::init() loads the webtrees catalogue + this
     * module's customTranslations(); Carbon::setLocale() localises the weekday/
     * month names in the day headers (I18N::init does NOT touch Carbon).
     */
    private function applyLocale(string $tag): void
    {
        $tag = $tag !== '' ? $tag : 'en-US';

        try {
            I18N::init($tag);
        } catch (Throwable $e) {
            I18N::init('en-US');
            $tag = 'en-US';
        }

        $primary = strtolower(substr($tag, 0, 2)) ?: 'en';

        try {
            \Carbon\Carbon::setLocale($primary);
        } catch (Throwable $e) {
            // Carbon will keep its previous locale; dates fall back gracefully.
        }
    }

    public function defaultMenuOrder(): int
    {
        return 9;
    }

    /**
     * Only show the menu to signed-in users — reminders are personal.
     */
    public function getMenu(Tree $tree): ?Menu
    {
        if (!Auth::check()) {
            return null;
        }

        return new Menu(
            $this->title(),
            $this->editUrl($tree),
            'menu-reminders',
            ['rel' => 'nofollow']
        );
    }

    // ---------------------------------------------------------------------
    // Individual-page sidebar (ModuleSidebarInterface)
    //
    // Surfaces the two things you otherwise had to go to the settings page for,
    // right on the person's own page: (1) how this person is related to YOU,
    // and (2) a one-click "remind me of this birthday" follow toggle. Sidebars
    // render inline (no AJAX round-trip), and webtrees wraps every module's
    // boot()/render in try/catch — but we ALSO guard getSidebarContent so a
    // relationship-BFS or DB hiccup can never blank out the individual page.
    // ---------------------------------------------------------------------

    /** Place below the Family navigator (order 2) but above nothing in particular. */
    public function defaultSidebarOrder(): int
    {
        return 99;
    }

    public function sidebarTitle(Individual $individual): string
    {
        return $this->title();
    }

    /**
     * Only signed-in members whose account is linked to a person in the tree
     * see the sidebar — both the relationship label and the follow action are
     * meaningless ("related to whom?") without a linked self.
     */
    public function hasSidebarContent(Individual $individual): bool
    {
        if (!Auth::check()) {
            return false;
        }

        if ($this->sidebarModeFor(Auth::user()) === 'hidden') {
            return false;
        }

        return $individual->tree()->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF) !== '';
    }

    public function getSidebarContent(Individual $individual): string
    {
        try {
            $tree = $individual->tree();
            $user = Auth::user();
            $self = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

            if ($self === '') {
                return '';
            }

            $is_self = $self === $individual->xref();

            // Relationship of the viewed person TO the logged-in user. Resolved
            // via common ancestors first (the meaningful lineage, e.g. "maternal
            // great ×24 grandfather"), falling back to any-relationship; closest
            // path only. See ReminderRelationships::resolveForCard.
            $relationship     = '';
            $relationship_url = '';

            if (!$is_self) {
                $subject = Registry::individualFactory()->make($self, $tree);

                if ($subject !== null) {
                    $resolved = (new ReminderRelationships(
                        Registry::container()->get(RelationshipService::class)
                    ))->resolveForCard($subject, $individual);

                    if ($resolved['label'] !== '' && $resolved['label'] !== 'You') {
                        $relationship = $resolved['label'];

                        // Link to the full "Relationships" chart, reproducing how
                        // the label was found: via-ancestors if that's where it
                        // came from, else any-relationship — always recursion 0
                        // (closest); higher recursion on this tree is far too slow.
                        try {
                            $relationship_url = route(RelationshipsChartModule::class, [
                                'tree'      => $tree->name(),
                                'xref'      => $self,
                                'xref2'     => $individual->xref(),
                                'ancestors' => $resolved['ancestors'] === true ? 1 : 0,
                                'recursion' => 0,
                            ]);
                        } catch (Throwable $e) {
                            $relationship_url = '';
                        }
                    }
                }
            }

            $is_following = false;

            try {
                $is_following = in_array($individual->xref(), (new ReminderFollows())->targets($self), true);
            } catch (Throwable $e) {
                $is_following = false;
            }

            // Short biography, if this person is in the "Famous family members"
            // gallery — shown in the same card so a celebrity's profile carries
            // their story (German or English, matching the active language).
            $bio  = '';
            $wiki = '';
            $fam  = $this->famousEntry($individual->xref());

            if ($fam !== null) {
                $german = str_starts_with(strtolower(I18N::languageTag()), 'de');
                $bio    = $german ? (string) ($fam['bio_de'] ?: $fam['bio_en']) : (string) ($fam['bio_en'] ?: $fam['bio_de']);
                $wiki   = $german ? (string) ($fam['wiki_de'] ?: $fam['wiki_en']) : (string) ($fam['wiki_en'] ?: $fam['wiki_de']);
            }

            return $this->renderReminderSidebar([
                'individual'       => $individual,
                'is_self'          => $is_self,
                'is_following'     => $is_following,
                'relationship'     => $relationship,
                'relationship_url' => $relationship_url,
                'birthday'         => $is_self ? '' : $this->birthdayLabel($individual),
                'bio'              => $bio,
                'wiki'             => $wiki,
                'action_url'       => $this->editUrl($tree),
                'manage_url'       => $this->editUrl($tree),
                'csrf'             => csrf_token(),
            ], $this->sidebarModeFor($user));
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Look up the short biography for a person in the "Famous family members"
     * gallery (wt_famous_people). Returns null when they are not a gallery
     * member, or when that other module's table does not exist. The table has a
     * literal, un-prefixed name (it is owned by the famous-people module), so it
     * is queried by that exact name — through webtrees' own database connection.
     *
     * @return array{bio_en:string,bio_de:string,wiki_en:string,wiki_de:string}|null
     */
    private function famousEntry(string $xref): ?array
    {
        try {
            $row = DB::connection()->selectOne(
                'SELECT bio_en, bio_de, wiki_en, wiki_de FROM wt_famous_people WHERE xref = ? LIMIT 1',
                [$xref]
            );

            return $row === null ? null : (array) $row;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The member's settings page (module action "Edit", GET).
     */
    public function getEditAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        if (!Auth::check()) {
            // Redirect to LoginPage preserving the original request URI so the
            // user lands here after signing in. (Previously used the string
            // 'login' alias + hardcoded route, which worked but loses any
            // additional query params on the original URL. Same fix pattern as
            // ForumModule + webtrees core AuthLoggedIn middleware.)
            return redirect(route(LoginPage::class, [
                'tree' => $tree->name(),
                'url'  => (string) $request->getUri(),
            ]));
        }

        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($xref === '') {
            return $this->viewResponse($this->name() . '::edit', [
                'title'        => $this->title(),
                'tree'         => $tree,
                'module'       => $this,
                'linked'       => false,
                'subscription' => null,
            ]);
        }

        $subscriptions = new ReminderSubscriptions();
        $subscription  = $subscriptions->find($xref);

        return $this->viewResponse($this->name() . '::edit', [
            'title'        => $this->title(),
            'tree'         => $tree,
            'module'       => $this,
            'linked'       => true,
            'xref'         => $xref,
            'subscription' => $subscription,
            'following'    => $this->followingList($tree, $xref),
            'search_url'   => $this->searchUrl($tree),
            'upcoming'     => $this->upcomingList($tree, $xref, $subscription),
            'horizon_days' => self::PREVIEW_HORIZON_DAYS,
            'sidebar_choice' => $this->userMayChooseSidebarMode(),
            'sidebar_user'   => $user->getPreference('reminders_sidebar_mode', ''),
            'sidebar_site'   => $this->sidebarMode(),
            'sidebar_modes'  => $this->sidebarModes(),
        ]);
    }

    /** How far the settings-page "upcoming events" preview looks ahead. */
    private const PREVIEW_HORIZON_DAYS = 31;

    /**
     * Build the settings-page "upcoming events" preview: the same people the
     * daily digest would mention (distance filter + followed + deceased rule),
     * but over the next month. Uses the subscriber's saved settings; falls back
     * to sensible defaults when they have not subscribed yet. Never throws — a
     * preview failure must not break the settings page.
     *
     * @param array<string,mixed>|null $subscription
     *
     * @return array<int,array<string,mixed>>
     */
    private function upcomingList(Tree $tree, string $xref, ?array $subscription): array
    {
        try {
            $calendar     = Registry::container()->get(CalendarService::class);
            $relationship = Registry::container()->get(RelationshipService::class);
            $service      = new ReminderService($calendar, $relationship, $this->name(), fn (Tree $t): string => $this->editUrl($t));

            $max_distance      = (int) ($subscription['max_distance'] ?? 0);
            $include_deceased  = (bool) ($subscription['include_deceased'] ?? false);
            $include_marriages = (bool) ($subscription['include_marriages'] ?? false);

            return $service->upcomingEvents($tree, $xref, $max_distance, $include_deceased, $include_marriages, self::PREVIEW_HORIZON_DAYS);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve a subscriber's followed XREFs into display rows for the settings
     * page (and AJAX follow/unfollow responses). Unresolvable XREFs (deleted
     * individuals) are silently dropped.
     *
     * @return array<int,array{xref:string,name:string,lifespan:string,url:string}>
     */
    private function followingList(Tree $tree, string $xref): array
    {
        $follows = new ReminderFollows();
        $rows    = [];

        foreach ($follows->daysByTarget($xref) as $target => $follow_days) {
            $target     = (string) $target;
            $individual = Registry::individualFactory()->make($target, $tree);

            if ($individual === null || !$individual->canShow()) {
                continue;
            }

            $rows[] = [
                'xref'       => $target,
                'name'       => strip_tags($individual->fullName()),
                'lifespan'   => strip_tags($individual->lifespan()),
                'birthday'   => $this->birthdayLabel($individual),
                'days_until' => $this->daysUntilBirthday($individual),
                'url'        => $individual->url(),
                // Own "days ahead" of this followed person; null = the general setting.
                'days_ahead' => $follow_days,
            ];
        }

        // Soonest birthday first (by days until the next one). People with an
        // unknown/imprecise birth date (days_until = null) sort to the end;
        // ties (same day) and unknowns break alphabetically.
        usort($rows, static function (array $a, array $b): int {
            $da = $a['days_until'];
            $db = $b['days_until'];

            if ($da === null && $db === null) {
                return I18N::comparator()($a['name'], $b['name']);
            }
            if ($da === null) {
                return 1;
            }
            if ($db === null) {
                return -1;
            }

            return ($da <=> $db) ?: I18N::comparator()($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Whole days until an individual's NEXT birthday (0 = today), or null if the
     * birth day/month is unknown/imprecise. Used to order the "people you follow"
     * list soonest-first; mirrors the date math in birthdayLabel().
     */
    private function daysUntilBirthday(Individual $individual): ?int
    {
        $birth = $individual->getBirthDate();

        if (!$birth->isOK()) {
            return null;
        }

        $greg  = $birth->minimumDate();
        $day   = (int) $greg->day;
        $month = (int) $greg->month;

        if ($day < 1 || $month < 1 || $month > 12) {
            return null;
        }

        $tz    = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $year  = (int) $today->format('Y');

        $next = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $tz);

        if ($next === false) {
            return null;
        }

        if ($next < $today) {
            $next = \DateTimeImmutable::createFromFormat('!Y-n-j', ($year + 1) . '-' . $month . '-' . $day, $tz);

            if ($next === false) {
                return null;
            }
        }

        return (int) $today->diff($next)->days;
    }

    /**
     * "🎂 28 Jun (in 11 days)" — the next occurrence of an individual's birthday
     * and how far off it is. Returns '' if the birth day/month is unknown.
     * Day count is calendar-based (UTC, matching webtrees' internal clock); the
     * "D MMM" label is localised via the same Timestamp machinery the digest uses.
     */
    private function birthdayLabel(Individual $individual): string
    {
        $birth = $individual->getBirthDate();

        if (!$birth->isOK()) {
            return '';
        }

        $greg  = $birth->minimumDate();
        $day   = (int) $greg->day;
        $month = (int) $greg->month;

        if ($day < 1 || $month < 1 || $month > 12) {
            return ''; // imprecise date (e.g. "1983" or "JUN 1983") — no countdown
        }

        $tz    = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $year  = (int) $today->format('Y');

        $next = \DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $tz);

        if ($next === false) {
            return '';
        }

        if ($next < $today) {
            $next = \DateTimeImmutable::createFromFormat('!Y-n-j', ($year + 1) . '-' . $month . '-' . $day, $tz);

            if ($next === false) {
                return '';
            }
        }

        $days = (int) $today->diff($next)->days;
        $when = Registry::timestampFactory()->now()->addDays($days)->isoFormat('D MMM');

        if ($days === 0) {
            $rel = I18N::translate('today');
        } elseif ($days === 1) {
            $rel = I18N::translate('tomorrow');
        } else {
            // Single-string translate (not I18N::plural): a module's flat
            // customTranslations() map resolves for I18N::translate but NOT for
            // I18N::plural, so plurals would fall back to English. $days >= 2 here.
            $rel = I18N::translate('in %s days', I18N::number($days));
        }

        // Age they'll turn on that birthday (would-be age for the deceased).
        // Plural pattern keeps it grammatical per language ("43 years old" /
        // "43 ans" / "43 anni" / "43 años" — FR/IT/ES drop the word "old").
        $birth_year = (int) $greg->year;
        $age        = (int) $next->format('Y') - $birth_year;

        if ($birth_year > 0 && $age >= 0) {
            // Single-string translate, not I18N::plural (see note above).
            $age_phrase = $age === 1
                ? I18N::translate('%s year old', I18N::number($age))
                : I18N::translate('%s years old', I18N::number($age));

            return '🎂 ' . $when . ' ' . $birth_year . ' (' . $age_phrase . ' ' . $rel . ')';
        }

        return '🎂 ' . $when . ' (' . $rel . ')';
    }

    /**
     * JSON autocomplete for the "follow a person" search box (module action "Search", GET). Auth-gated: the
     * tree is private, so anonymous callers get an empty list. Marks people the
     * subscriber already follows so the UI can show "Following" instead of "Add".
     */
    public function getSearchAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return response(['results' => []], StatusCodeInterface::STATUS_FORBIDDEN);
        }

        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();
        $self = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        $q = trim(Validator::queryParams($request)->string('q', ''));

        if (mb_strlen($q) < 2) {
            return response(['results' => []]);
        }

        // Word-wise search across ALL of a person's name rows (birth name AND
        // married names). Each query word must appear in at least one of the
        // individual's name rows — so "johanna wolff" finds Johanna Freiin von
        // Weichs zur Wenne (married name Wolff), where the given name and the
        // married surname live in different `name` rows. We GROUP BY the xref and
        // require, per word, SUM(n_full LIKE ?) > 0 across that person's rows.
        // (n_full uses an accent-insensitive collation, so ASCII tokens match
        // accented names.)
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);

        $query = DB::table('name')
            ->where('n_file', '=', $tree->id())
            ->groupBy('n_id')
            ->select('n_id')
            ->orderByRaw('MIN(n_sort)')
            ->limit(15);

        foreach ($words as $word) {
            $like = '%' . addcslashes($word, '\\%_') . '%';
            $query->havingRaw('SUM(n_full LIKE ?) > 0', [$like]);
        }

        $individuals = [];

        foreach ($query->pluck('n_id')->all() as $xref_match) {
            $individual = Registry::individualFactory()->make((string) $xref_match, $tree);

            if ($individual !== null) {
                $individuals[] = $individual;
            }
        }

        $already = [];
        try {
            $already = array_flip((new ReminderFollows())->targets($self));
        } catch (Throwable $e) {
            $already = [];
        }

        $results = [];

        foreach ($individuals as $individual) {
            $x = $individual->xref();

            if ($x === $self || !$individual->canShow()) {
                continue; // can't follow your own birthday; respect privacy
            }

            $results[] = [
                'xref'      => $x,
                'name'      => strip_tags($individual->fullName()),
                'lifespan'  => strip_tags($individual->lifespan()),
                'birthday'  => $this->birthdayLabel($individual),
                'following' => isset($already[$x]),
            ];
        }

        return response(['results' => $results]);
    }

    /**
     * Save subscription changes for the current user (module action "Edit", POST).
     */
    public function postEditAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        $back = redirect($this->editUrl($tree));

        if (!Auth::check()) {
            return $back;
        }

        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($xref === '') {
            return $back;
        }

        $params = (array) $request->getParsedBody();
        $action = $params['action'] ?? '';

        // --- Follow / unfollow an individual birthday (AJAX from the settings
        // page). Independent of the subscribe form, so handle it first and never
        // touch the days_ahead/max_distance prefs. ----------------------------
        if ($action === 'follow' || $action === 'unfollow' || $action === 'days') {
            return $this->handleFollow($request, $tree, $xref, $action, $params);
        }

        // --- Personal display mode of the individual-page panel. Only the
        // viewer's own preference, only a whitelisted value, only if allowed.
        if ($action === 'display') {
            $mode = is_string($params['sidebar_mode'] ?? null) ? $params['sidebar_mode'] : '';

            if ($this->userMayChooseSidebarMode() && ($mode === '' || in_array($mode, $this->sidebarModes(), true))) {
                $user->setPreference('reminders_sidebar_mode', $mode);
                FlashMessages::addMessage(I18N::translate('Your display preference has been saved.'), 'success');
            }

            return $back;
        }

        $days_ahead        = (int) ($params['days_ahead'] ?? 1);
        $max_distance      = (int) ($params['max_distance'] ?? 0);
        $include_deceased  = !empty($params['include_deceased']);
        $include_marriages = !empty($params['include_marriages']);

        // Clamp to sane ranges (0 = same day only, max one week ahead).
        $days_ahead   = max(0, min(7, $days_ahead));
        $max_distance = max(0, min(100, $max_distance));

        $subscriptions = new ReminderSubscriptions();

        if ($action === 'unsubscribe') {
            $subscriptions->unsubscribe($xref);
        } else {
            $subscriptions->subscribe($xref);
            $subscriptions->updatePrefs($xref, $days_ahead, $max_distance, $include_deceased, $include_marriages);
        }

        return $back;
    }

    /**
     * Add or remove a followed birthday. Called from postEditAction for the
     * follow/unfollow actions. The JS sends ajax=1 and expects JSON containing
     * the refreshed "following" list; a plain (no-JS) submit redirects back.
     *
     * @param array<string,mixed> $params
     */
    private function handleFollow(
        ServerRequestInterface $request,
        Tree $tree,
        string $xref,
        string $action,
        array $params
    ): ResponseInterface {
        $target = trim((string) ($params['target'] ?? ''));
        $ajax   = (string) ($params['ajax'] ?? '') === '1';

        // XREFs are alphanumeric (I123, X45, …). Reject anything else, and never
        // let someone follow their own birthday.
        if ($target !== '' && $target !== $xref && preg_match('/^[A-Za-z0-9]+$/', $target) === 1) {
            $follows = new ReminderFollows();

            if ($action === 'follow') {
                // Only follow real, visible individuals.
                $individual = Registry::individualFactory()->make($target, $tree);

                if ($individual !== null && $individual->canShow()) {
                    $follows->follow($xref, $target);

                    // A followed birthday only ever reaches someone who has a
                    // subscription row (the cron iterates subscribers). Someone
                    // following straight from an individual page may never have
                    // visited the settings form — so auto-subscribe them with
                    // sane defaults, but NEVER clobber an existing subscriber's
                    // saved preferences (distance / days-ahead / deceased).
                    try {
                        $subs = new ReminderSubscriptions();

                        if ($subs->find($xref) === null) {
                            $subs->subscribe($xref);
                            $subs->updatePrefs($xref, 1, 0, false, false);
                        }
                    } catch (Throwable $e) {
                        // Best-effort: the follow is recorded regardless.
                    }
                }
            } elseif ($action === 'days') {
                // '' = back to the general setting; otherwise 0 (same day) to 7.
                $raw  = $params['days'] ?? '';
                $days = is_string($raw) && preg_match('/^[0-7]$/', $raw) === 1 ? (int) $raw : null;

                $follows->setDays($xref, $target, $days);
            } else {
                $follows->unfollow($xref, $target);
            }
        }

        if ($ajax) {
            return response([
                'ok'        => true,
                'following' => $this->followingList($tree, $xref),
            ]);
        }

        return redirect($this->editUrl($tree));
    }

    /**
     * Resolve a tree by name WITHOUT the visibility filter in TreeService::all()
     * — the cron runs as a guest and the family tree is private, so all() would
     * hide it. We read the gedcom row directly and build the Tree.
     */
    private function resolveTree(string $name): ?Tree
    {
        // Called after elevation (run-as admin), so TreeService::all() returns
        // the private tree too. Using the service avoids depending on the Tree
        // constructor signature (which changed in webtrees 2.2.6).
        $trees = Registry::container()->get(\Fisharebest\Webtrees\Services\TreeService::class)->all();

        // No tree configured ⇒ use the first/only tree (the common single-tree case).
        if ($name === '') {
            return $trees->first();
        }

        return $trees->first(static fn (Tree $t): bool => $t->name() === $name);
    }
}
