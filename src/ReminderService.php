<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Closure;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;
use Throwable;

/**
 * Builds a subscriber's event-reminder digest (HTML), or '' if there is nothing
 * to send. Events are birthdays and, optionally, wedding anniversaries.
 * Rebuild of the legacy MeranReminders::getOutputHtml on the webtrees 2.x APIs
 * (CalendarService + RelationshipService).
 */
class ReminderService
{
    public const TYPE_BIRTHDAY = 'BIRT';
    public const TYPE_MARRIAGE = 'MARR';

    private CalendarService $calendar;
    private ReminderRelationships $relationships;
    private string $view_namespace;

    /** @var Closure(Tree):string Builds the URL of the member's reminder settings page. */
    private Closure $manage_url;

    public function __construct(
        CalendarService $calendar,
        RelationshipService $relationship_service,
        string $view_namespace,
        Closure $manage_url
    ) {
        $this->calendar       = $calendar;
        $this->relationships  = new ReminderRelationships($relationship_service);
        $this->view_namespace = $view_namespace;
        $this->manage_url     = $manage_url;
    }

    /**
     * @return string Rendered HTML digest, or '' if no relevant events.
     */
    public function digestHtml(
        Tree $tree,
        string $xref,
        int $days_ahead,
        int $max_distance,
        bool $include_deceased = false,
        bool $include_marriages = false
    ): string {
        $subscriber = Registry::individualFactory()->make($xref, $tree);

        if ($subscriber === null) {
            return '';
        }

        // Hard cap: reminders never look more than a week ahead. 0 means "same day only".
        $days_ahead = max(0, min(7, $days_ahead));

        // 0 == "unlimited" in the legacy settings.
        $effective_max = $max_distance === 0 ? 100 : $max_distance;
        $distances     = $this->relationships->distancesFrom($subscriber, $effective_max);
        $followed      = $this->followedTargets($xref);

        $now      = Registry::timestampFactory()->now();
        $sections = [];

        for ($d = 0; $d <= $days_ahead; $d++) {
            $jd     = $now->addDays($d)->julianDay();
            $events = $this->eventsOn($tree, $subscriber, $distances, $followed, $include_deceased, $include_marriages, $jd);

            if ($events === []) {
                continue;
            }

            usort($events, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

            $sections[] = [
                'title'    => $this->sectionTitle($d),
                'is_today' => $d === 0,
                'events'   => $events,
            ];
        }

        if ($sections === []) {
            return '';
        }

        return view($this->view_namespace . '::email-digest', [
            'tree'              => $tree,
            'sections'          => $sections,
            'days_ahead'        => $days_ahead,
            'max_distance'      => $max_distance,
            'include_deceased'  => $include_deceased,
            'include_marriages' => $include_marriages,
            'user_emails'       => self::accountEmails($tree),
            'manage_url'        => ($this->manage_url)($tree),
        ]);
    }

    /**
     * Upcoming events for the settings page: the SAME selection the daily digest
     * uses (distance filter + followed people + the deceased rule + the wedding
     * option), but looking ahead a calendar month instead of the email's <=7-day
     * window. Returns a flat, day-ordered list of display rows so the settings
     * view can group them by date. Read-only: it builds nothing and sends nothing.
     *
     * @return array<int,array<string,mixed>>
     */
    public function upcomingEvents(
        Tree $tree,
        string $xref,
        int $max_distance,
        bool $include_deceased,
        bool $include_marriages,
        int $horizon_days = 31
    ): array {
        $subscriber = Registry::individualFactory()->make($xref, $tree);

        if ($subscriber === null) {
            return [];
        }

        $horizon_days = max(1, min(366, $horizon_days));

        // 0 == "unlimited" — same convention as the digest.
        $effective_max = $max_distance === 0 ? 100 : $max_distance;
        $distances     = $this->relationships->distancesFrom($subscriber, $effective_max);
        $followed      = $this->followedTargets($xref);

        $now  = Registry::timestampFactory()->now();
        $rows = [];

        // Per-day walk (mirrors digestHtml) so each event's day offset — and thus
        // its "in N days" countdown — is exact, and the data source is identical
        // to the email (CalendarService anniversaries).
        for ($d = 0; $d <= $horizon_days; $d++) {
            $when   = $now->addDays($d);
            $events = $this->eventsOn($tree, $subscriber, $distances, $followed, $include_deceased, $include_marriages, $when->julianDay());

            foreach ($events as $event) {
                $years = (int) $event['fact']->anniv;

                $rows[] = [
                    'type'          => $event['type'],
                    'xref'          => $event['record']->xref(),
                    'name'          => $event['name'],
                    'relationship'  => $event['relationship'],
                    'distance'      => $event['distance'],
                    'followed'      => $event['followed'],
                    'deceased'      => $event['deceased'],
                    'days'          => $d,
                    'date_label'    => $when->isoFormat('D MMM'),
                    // The date of the original event (birth or marriage) the entry comes from.
                    'original_date' => $event['original_date'],
                    'age'           => $years > 0 ? $years : null,
                    'url'           => $event['record']->url(),
                ];
            }
        }

        // Day order, then closest relative first within a day.
        usort($rows, static fn (array $a, array $b): int => [$a['days'], $a['distance']] <=> [$b['days'], $b['distance']]);

        return $rows;
    }

    /**
     * XREFs of the individually-followed people (birthdays only). A database
     * failure must never break a digest, so it is swallowed.
     *
     * @return array<string,int>
     */
    private function followedTargets(string $xref): array
    {
        try {
            return array_flip((new ReminderFollows())->targets($xref));
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every event that falls on one calendar day and passes the subscriber's filters.
     *
     * Birthdays: shown when the person is within the distance limit or followed;
     * the deceased only when opted in (or followed). Wedding anniversaries (only
     * when $include_marriages): shown when at least one spouse is within the
     * distance limit; suppressed when every known spouse is deceased, unless the
     * subscriber opted in to deceased people. Following a person affects birthdays only.
     * The subscriber's own birthday/wedding is never listed.
     *
     * @param array<string,int> $distances
     * @param array<string,int> $followed
     *
     * @return array<int,array<string,mixed>>
     */
    private function eventsOn(
        Tree $tree,
        Individual $subscriber,
        array $distances,
        array $followed,
        bool $include_deceased,
        bool $include_marriages,
        int $jd
    ): array {
        $xref   = $subscriber->xref();
        $events = [];

        // Fetch ALL birthdays (only_living = false): the living-only rule is
        // applied per event below, so explicitly followed people still show.
        foreach ($this->calendar->getEventsList($jd, $jd, 'BIRT', false, 'anniv', $tree) as $fact) {
            $record = $fact->record();

            if (!$record instanceof Individual) {
                continue;
            }

            $target = $record->xref();

            if ($target === $xref) {
                continue; // don't remind someone of their own birthday
            }

            $within_distance = array_key_exists($target, $distances);
            $is_followed     = isset($followed[$target]);

            if (!$within_distance && !$is_followed) {
                continue; // beyond max_distance and not individually followed
            }

            $is_dead = $record->isDead();

            if ($is_dead && !$include_deceased && !$is_followed) {
                continue;
            }

            $events[] = [
                'type'          => self::TYPE_BIRTHDAY,
                'record'        => $record,
                'fact'          => $fact,
                'name'          => self::displayName($record),
                // Followed-but-distant people sort after the in-range relatives
                // for the same day (PHP_INT_MAX), but still appear.
                'distance'      => $within_distance ? $distances[$target] : PHP_INT_MAX,
                'followed'      => $is_followed,
                'deceased'      => $is_dead,
                'relationship'  => $this->relationships->smartLabel($subscriber, $record),
                'original_date' => $this->originalDate($fact, $tree),
            ];
        }

        if (!$include_marriages) {
            return $events;
        }

        foreach ($this->calendar->getEventsList($jd, $jd, 'MARR', false, 'anniv', $tree) as $fact) {
            $record = $fact->record();

            if (!$record instanceof Family) {
                continue;
            }

            $spouses = array_values(array_filter([$record->husband(), $record->wife()]));

            if ($spouses === []) {
                continue;
            }

            $in_range  = [];
            $names     = [];
            $labels    = [];
            $all_dead  = true;
            $is_self   = false;

            foreach ($spouses as $spouse) {
                if ($spouse->xref() === $xref) {
                    $is_self = true;
                }

                if (array_key_exists($spouse->xref(), $distances)) {
                    $in_range[] = $distances[$spouse->xref()];
                }

                $names[] = self::displayName($spouse);
                $label   = $this->relationships->smartLabel($subscriber, $spouse);

                if ($label !== '') {
                    $labels[$label] = $label;
                }

                if (!$spouse->isDead()) {
                    $all_dead = false;
                }
            }

            if ($is_self || $in_range === []) {
                continue;
            }

            if ($all_dead && !$include_deceased) {
                continue;
            }

            $events[] = [
                'type'          => self::TYPE_MARRIAGE,
                'record'        => $record,
                'fact'          => $fact,
                'name'          => implode(' & ', $names),
                'distance'      => min($in_range),
                'followed'      => false,
                'deceased'      => $all_dead,
                'relationship'  => implode(' & ', $labels),
                'original_date' => $this->originalDate($fact, $tree),
            ];
        }

        return $events;
    }

    /** The date of the original event as plain text (no markup). */
    private function originalDate(Fact $fact, Tree $tree): string
    {
        return trim(strip_tags($fact->date()->display($tree, null, true)));
    }

    /** @var array<string,string>|null xref => linked account email; cached per request. */
    private static ?array $account_emails = null;

    /**
     * Map of individual XREF => linked user-account email, for every webtrees
     * account that is linked to an individual in this tree. Powers the digest's
     * "Send birthday email" link (a birthday person who has an account gets a
     * mailto link in the digest). Cached per request.
     *
     * @return array<string,string>
     */
    private static function accountEmails(Tree $tree): array
    {
        if (self::$account_emails === null) {
            self::$account_emails = DB::table('user')
                ->join('user_gedcom_setting', 'user_gedcom_setting.user_id', '=', 'user.user_id')
                ->where('user_gedcom_setting.gedcom_id', '=', $tree->id())
                ->where('user_gedcom_setting.setting_name', '=', 'gedcomid')
                ->where('user.email', '<>', '')
                ->pluck('user.email', 'user_gedcom_setting.setting_value')
                ->all();
        }

        return self::$account_emails;
    }

    /**
     * Localised day header. "Today"/"Tomorrow" come from the (custom) translation
     * catalogue; dates >= 2 days out use the webtrees Timestamp's Carbon-backed
     * isoFormat so the weekday + month follow the active locale (the cron sets
     * both I18N::init() AND Carbon::setLocale() per subscriber). 'dddd, LL' =>
     * e.g. "Samstag, 13. Juni 2026" / "Saturday, June 13, 2026".
     */
    private function sectionTitle(int $days): string
    {
        if ($days === 0) {
            return I18N::translate('Today');
        }

        if ($days === 1) {
            return I18N::translate('Tomorrow');
        }

        return Registry::timestampFactory()->now()->addDays($days)->isoFormat('dddd, LL');
    }

    /**
     * Display name: the primary fullName, with the married surname added in
     * parentheses when a `_MARNM` (married name) NAME row exists for the person.
     */
    public static function displayName(Individual $person): string
    {
        $full = strip_tags($person->fullName());
        foreach ($person->getAllNames() as $name) {
            if (($name['type'] ?? '') !== '_MARNM') {
                continue;
            }
            // Prefer the bare surname (n_surn), fall back to the full rendering.
            // Some _MARNM rows are surname-only; others carry "Given Maiden",
            // matching how the legacy getMarriedName() coalesced.
            $marnm = (string) ($name['surn'] ?? $name['surname'] ?? $name['full'] ?? '');
            $marnm = trim(strip_tags($marnm));
            if ($marnm !== '') {
                return $full . ' (' . $marnm . ')';
            }
        }
        return $full;
    }
}
