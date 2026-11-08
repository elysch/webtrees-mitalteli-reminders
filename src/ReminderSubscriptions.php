<?php

declare(strict_types=1);

namespace Mitalteli\Webtrees\Util\Reminders;

use mysqli;
use RuntimeException;
use Throwable;

/**
 * Data layer for the birthday-reminder subscriptions.
 *
 * The subscription table (mitalteli_reminders_subscriptions) is the module's own table,
 * not a webtrees core table, so we use a raw mysqli connection (reading
 * webtrees' own data/config.ini.php) rather than the prefixed query builder. It
 * self-creates on first use; all queries use prepared statements.
 *
 * Each row is keyed by the subscriber's own tree XREF (gedid). The webtrees
 * account behind that XREF (for email + display name) is resolved by joining to
 * the core user tables via the per-tree "gedcomid" user preference, so the
 * module is self-contained (no per-site database view required).
 */
class ReminderSubscriptions
{
    private const TABLE = 'mitalteli_reminders_subscriptions';

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
     * Create the subscription table on first use. Idempotent (IF NOT EXISTS),
     * wrapped in try/catch because PHP 8's mysqli throws on error and a benign
     * "already exists"/race must never break the page. max_distance 0 == no
     * relationship-distance limit (the digest convention).
     */
    private function ensureTable(): void
    {
        try {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS `' . $this->prefix . self::TABLE . '` ('
                . '`id` INT(11) NOT NULL AUTO_INCREMENT,'
                . '`gedid` VARCHAR(20) NOT NULL,'
                . '`days_ahead` INT(11) NOT NULL DEFAULT 1,'
                . '`max_distance` INT(11) NOT NULL DEFAULT 0,'
                . '`include_deceased` TINYINT(1) NOT NULL DEFAULT 0,'
                . '`created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uq_gedid` (`gedid`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (Throwable $e) {
            // Table almost certainly already exists; nothing to do.
        }
    }

    /**
     * SELECT that joins a subscription to its webtrees account (email + names).
     * The join to the core user tables is done here so the module needs no
     * per-site database view.
     */
    private function selectWithUser(string $tail): string
    {
        $user      = '`' . $this->prefix . 'user`';
        $user_pref = '`' . $this->prefix . 'user_gedcom_setting`';

        return 'SELECT u.user_id, u.user_name, u.real_name AS namekomplett, u.email,'
            . ' r.gedid, r.max_distance, r.days_ahead, r.include_deceased'
            . ' FROM `' . $this->prefix . self::TABLE . '` r'
            . ' JOIN ' . $user_pref . ' gs ON gs.setting_name = \'gedcomid\' AND gs.setting_value = r.gedid'
            . ' JOIN ' . $user . ' u ON u.user_id = gs.user_id'
            . ' ' . $tail;
    }

    /**
     * All subscribers, joined to their webtrees user (email, name, user_id).
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $result = $this->db->query($this->selectWithUser('ORDER BY u.user_id ASC'));

        if ($result === false) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    /**
     * One subscriber's record, or null if not subscribed.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $xref): ?array
    {
        $stmt = $this->db->prepare($this->selectWithUser('WHERE r.gedid = ? LIMIT 1'));
        $stmt->bind_param('s', $xref);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Add a subscription row if one does not already exist (gedid is UNIQUE).
     */
    public function subscribe(string $xref): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO `' . $this->prefix . self::TABLE . '` (gedid) VALUES (?)');
        $stmt->bind_param('s', $xref);
        $stmt->execute();
        $stmt->close();
    }

    public function unsubscribe(string $xref): void
    {
        $stmt = $this->db->prepare('DELETE FROM `' . $this->prefix . self::TABLE . '` WHERE gedid = ?');
        $stmt->bind_param('s', $xref);
        $stmt->execute();
        $stmt->close();
    }

    public function updatePrefs(string $xref, int $days_ahead, int $max_distance, bool $include_deceased = false): void
    {
        $deceased = $include_deceased ? 1 : 0;
        $stmt     = $this->db->prepare(
            'UPDATE `' . $this->prefix . self::TABLE . '` SET days_ahead = ?, max_distance = ?, include_deceased = ? WHERE gedid = ?'
        );
        $stmt->bind_param('iiis', $days_ahead, $max_distance, $deceased, $xref);
        $stmt->execute();
        $stmt->close();
    }

    public function __destruct()
    {
        $this->db->close();
    }
}
