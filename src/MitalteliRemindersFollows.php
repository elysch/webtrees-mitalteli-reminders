<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

use Fisharebest\Webtrees\DB;

require_once __DIR__ . '/MitalteliRemindersSchema.php';

/**
 * Data layer for individually-followed birthdays.
 *
 * A subscriber (identified by their own tree XREF in `gedid`) can "follow" any
 * number of other individuals (`target`). Followed birthdays appear in the
 * subscriber's daily digest REGARDLESS of the max_distance relationship filter,
 * so you can be reminded of a distant relative's birthday you actually care
 * about (e.g. a 4th cousin) without lowering your distance cap for everyone.
 *
 * The table is the module's own; it is created on first use (see
 * MitalteliRemindersSchema) and accessed through webtrees' database layer.
 */
class MitalteliRemindersFollows
{
    private const TABLE = MitalteliRemindersSchema::FOLLOWS;

    public function __construct()
    {
        MitalteliRemindersSchema::ensure();
    }

    /**
     * The XREFs a subscriber follows.
     *
     * @return array<int,string>
     */
    public function targets(string $gedid): array
    {
        return DB::table(self::TABLE)
            ->where('gedid', '=', $gedid)
            ->orderBy('created')
            ->orderBy('id')
            ->pluck('target')
            ->map(static fn (mixed $target): string => (string) $target)
            ->values()
            ->all();
    }

    /**
     * The followed XREFs with their own "days ahead" (null = use the subscriber's
     * general setting), oldest follow first.
     *
     * @return array<string,int|null>
     */
    public function daysByTarget(string $gedid): array
    {
        $map = [];

        foreach (DB::table(self::TABLE)
            ->where('gedid', '=', $gedid)
            ->orderBy('created')
            ->orderBy('id')
            ->get(['target', 'days_ahead']) as $row) {
            $map[(string) $row->target] = $row->days_ahead === null ? null : max(0, min(7, (int) $row->days_ahead));
        }

        return $map;
    }

    /** Set (0-7) or clear (null) the days ahead of one followed person. */
    public function setDays(string $gedid, string $target, ?int $days): void
    {
        DB::table(self::TABLE)
            ->where('gedid', '=', $gedid)
            ->where('target', '=', $target)
            ->update(['days_ahead' => $days === null ? null : max(0, min(7, $days))]);
    }

    /** Follow a birthday (no-op if already followed; UNIQUE(gedid,target)). */
    public function follow(string $gedid, string $target): void
    {
        DB::table(self::TABLE)->updateOrInsert(['gedid' => $gedid, 'target' => $target], []);
    }

    public function unfollow(string $gedid, string $target): void
    {
        DB::table(self::TABLE)
            ->where('gedid', '=', $gedid)
            ->where('target', '=', $target)
            ->delete();
    }
}
