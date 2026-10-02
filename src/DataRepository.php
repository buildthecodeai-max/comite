<?php

declare(strict_types=1);

namespace CommitteeManager;

use PDO;

final class DataRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function loadPublicInfo(): array
    {
        $settings = $this->loadSettings();
        if ($settings === null) {
            throw new \RuntimeException('Settings row missing. Run sql/schema.sql and scripts/seed.php.');
        }

        return [
            'committeeName' => $settings['committee_name'],
            'committeeSubtitle' => $settings['committee_subtitle'],
            'adminUser' => $settings['admin_user'],
            // Intentionally omit members list for privacy on the login screen.
        ];
    }

    public function loadAll(): array
    {
        $settings = $this->loadSettings();
        if ($settings === null) {
            throw new \RuntimeException('Settings row missing. Run sql/schema.sql and scripts/seed.php.');
        }

        $members = $this->loadMembers();
        $trashedMembers = $this->loadTrashedMembers();
        $payments = $this->loadPayments();
        $winners = $this->loadWinners();

        $committeeRules = [];
        $auditLog = [];
        if ($this->supportsCommitteeRules() && !empty($settings['committee_rules'])) {
            $decoded = json_decode((string) $settings['committee_rules'], true);
            if (is_array($decoded)) {
                $committeeRules = is_array($decoded['rules'] ?? null) ? $decoded['rules'] : [];
                $auditLog = is_array($decoded['auditLog'] ?? null) ? $decoded['auditLog'] : [];
            }
        }

        foreach ($members as &$member) {
            $memberId = (int) $member['id'];
            $member['payments'] = $payments[$memberId] ?? [];
            // Never expose the real PIN. Empty string means "unchanged" on save.
            $member['pin'] = '';
            unset($member['pin_hash']);
        }
        unset($member);

        foreach ($trashedMembers as &$member) {
            $memberId = (int) $member['id'];
            $member['payments'] = $payments[$memberId] ?? [];
            $member['pin'] = '';
            unset($member['pin_hash']);
        }
        unset($member);

        return [
            'committeeName' => $settings['committee_name'],
            'committeeSubtitle' => $settings['committee_subtitle'],
            'adminUser' => $settings['admin_user'],
            'adminPass' => '',
            'adminEmail' => $settings['admin_email'] ?? '',
            'recoveryContact' => $settings['recovery_contact'],
            'recoveryNote' => $settings['recovery_note'],
            'amtPerShare' => (int) $settings['amt_per_share'],
            'totalMonths' => (int) $settings['total_months'],
            'prizePerShare' => (int) $settings['prize_per_share'],
            'startMonth' => $settings['start_month'],
            'currentMonth' => (int) $settings['current_month'],
            'committeeRules' => (object) $committeeRules,
            'auditLog' => $auditLog,
            'members' => $members,
            'trashedMembers' => $trashedMembers,
            'winners' => $winners,
            'nextId' => (int) $settings['next_id'],
            'updatedAt' => (int) $settings['updated_at'],
        ];
    }

    /** Member-scoped payload: only the logged-in member's own records. */
    public function loadForMember(int $memberId): array
    {
        $all = $this->loadAll();
        $self = null;
        foreach ($all['members'] as $member) {
            if ((int) $member['id'] === $memberId) {
                $self = $member;
                break;
            }
        }
        if ($self === null) {
            throw new \RuntimeException('Member not found.');
        }

        $all['members'] = [$self];
        $all['trashedMembers'] = [];
        $all['winners'] = array_values(array_filter(
            $all['winners'],
            static fn(array $w): bool => (int) ($w['memberId'] ?? 0) === $memberId
        ));
        $all['adminPass'] = '';
        $all['adminEmail'] = '';
        $all['auditLog'] = [];
        $all['committeeRules'] = (object) [];
        $all['viewMode'] = 'member';

        return $all;
    }

    public function findActiveMemberIdByName(string $name): ?int
    {
        $needle = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach ($this->loadMembers() as $member) {
            $candidate = strtolower(trim(preg_replace('/\s+/', ' ', (string) $member['name']) ?? ''));
            if ($candidate === $needle) {
                $matches[] = (int) $member['id'];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function saveAll(array $data): array
    {
        $this->validateMembers($data['members'] ?? [], $data['trashedMembers'] ?? []);

        $this->pdo->beginTransaction();

        try {
            $this->saveSettings($data);
            $this->saveMembers($data['members'] ?? []);
            $this->saveTrashedMembers($data['trashedMembers'] ?? []);
            $this->savePayments($data['members'] ?? [], $data['trashedMembers'] ?? []);
            $this->saveWinners($data['winners'] ?? []);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->loadAll();
    }

    public function permanentDeleteMembers(array $memberIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $memberIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return $this->loadAll();
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM members WHERE id IN ($placeholders) AND deleted_at IS NOT NULL");
        $stmt->execute($ids);

        return $this->loadAll();
    }

    public function saveMemberSelf(int $memberId, array $incoming, array $current): array
    {
        $memberIndex = null;
        foreach ($current['members'] as $idx => $member) {
            if ((int) $member['id'] === $memberId) {
                $memberIndex = $idx;
                break;
            }
        }

        if ($memberIndex === null) {
            throw new \RuntimeException('Member not found.');
        }

        $incomingMember = null;
        foreach ($incoming['members'] ?? [] as $member) {
            if ((int) ($member['id'] ?? 0) === $memberId) {
                $incomingMember = $member;
                break;
            }
        }

        if ($incomingMember === null) {
            throw new \RuntimeException('Member payload missing.');
        }

        if (isset($incomingMember['prefMonth'])) {
            $current['members'][$memberIndex]['prefMonth'] = (int) $incomingMember['prefMonth'];
        }

        if (!empty($incomingMember['pin'])) {
            $current['members'][$memberIndex]['pin'] = (string) $incomingMember['pin'];
        }

        $current['updatedAt'] = (int) ($incoming['updatedAt'] ?? (microtime(true) * 1000));

        $this->saveAll($current);

        return $this->loadForMember($memberId);
    }

    public function payloadMatchesMemberSelfEdit(array $incoming, array $current, int $memberId): bool
    {
        if (!empty($incoming['resetAll'])) {
            return false;
        }

        $incomingCopy = $incoming;
        $currentCopy = $current;
        unset(
            $incomingCopy['updatedAt'], $currentCopy['updatedAt'],
            $incomingCopy['adminPass'], $currentCopy['adminPass'],
            $incomingCopy['committeeRules'], $currentCopy['committeeRules'],
            $incomingCopy['auditLog'], $currentCopy['auditLog']
        );

        $incomingMember = null;
        $currentMember = null;
        foreach ($incomingCopy['members'] ?? [] as $member) {
            if ((int) ($member['id'] ?? 0) === $memberId) {
                $incomingMember = $member;
                break;
            }
        }
        foreach ($currentCopy['members'] ?? [] as $member) {
            if ((int) ($member['id'] ?? 0) === $memberId) {
                $currentMember = $member;
                break;
            }
        }

        if ($incomingMember === null || $currentMember === null) {
            return false;
        }

        $allowedPin = (string) ($incomingMember['pin'] ?? (string) $memberId);
        $allowedPref = (int) ($incomingMember['prefMonth'] ?? 0);

        $sanitizedIncoming = $currentCopy;
        $sanitizedIncoming['members'] = array_map(static function (array $member) use ($memberId, $allowedPin, $allowedPref): array {
            if ((int) $member['id'] === $memberId) {
                $member['pin'] = $allowedPin;
                $member['prefMonth'] = $allowedPref;
            }
            return $member;
        }, $sanitizedIncoming['members']);

        return json_encode($sanitizedIncoming) === json_encode($currentCopy);
    }

    public function verifyAdminPassword(string $password): bool
    {
        $settings = $this->loadSettings();
        if ($settings === null) {
            return false;
        }

        return password_verify($password, $settings['admin_pass_hash']);
    }

    public function getAdminUsername(): ?string
    {
        $settings = $this->loadSettings();

        return $settings === null ? null : (string) $settings['admin_user'];
    }

    public function updateAdminPassword(string $newPassword): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE settings SET admin_pass_hash = :hash, updated_at = :updated_at WHERE id = 1'
        );
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => (int) (microtime(true) * 1000),
        ]);
    }

    public function updateAdminEmail(string $email): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE settings SET admin_email = :email, updated_at = :updated_at WHERE id = 1'
        );
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'updated_at' => (int) (microtime(true) * 1000),
        ]);
    }

    public function validateMemberNames(array $members): void
    {
        $seen = [];
        foreach ($members as $member) {
            $name = strtolower(trim((string) ($member['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $id = (int) ($member['id'] ?? 0);
            if (isset($seen[$name]) && $seen[$name] !== $id) {
                throw new \RuntimeException('Member name already exists.');
            }
            $seen[$name] = $id;
        }
    }

    public function validateMembers(array $members, array $trashedMembers = []): void
    {
        $this->validateMemberNames($members);
        $this->validateMemberNames($trashedMembers);

        $activeIds = [];
        $activeNames = [];
        foreach ($members as $member) {
            $id = (int) ($member['id'] ?? 0);
            if ($id <= 0) {
                throw new \RuntimeException('Please complete all required fields.');
            }
            if (trim((string) ($member['name'] ?? '')) === '') {
                throw new \RuntimeException('Please complete all required fields.');
            }
            if (isset($activeIds[$id])) {
                throw new \RuntimeException('Member ID already exists.');
            }
            $activeIds[$id] = true;

            $name = strtolower(trim((string) $member['name']));
            if (isset($activeNames[$name])) {
                throw new \RuntimeException('Member name already exists.');
            }
            $activeNames[$name] = true;
        }

        $trashedIds = [];
        foreach ($trashedMembers as $member) {
            $id = (int) ($member['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($trashedIds[$id])) {
                throw new \RuntimeException('Member ID already exists.');
            }
            $trashedIds[$id] = true;

            if (isset($activeIds[$id])) {
                throw new \RuntimeException('Member ID already exists.');
            }
        }
    }

    private function assertMemberFields(array $member): void
    {
        $id = (int) ($member['id'] ?? 0);
        $name = trim((string) ($member['name'] ?? ''));

        if ($id <= 0 || $name === '') {
            throw new \RuntimeException('Please complete all required fields.');
        }
    }

    private function supportsSoftDelete(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => 'members', 'column' => 'deleted_at']);
        $supported = (int) $stmt->fetchColumn() > 0;

        return $supported;
    }

    private function supportsCommitteeRules(): bool
    {
        static $supported = null;
        if ($supported !== null) {
            return $supported;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => 'settings', 'column' => 'committee_rules']);
        $supported = (int) $stmt->fetchColumn() > 0;

        return $supported;
    }

    public function verifyMemberPin(int $memberId, string $pin): bool
    {
        $stmt = $this->pdo->prepare(
            $this->supportsSoftDelete()
                ? 'SELECT pin_hash FROM members WHERE id = :id AND deleted_at IS NULL'
                : 'SELECT pin_hash FROM members WHERE id = :id'
        );
        $stmt->execute(['id' => $memberId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        return password_verify($pin, $row['pin_hash']);
    }

    public function resetToDefaults(): array
    {
        Seeder::run($this->pdo);
        return $this->loadAll();
    }

    private function loadSettings(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM settings WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function loadMembers(): array
    {
        $sql = $this->supportsSoftDelete()
            ? 'SELECT id, name, shares, phone, email, pin_hash, pref_month
               FROM members WHERE deleted_at IS NULL ORDER BY id'
            : 'SELECT id, name, shares, phone, email, pin_hash, pref_month FROM members ORDER BY id';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'shares' => (int) $row['shares'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'pin_hash' => $row['pin_hash'],
                'prefMonth' => (int) $row['pref_month'],
            ];
        }, $rows);
    }

    private function loadTrashedMembers(): array
    {
        if (!$this->supportsSoftDelete()) {
            return [];
        }

        $stmt = $this->pdo->query(
            'SELECT id, name, shares, phone, email, pin_hash, pref_month, deleted_at, deleted_by
             FROM members WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC'
        );
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'shares' => (int) $row['shares'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'pin_hash' => $row['pin_hash'],
                'prefMonth' => (int) $row['pref_month'],
                'deletedAt' => (int) $row['deleted_at'],
                'deletedBy' => (string) ($row['deleted_by'] ?? ''),
            ];
        }, $rows);
    }

    private function loadPayments(): array
    {
        $stmt = $this->pdo->query('SELECT member_id, month_num FROM payments ORDER BY member_id, month_num');
        $rows = $stmt->fetchAll();
        $map = [];

        foreach ($rows as $row) {
            $memberId = (int) $row['member_id'];
            $map[$memberId]['M' . (int) $row['month_num']] = true;
        }

        return $map;
    }

    private function loadWinners(): array
    {
        $stmt = $this->pdo->query(
            'SELECT member_id, name, month_num, shares_won, amount FROM winners ORDER BY month_num, id'
        );
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'memberId' => (int) $row['member_id'],
                'name' => $row['name'],
                'month' => (int) $row['month_num'],
                'sharesWon' => (int) $row['shares_won'],
                'amount' => (int) $row['amount'],
            ];
        }, $rows);
    }

    private function saveSettings(array $data): void
    {
        $updatedAt = (int) ($data['updatedAt'] ?? (microtime(true) * 1000));
        $params = [
            'committee_name' => (string) ($data['committeeName'] ?? 'Hashmat Commite'),
            'committee_subtitle' => (string) ($data['committeeSubtitle'] ?? 'Committee Management System'),
            'admin_user' => (string) ($data['adminUser'] ?? 'admin'),
            'admin_email' => strtolower(trim((string) ($data['adminEmail'] ?? ''))),
            'recovery_contact' => (string) ($data['recoveryContact'] ?? ''),
            'recovery_note' => (string) ($data['recoveryNote'] ?? 'Contact the committee admin to reset your password or PIN.'),
            'amt_per_share' => (int) ($data['amtPerShare'] ?? 2000),
            'total_months' => (int) ($data['totalMonths'] ?? 25),
            'prize_per_share' => (int) ($data['prizePerShare'] ?? 50000),
            'start_month' => (string) ($data['startMonth'] ?? ''),
            'current_month' => (int) ($data['currentMonth'] ?? 1),
            'next_id' => max(1, (int) ($data['nextId'] ?? 1)),
            'updated_at' => $updatedAt,
        ];

        $hasRules = $this->supportsCommitteeRules();
        if ($hasRules) {
            $rules = $data['committeeRules'] ?? [];
            $auditLog = $data['auditLog'] ?? [];
            $params['committee_rules'] = json_encode([
                'rules' => is_array($rules) ? $rules : (array) $rules,
                'auditLog' => is_array($auditLog) ? array_slice($auditLog, -200) : [],
            ]);
        }
        $rulesAssign = $hasRules ? ', committee_rules = :committee_rules' : '';

        if (!empty($data['adminPass'])) {
            $params['admin_pass_hash'] = password_hash((string) $data['adminPass'], PASSWORD_DEFAULT);
            $sql = 'UPDATE settings SET
                committee_name = :committee_name,
                committee_subtitle = :committee_subtitle,
                admin_user = :admin_user,
                admin_pass_hash = :admin_pass_hash,
                admin_email = :admin_email,
                recovery_contact = :recovery_contact,
                recovery_note = :recovery_note,
                amt_per_share = :amt_per_share,
                total_months = :total_months,
                prize_per_share = :prize_per_share,
                start_month = :start_month,
                current_month = :current_month,
                next_id = :next_id,
                updated_at = :updated_at' . $rulesAssign . '
            WHERE id = 1';
        } else {
            $sql = 'UPDATE settings SET
                committee_name = :committee_name,
                committee_subtitle = :committee_subtitle,
                admin_user = :admin_user,
                admin_email = :admin_email,
                recovery_contact = :recovery_contact,
                recovery_note = :recovery_note,
                amt_per_share = :amt_per_share,
                total_months = :total_months,
                prize_per_share = :prize_per_share,
                start_month = :start_month,
                current_month = :current_month,
                next_id = :next_id,
                updated_at = :updated_at' . $rulesAssign . '
            WHERE id = 1';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function saveMembers(array $members): void
    {
        $existingPins = $this->loadPinHashes();
        $softDelete = $this->supportsSoftDelete();

        $upsert = $this->pdo->prepare(
            $softDelete
                ? 'INSERT INTO members (id, name, shares, phone, email, pin_hash, pref_month, deleted_at, deleted_by)
                   VALUES (:id, :name, :shares, :phone, :email, :pin_hash, :pref_month, NULL, NULL)
                   ON DUPLICATE KEY UPDATE
                      name = VALUES(name),
                      shares = VALUES(shares),
                      phone = VALUES(phone),
                      email = VALUES(email),
                      pin_hash = VALUES(pin_hash),
                      pref_month = VALUES(pref_month),
                      deleted_at = NULL,
                      deleted_by = NULL'
                : 'INSERT INTO members (id, name, shares, phone, email, pin_hash, pref_month)
                   VALUES (:id, :name, :shares, :phone, :email, :pin_hash, :pref_month)
                   ON DUPLICATE KEY UPDATE
                      name = VALUES(name),
                      shares = VALUES(shares),
                      phone = VALUES(phone),
                      email = VALUES(email),
                      pin_hash = VALUES(pin_hash),
                      pref_month = VALUES(pref_month)'
        );

        foreach ($members as $member) {
            $id = (int) ($member['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $pin = trim((string) ($member['pin'] ?? ''));
            if ($pin === '') {
                // Keep existing hash; default new members to member ID.
                $pinHash = $existingPins[$id] ?? password_hash((string) $id, PASSWORD_DEFAULT);
            } elseif (!isset($existingPins[$id]) || $this->shouldRehashPin($id, $pin, $existingPins[$id])) {
                $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            } else {
                $pinHash = $existingPins[$id];
            }

            $upsert->execute([
                'id' => $id,
                'name' => (string) ($member['name'] ?? 'Member ' . $id),
                'shares' => max(1, (int) ($member['shares'] ?? 1)),
                'phone' => (string) ($member['phone'] ?? ''),
                'email' => (string) ($member['email'] ?? ''),
                'pin_hash' => $pinHash,
                'pref_month' => (int) ($member['prefMonth'] ?? 0),
            ]);
        }
    }

    private function saveTrashedMembers(array $trashedMembers): void
    {
        if (!$this->supportsSoftDelete()) {
            return;
        }

        $existingPins = $this->loadPinHashes();

        $upsert = $this->pdo->prepare(
            'INSERT INTO members (id, name, shares, phone, email, pin_hash, pref_month, deleted_at, deleted_by)
             VALUES (:id, :name, :shares, :phone, :email, :pin_hash, :pref_month, :deleted_at, :deleted_by)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                shares = VALUES(shares),
                phone = VALUES(phone),
                email = VALUES(email),
                pin_hash = VALUES(pin_hash),
                pref_month = VALUES(pref_month),
                deleted_at = VALUES(deleted_at),
                deleted_by = VALUES(deleted_by)'
        );

        foreach ($trashedMembers as $member) {
            $id = (int) ($member['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $pin = trim((string) ($member['pin'] ?? ''));
            if ($pin === '') {
                $pinHash = $existingPins[$id] ?? password_hash((string) $id, PASSWORD_DEFAULT);
            } elseif (!isset($existingPins[$id]) || $this->shouldRehashPin($id, $pin, $existingPins[$id])) {
                $pinHash = password_hash($pin, PASSWORD_DEFAULT);
            } else {
                $pinHash = $existingPins[$id];
            }

            $upsert->execute([
                'id' => $id,
                'name' => (string) ($member['name'] ?? 'Member ' . $id),
                'shares' => max(1, (int) ($member['shares'] ?? 1)),
                'phone' => (string) ($member['phone'] ?? ''),
                'email' => (string) ($member['email'] ?? ''),
                'pin_hash' => $pinHash,
                'pref_month' => (int) ($member['prefMonth'] ?? 0),
                'deleted_at' => (int) ($member['deletedAt'] ?? (microtime(true) * 1000)),
                'deleted_by' => (string) ($member['deletedBy'] ?? 'Admin'),
            ]);
        }
    }

    private function savePayments(array $members, array $trashedMembers = []): void
    {
        $this->pdo->exec('DELETE FROM payments');
        $insert = $this->pdo->prepare(
            'INSERT INTO payments (member_id, month_num) VALUES (:member_id, :month_num)'
        );

        $allMembers = array_merge($members, $trashedMembers);

        foreach ($allMembers as $member) {
            $memberId = (int) ($member['id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }

            foreach (($member['payments'] ?? []) as $key => $paid) {
                if (!$paid || !preg_match('/^M(\d+)$/', (string) $key, $matches)) {
                    continue;
                }

                $insert->execute([
                    'member_id' => $memberId,
                    'month_num' => (int) $matches[1],
                ]);
            }
        }
    }

    private function saveWinners(array $winners): void
    {
        $this->pdo->exec('DELETE FROM winners');
        $insert = $this->pdo->prepare(
            'INSERT INTO winners (member_id, name, month_num, shares_won, amount)
             VALUES (:member_id, :name, :month_num, :shares_won, :amount)'
        );

        foreach ($winners as $winner) {
            $insert->execute([
                'member_id' => (int) ($winner['memberId'] ?? 0),
                'name' => (string) ($winner['name'] ?? ''),
                'month_num' => (int) ($winner['month'] ?? 0),
                'shares_won' => max(1, (int) ($winner['sharesWon'] ?? 1)),
                'amount' => (int) ($winner['amount'] ?? 0),
            ]);
        }
    }

    private function loadPinHashes(): array
    {
        $stmt = $this->pdo->query('SELECT id, pin_hash FROM members');
        $map = [];

        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['id']] = $row['pin_hash'];
        }

        return $map;
    }

    private function shouldRehashPin(int $memberId, string $pin, string $existingHash): bool
    {
        if ($pin === (string) $memberId && password_verify((string) $memberId, $existingHash)) {
            return false;
        }

        return !password_verify($pin, $existingHash);
    }
}
