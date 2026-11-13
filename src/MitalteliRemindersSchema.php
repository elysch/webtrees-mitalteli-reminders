<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\EventReminders;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Log;
use Illuminate\Database\Schema\Blueprint;
use Throwable;

/**
 * The module's own tables, created on first use through webtrees' database layer
 * (same connection, credentials and table prefix as webtrees itself — nothing is
 * read from data/config.ini.php and no second connection is opened).
 *
 * COLLATION. On MySQL/MariaDB the tables are created with the same character set
 * and collation as webtrees' own tables, and tables that already exist with a
 * different one are converted to it. The reference is what the core tables
 * ACTUALLY have (individuals, falling back to user_gedcom_setting), not the
 * database's default: webtrees' installer creates the database with
 * utf8mb4_unicode_ci, but a database that already existed, or one restored from a
 * dump, can have a different default than its tables. Left to the default, the
 * module's tables would then differ from the core's, and any comparison between
 * their text columns fails with "Illegal mix of collations". Other databases
 * (PostgreSQL, SQLite, SQL Server) have no per-table collation to align.
 *
 * VERIFIED ONCE. After a successful check a marker is stored in site_setting, so
 * later requests cost a single primary-key lookup. recheck() clears it.
 *
 * WHY THE COMMIT/BEGIN AROUND THE DDL: webtrees wraps every request in a database
 * transaction (UseTransaction middleware). MySQL/MariaDB commit implicitly on
 * CREATE/ALTER TABLE, so webtrees' own commit at the end of the request would then
 * fail with "There is no active transaction". Closing the transaction before the
 * DDL and reopening it afterwards keeps webtrees' bookkeeping consistent.
 */
final class MitalteliRemindersSchema
{
    public const SUBSCRIPTIONS = 'mitalteli_reminders_subscriptions';
    public const FOLLOWS       = 'mitalteli_reminders_follows';

    /** Core tables whose collation we mirror, in order of preference. */
    private const REFERENCE_TABLES = ['individuals', 'user_gedcom_setting'];

    private const MARKER  = 'MITALTELI_EVENT_REMINDERS_SCHEMA';
    private const VERSION = '4';

    private static bool $ready = false;

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }

        $verified = DB::table('site_setting')
            ->where('setting_name', '=', self::MARKER)
            ->value('setting_value');

        if ((string) $verified !== self::VERSION) {
            self::verify();

            DB::table('site_setting')->updateOrInsert(['setting_name' => self::MARKER], ['setting_value' => self::VERSION]);
        }

        self::$ready = true;
    }

    /** Forget that the schema was verified, so the next request checks it again. */
    public static function recheck(): void
    {
        DB::table('site_setting')->where('setting_name', '=', self::MARKER)->delete();

        self::$ready = false;
    }

    private static function verify(): void
    {
        $schema   = DB::schema();
        $encoding = self::coreEncoding();

        $missing    = [];
        $misaligned = [];

        // Columns added after the first release (table already exists without them).
        $needs_marriages = $schema->hasTable(self::SUBSCRIPTIONS)
            && !$schema->hasColumn(self::SUBSCRIPTIONS, 'include_marriages');
        $needs_follow_days = $schema->hasTable(self::FOLLOWS)
            && !$schema->hasColumn(self::FOLLOWS, 'days_ahead');

        foreach ([self::SUBSCRIPTIONS, self::FOLLOWS] as $table) {
            if (!$schema->hasTable($table)) {
                $missing[] = $table;
            } elseif ($encoding !== null && self::tableCollation($table) !== $encoding['collation']) {
                $misaligned[] = $table;
            }
        }

        if ($missing === [] && $misaligned === [] && !$needs_marriages && !$needs_follow_days) {
            return;
        }

        $connection = DB::connection();
        $reopen     = $connection->transactionLevel() === 1;

        if ($reopen) {
            $connection->commit();
        }

        try {
            foreach ($missing as $table) {
                self::create($table, $encoding);
            }

            foreach ($misaligned as $table) {
                self::align($table, $encoding ?? ['charset' => '', 'collation' => '']);
            }

            if ($needs_marriages) {
                DB::schema()->table(self::SUBSCRIPTIONS, static function (Blueprint $table): void {
                    $table->boolean('include_marriages')->default(false);
                });
            }

            if ($needs_follow_days) {
                DB::schema()->table(self::FOLLOWS, static function (Blueprint $table): void {
                    $table->unsignedTinyInteger('days_ahead')->nullable();
                });
            }
        } finally {
            if ($reopen) {
                $connection->beginTransaction();
            }
        }
    }

    /**
     * Character set and collation of the core tables (MySQL/MariaDB only).
     *
     * @return array{charset:string,collation:string}|null
     */
    private static function coreEncoding(): ?array
    {
        if (!in_array(DB::driverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        foreach (self::REFERENCE_TABLES as $core) {
            $collation = self::tableCollation($core);

            // It comes from the database, but it ends up in DDL: accept only a plain identifier.
            if (preg_match('/^([a-z0-9]+)_[a-z0-9_]+$/', $collation, $match) === 1) {
                return ['charset' => $match[1], 'collation' => $collation];
            }
        }

        return null;
    }

    /** Default collation of a table (name without prefix), or '' if it cannot be read. */
    private static function tableCollation(string $table): string
    {
        try {
            $row = DB::connection()->selectOne(
                'SELECT TABLE_COLLATION AS collation_name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?)',
                [DB::prefix($table)]
            );

            return $row === null ? '' : strtolower((string) $row->collation_name);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param array{charset:string,collation:string}|null $encoding
     */
    private static function create(string $name, ?array $encoding): void
    {
        try {
            if ($name === self::SUBSCRIPTIONS) {
                // max_distance 0 == no relationship-distance limit (the digest convention).
                DB::schema()->create($name, static function (Blueprint $table) use ($name, $encoding): void {
                    if ($encoding !== null) {
                        $table->charset   = $encoding['charset'];
                        $table->collation = $encoding['collation'];
                    }

                    $table->integer('id', true);
                    $table->string('gedid', 20);
                    $table->integer('days_ahead')->default(1);
                    $table->integer('max_distance')->default(0);
                    $table->boolean('include_deceased')->default(false);
                    $table->boolean('include_marriages')->default(false);
                    $table->timestamp('created')->useCurrent();

                    $table->unique('gedid', DB::prefix($name . '_gedid_unique'));
                });
            } else {
                DB::schema()->create($name, static function (Blueprint $table) use ($name, $encoding): void {
                    if ($encoding !== null) {
                        $table->charset   = $encoding['charset'];
                        $table->collation = $encoding['collation'];
                    }

                    $table->integer('id', true);
                    $table->string('gedid', 20);
                    $table->string('target', 20);
                    // Days ahead for this followed person; NULL = the subscriber's general setting.
                    $table->unsignedTinyInteger('days_ahead')->nullable();
                    $table->timestamp('created')->useCurrent();

                    $table->unique(['gedid', 'target'], DB::prefix($name . '_follow_unique'));
                    $table->index('gedid', DB::prefix($name . '_gedid_index'));
                });
            }
        } catch (Throwable $e) {
            // A concurrent first request may have created it a moment ago.
            if (!DB::schema()->hasTable($name)) {
                throw $e;
            }
        }
    }

    /**
     * Convert an existing table to the core's character set/collation. Best effort:
     * nothing in the module compares these columns with the core's any more, so a
     * failure (e.g. missing privilege) is logged and the page carries on.
     *
     * @param array{charset:string,collation:string} $encoding
     */
    private static function align(string $name, array $encoding): void
    {
        try {
            DB::connection()->statement(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                str_replace('`', '``', DB::prefix($name)),
                $encoding['charset'],
                $encoding['collation']
            ));
        } catch (Throwable $e) {
            try {
                Log::addErrorLog('Reminders: could not convert ' . $name . ' to ' . $encoding['collation'] . ': ' . $e->getMessage());
            } catch (Throwable $ignored) {
                // Logging must never break the page either.
            }
        }
    }
}
