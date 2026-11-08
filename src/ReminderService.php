<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;

/**
 * Builds a subscriber's birthday-reminder digest (HTML), or '' if there is
 * nothing to send. Rebuild of the legacy MeranReminders::getOutputHtml on the
 * webtrees 2.x APIs (CalendarService + RelationshipService).
 */
class ReminderService
{
    private CalendarService $calendar;
    private ReminderRelationships $relationships;
    private string $view_namespace;

    public function __construct(
        CalendarService $calendar,
        RelationshipService $relationship_service,
        string $view_namespace
    ) {
        $this->calendar       = $calendar;
        $this->relationships  = new ReminderRelationships($relationship_service);
        $this->view_namespace = $view_namespace;
    }

    /**
     * @return string Rendered HTML digest, or '' if no relevant events.
     */
    public function digestHtml(Tree $tree, string $xref, int $days_ahead, int $max_distance, bool $include_deceased = false): string
    {
        $subscriber = Registry::individualFactory()->make($xref, $tree);

        if ($subscriber === null) {
            return '';
        }

        // Hard cap: reminders never look more than a week ahead.
        $days_ahead = max(0, min(7, $days_ahead));

        // 0 == "unlimited" in the legacy settings.
        $effective_max = $max_distance === 0 ? 100 : $max_distance;
        $distances     = $this->relationships->distancesFrom($subscriber, $effective_max);

        // Individually-followed birthdays: these appear REGARDLESS of distance.
        // Connection failure must never break a digest, so guard it.
        $followed = [];
        try {
            $followed = array_flip((new ReminderFollows())->targets($xref));
        } catch (\Throwable $e) {
            $followed = [];
        }

        $now      = Registry::timestampFactory()->now();
        $sections = [];

        for ($d = 0; $d <= $days_ahead; $d++) {
            $jd    = $now->addDays($d)->julianDay();
            // Fetch ALL birthdays (only_living = false). The living-only rule is
            // applied per-event below: distance-based entries stay living-only,
            // but explicitly followed people show even if deceased.
            $facts = $this->calendar->getEventsList($jd, $jd, 'BIRT', false, 'anniv', $tree);

            $events = [];

            foreach ($facts as $fact) {
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

                // Deceased people are normally suppressed. They appear when the
                // subscriber has opted to include the deceased in general, OR
                // when this particular person is explicitly followed.
                $is_dead = $record->isDead();

                if ($is_dead && !$include_deceased && !$is_followed) {
                    continue;
                }

                $events[] = [
                    'record'       => $record,
                    'fact'         => $fact,
                    // Followed-but-distant people sort after the in-range relatives
                    // for the same day (PHP_INT_MAX), but still appear.
                    'distance'     => $within_distance ? $distances[$target] : PHP_INT_MAX,
                    'followed'     => $is_followed,
                    'deceased'     => $is_dead,
                    'relationship' => $this->relationships->smartLabel($subscriber, $record),
                ];
            }

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
            'tree'             => $tree,
            'sections'         => $sections,
            'days_ahead'       => $days_ahead,
            'max_distance'     => $max_distance,
            'include_deceased' => $include_deceased,
            'user_emails'      => self::accountEmails($tree),
        ]);
    }

    /**
     * Upcoming birthdays for the settings page: the SAME selection the daily
     * digest uses (distance filter + individually-followed people + the
     * deceased rule), but looking ahead a calendar month instead of the email's
     * <=7-day window. Returns a flat, day-ordered list of display rows so the
     * settings view can group them by date. This is a read-only preview — it
     * builds nothing and sends nothing.
     *
     * @return array<int,array{xref:string,name:string,relationship:string,distance:int,followed:bool,deceased:bool,days:int,date_label:string,age:int|null,url:string}>
     */
    public function upcomingBirthdays(Tree $tree, string $xref, int $max_distance, bool $include_deceased, int $horizon_days = 31): array
    {
        $subscriber = Registry::individualFactory()->make($xref, $tree);

        if ($subscriber === null) {
            return [];
        }

        $horizon_days = max(1, min(366, $horizon_days));

        // 0 == "unlimited" — same convention as the digest.
        $effective_max = $max_distance === 0 ? 100 : $max_distance;
        $distances     = $this->relationships->distancesFrom($subscriber, $effective_max);

        $followed = [];
        try {
            $followed = array_flip((new ReminderFollows())->targets($xref));
        } catch (\Throwable $e) {
            $followed = [];
        }

        $now  = Registry::timestampFactory()->now();
        $rows = [];

        // Per-day walk (mirrors digestHtml) so each event's day offset — and thus
        // its "in N days" countdown — is exact, and the data source is identical
        // to the email (CalendarService anniversaries).
        for ($d = 0; $d <= $horizon_days; $d++) {
            $when  = $now->addDays($d);
            $jd    = $when->julianDay();
            $facts = $this->calendar->getEventsList($jd, $jd, 'BIRT', false, 'anniv', $tree);

            foreach ($facts as $fact) {
                $record = $fact->record();

                if (!$record instanceof Individual) {
                    continue;
                }

                $target = $record->xref();

                if ($target === $xref) {
                    continue;
                }

                $within_distance = array_key_exists($target, $distances);
                $is_followed     = isset($followed[$target]);

                if (!$within_distance && !$is_followed) {
                    continue;
                }

                $is_dead = $record->isDead();

                if ($is_dead && !$include_deceased && !$is_followed) {
                    continue;
                }

                // Age they will turn on that birthday (would-be age for the dead).
                $age   = null;
                $birth = $record->getBirthDate();

                if ($birth->isOK()) {
                    $birth_year = (int) $birth->minimumDate()->year;

                    if ($birth_year > 0) {
                        $maybe = (int) $when->isoFormat('YYYY') - $birth_year;
                        $age   = $maybe >= 0 ? $maybe : null;
                    }
                }

                $rows[] = [
                    'xref'         => $target,
                    'name'         => self::displayName($record),
                    'relationship' => $this->relationships->smartLabel($subscriber, $record),
                    'distance'     => $within_distance ? $distances[$target] : PHP_INT_MAX,
                    'followed'     => $is_followed,
                    'deceased'     => $is_dead,
                    'days'         => $d,
                    'date_label'   => $when->isoFormat('D MMM'),
                    'age'          => $age,
                    'url'          => $record->url(),
                ];
            }
        }

        // Day order, then closest relative first within a day.
        usort($rows, static fn (array $a, array $b): int => [$a['days'], $a['distance']] <=> [$b['days'], $b['distance']]);

        return $rows;
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
