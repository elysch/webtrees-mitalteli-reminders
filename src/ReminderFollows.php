<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\DB;

require_once __DIR__ . '/ReminderSchema.php';

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
 * ReminderSchema) and accessed through webtrees' database layer.
 */
class ReminderFollows
{
    private const TABLE = ReminderSchema::FOLLOWS;

    public function __construct()
    {
        ReminderSchema::ensure();
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
