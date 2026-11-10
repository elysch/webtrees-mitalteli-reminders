<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use Fisharebest\Webtrees\DB;

require_once __DIR__ . '/ReminderSchema.php';

/**
 * Data layer for the event-reminder subscriptions.
 *
 * The table (mitalteli_reminders_subscriptions) is the module's own; it is
 * created on first use (see ReminderSchema) and accessed through webtrees'
 * database layer, so it works with whatever database/prefix webtrees uses.
 *
 * Each row is keyed by the subscriber's own tree XREF (gedid). The webtrees
 * account behind that XREF (for email + display name) is resolved through the
 * per-tree "gedcomid" user preference (see withAccounts()), so the module is
 * self-contained (no per-site database view required).
 */
class ReminderSubscriptions
{
    private const TABLE = ReminderSchema::SUBSCRIPTIONS;

    public function __construct()
    {
        ReminderSchema::ensure();
    }

    /**
     * Attach each subscription's webtrees account (user_id, name, email).
     *
     * This is deliberately NOT a SQL JOIN between the module's table and
     * user_gedcom_setting: those are two tables whose text columns can have
     * different collations (the module's table takes the database default, the
     * core tables may not), and MySQL then refuses the comparison with
     * "Illegal mix of collations". Looking each XREF up with a bound parameter
     * never compares two columns, so it works whatever the collations are.
     *
     * Same result as the former inner join: a subscription whose XREF is not
     * linked to any account is left out; the match on XREF is case-insensitive.
     *
     * @param array<int,array<string,mixed>> $subscriptions Rows of the module's table
     *
     * @return array<int,array<string,mixed>> Ordered by user_id, then XREF
     */
    private function withAccounts(array $subscriptions): array
    {
        if ($subscriptions === []) {
            return [];
        }

        $xrefs = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['gedid'],
            $subscriptions
        )));

        // XREF (lower-cased) => user ids that have it as their "gedcomid" preference.
        $links = [];

        foreach (array_chunk($xrefs, 500) as $chunk) {
            $rows = DB::table('user_gedcom_setting')
                ->where('setting_name', '=', 'gedcomid')
                ->whereIn('setting_value', $chunk)
                ->get(['user_id', 'setting_value']);

            foreach ($rows as $row) {
                $links[strtolower((string) $row->setting_value)][] = (int) $row->user_id;
            }
        }

        if ($links === []) {
            return [];
        }

        $users = DB::table('user')
            ->whereIn('user_id', array_values(array_unique(array_merge(...array_values($links)))))
            ->get(['user_id', 'user_name', 'real_name', 'email'])
            ->keyBy('user_id');

        $result = [];

        foreach ($subscriptions as $subscription) {
            foreach ($links[strtolower((string) $subscription['gedid'])] ?? [] as $user_id) {
                $user = $users->get($user_id);

                if ($user === null) {
                    continue;
                }

                $result[] = [
                    'user_id'          => $user->user_id,
                    'user_name'        => $user->user_name,
                    'namekomplett'     => $user->real_name,
                    'email'            => $user->email,
                    'gedid'            => $subscription['gedid'],
                    'max_distance'     => $subscription['max_distance'],
                    'days_ahead'       => $subscription['days_ahead'],
                    'include_deceased' => $subscription['include_deceased'],
                    'include_marriages' => $subscription['include_marriages'] ?? 0,
                ];
            }
        }

        usort($result, static fn (array $a, array $b): int => [(int) $a['user_id'], (string) $a['gedid']] <=> [(int) $b['user_id'], (string) $b['gedid']]);

        return $result;
    }

    /**
     * All subscribers, joined to their webtrees user (email, name, user_id).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->withAccounts(
            DB::table(self::TABLE)
                ->get(['gedid', 'max_distance', 'days_ahead', 'include_deceased', 'include_marriages'])
                ->map(static fn (object $row): array => (array) $row)
                ->all()
        );
    }

    /**
     * One subscriber's record, or null if not subscribed (or no linked account).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $xref): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('gedid', '=', $xref)
            ->first(['gedid', 'max_distance', 'days_ahead', 'include_deceased', 'include_marriages']);

        return $row === null ? null : ($this->withAccounts([(array) $row])[0] ?? null);
    }

    /** Add a subscription row if one does not already exist (gedid is UNIQUE). */
    public function subscribe(string $xref): void
    {
        DB::table(self::TABLE)->updateOrInsert(['gedid' => $xref], []);
    }

    public function unsubscribe(string $xref): void
    {
        DB::table(self::TABLE)->where('gedid', '=', $xref)->delete();
    }

    public function updatePrefs(string $xref, int $days_ahead, int $max_distance, bool $include_deceased = false, bool $include_marriages = false): void
    {
        DB::table(self::TABLE)
            ->where('gedid', '=', $xref)
            ->update([
                'days_ahead'       => $days_ahead,
                'max_distance'     => $max_distance,
                'include_deceased' => $include_deceased ? 1 : 0,
                'include_marriages' => $include_marriages ? 1 : 0,
            ]);
    }
}
