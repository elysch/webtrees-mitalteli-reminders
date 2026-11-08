<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Data layer for individually-followed birthdays.
 *
 * A subscriber (identified by their own tree XREF in `gedid`) can "follow" any
 * number of other individuals (`target`). Followed birthdays appear in the
 * subscriber's daily digest REGARDLESS of the max_distance relationship filter,
 * so you can be reminded of a distant relative's birthday you actually care
 * about (e.g. a 4th cousin) without lowering your distance cap for everyone.
 *
 * The table is the module's own (not a webtrees core table), so we use a raw
 * mysqli connection (reading webtrees' own data/config.ini.php) with prepared
 * statements throughout. It self-creates on first use.
 */
class ReminderFollows
{
    private const TABLE = 'mitalteli_reminders_follows';

    private mysqli $db;
    private string $prefix;

    public function __construct()
    {
        $config_file = __DIR__ . '/../../data/config.ini.php';

        if (!is_file($config_file)) {
            throw new RuntimeException('webtrees config.ini.php not found');
        }

        $config = parse_ini_file($config_file);

        if ($config === false) {
            throw new RuntimeException('Could not parse config.ini.php');
        }

        $this->prefix = (string) ($config['tblpfx'] ?? '');

        $this->db = new mysqli(
            (string) ($config['dbhost'] ?? 'localhost'),
            (string) ($config['dbuser'] ?? ''),
            (string) ($config['dbpass'] ?? ''),
            (string) ($config['dbname'] ?? ''),
            (int) ($config['dbport'] ?? 3306)
        );

        if ($this->db->connect_errno !== 0) {
            throw new RuntimeException('DB connect error: ' . $this->db->connect_error);
        }

        $this->db->set_charset('utf8mb4');
        $this->ensureTable();
    }

    /**
     * Create the follow table on first use. Idempotent (IF NOT EXISTS), wrapped
     * in try/catch because PHP 8's mysqli throws on error and a benign
     * "already exists"/race must never break the page.
     */
    private function ensureTable(): void
    {
        try {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS `' . $this->prefix . self::TABLE . '` ('
                . '`id` INT(11) NOT NULL AUTO_INCREMENT,'
                . '`gedid` VARCHAR(20) NOT NULL,'
                . '`target` VARCHAR(20) NOT NULL,'
                . '`created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_follow` (`gedid`,`target`),'
                . 'KEY `idx_gedid` (`gedid`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            // Table almost certainly already exists; nothing to do.
        }
    }

    /**
     * The XREFs a subscriber follows.
     *
     * @return array<int,string>
     */
    public function targets(string $gedid): array
    {
        $stmt = $this->db->prepare(
            'SELECT target FROM `' . $this->prefix . self::TABLE . '` WHERE gedid = ? ORDER BY created ASC'
        );
        $stmt->bind_param('s', $gedid);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map(static fn (array $r): string => (string) $r['target'], $rows);
    }

    /**
     * Follow a birthday (no-op if already followed; UNIQUE(gedid,target)).
     */
    public function follow(string $gedid, string $target): void
    {
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO `' . $this->prefix . self::TABLE . '` (gedid, target) VALUES (?, ?)'
        );
        $stmt->bind_param('ss', $gedid, $target);
        $stmt->execute();
        $stmt->close();
    }

    public function unfollow(string $gedid, string $target): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM `' . $this->prefix . self::TABLE . '` WHERE gedid = ? AND target = ?'
        );
        $stmt->bind_param('ss', $gedid, $target);
        $stmt->execute();
        $stmt->close();
    }

    public function __destruct()
    {
        $this->db->close();
    }
}
