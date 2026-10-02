<?php

declare(strict_types=1);

namespace CommitteeManager;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Committee-scoped extension of the legacy aggregate repository.
 *
 * Authentication continues to use DataRepository/settings. This repository owns
 * committee authorization, scoped reads/writes and enterprise reporting.
 */
final class MultiCommitteeRepository
{
    private PDO $pdo;
    private DataRepository $legacy;

    public function __construct(?PDO $pdo = null, ?DataRepository $legacy = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->legacy = $legacy ?? new DataRepository($this->pdo);
    }

    public function defaultCommitteeId(): int
    {
        $value = $this->pdo->query(
            'SELECT default_committee_id FROM settings WHERE id = 1 LIMIT 1'
        )->fetchColumn();
        $committeeId = (int) $value;
        if ($committeeId <= 0) {
            $committeeId = (int) ($this->pdo->query(
                'SELECT id FROM committees ORDER BY id LIMIT 1'
            )->fetchColumn() ?: 0);
        }
        if ($committeeId <= 0) {
            throw new RuntimeException('No committee is configured. Run php scripts/migrate.php.', 503);
        }

        return $committeeId;
    }

    /** Resolve and authorize a committee without trusting a client-side selection. */
    public function resolveCommitteeId(?int $requestedId, array $user, bool $forWrite = false): int
    {
        $requestedId = (int) ($requestedId ?? 0);
        $role = (string) ($user['role'] ?? '');

        // ── Superadmin: unrestricted access ───────────────────────────────
        if ($role === 'admin') {
            $committeeId = $requestedId > 0 ? $requestedId : $this->defaultCommitteeId();
            $stmt = $this->pdo->prepare('SELECT status, archived_at FROM committees WHERE id = :id');
            $stmt->execute(['id' => $committeeId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Committee not found.', 404);
            }
            if ($forWrite && ($row['archived_at'] !== null || $row['status'] === 'archived')) {
                throw new RuntimeException('Archived committees are read-only.', 409);
            }

            return $committeeId;
        }

        // ── Committee owner: scoped to committee_users rows ───────────────
        if ($role === 'owner') {
            $userId = (int) ($user['userId'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Owner session invalid.', 403);
            }
            if ($requestedId > 0) {
                $stmt = $this->pdo->prepare(
                    'SELECT c.id, c.status, c.archived_at
                     FROM committees c
                     JOIN committee_users cu ON cu.committee_id = c.id
                     WHERE c.id = :cid AND cu.user_id = :uid'
                );
                $stmt->execute(['cid' => $requestedId, 'uid' => $userId]);
            } else {
                $stmt = $this->pdo->prepare(
                    'SELECT c.id, c.status, c.archived_at
                     FROM committees c
                     JOIN committee_users cu ON cu.committee_id = c.id
                     WHERE cu.user_id = :uid
                     ORDER BY cu.created_at, c.id LIMIT 1'
                );
                $stmt->execute(['uid' => $userId]);
            }
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('You do not have access to this committee.', 403);
            }
            if ($forWrite && ($row['archived_at'] !== null || $row['status'] === 'archived')) {
                throw new RuntimeException('Archived committees are read-only.', 409);
            }

            return (int) $row['id'];
        }

        // ── Committee member: scoped to membership ────────────────────────
        $memberId = (int) ($user['memberId'] ?? 0);
        if ($memberId <= 0) {
            throw new RuntimeException('Member access required.', 403);
        }

        if ($requestedId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT c.id, c.status, c.archived_at
                 FROM committees c
                 JOIN members m ON m.committee_id = c.id
                 WHERE c.id = :committee_id AND m.id = :member_id AND m.deleted_at IS NULL'
            );
            $stmt->execute(['committee_id' => $requestedId, 'member_id' => $memberId]);
        } else {
            $defaultId = $this->defaultCommitteeId();
            $stmt = $this->pdo->prepare(
                'SELECT c.id, c.status, c.archived_at
                 FROM committees c
                 JOIN members m ON m.committee_id = c.id
                 WHERE m.id = :member_id AND m.deleted_at IS NULL
                 ORDER BY (c.id = :default_id) DESC, c.id
                 LIMIT 1'
            );
            $stmt->execute(['member_id' => $memberId, 'default_id' => $defaultId]);
        }

        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('You do not have access to this committee.', 403);
        }
        if ($forWrite && ($row['archived_at'] !== null || $row['status'] === 'archived')) {
            throw new RuntimeException('Archived committees are read-only.', 409);
        }

        return (int) $row['id'];
    }

    public function loadData(int $committeeId, array $user): array
    {
        $committeeId = $this->resolveCommitteeId($committeeId, $user);
        [$committee, $committeeSettings] = $this->loadCommitteeRecord($committeeId);
        $memberScope = ($user['role'] ?? '') === 'user' ? (int) ($user['memberId'] ?? 0) : null;

        [$members, $trashedMembers] = $this->loadMembersWithPayments($committeeId, $memberScope);
        $winners = $this->loadWinners($committeeId, $memberScope, false);
        $events = $this->loadEvents($committeeId);
        $notifications = $this->loadNotifications($committeeId, $user);
        $auditLog = ($user['role'] ?? '') === 'admin' ? $this->loadAudit($committeeId) : [];
        $portfolio = $this->listCommitteeSummaries($user, 1, 100);
        $global = $this->loadGlobalSettings();

        return [
            // Legacy active-committee shape. Existing UI can continue reading it.
            'committeeName' => $committee['name'],
            'committeeSubtitle' => $committeeSettings['subtitle'],
            'adminUser' => $global['admin_user'],
            'adminPass' => '',
            'adminEmail' => ($user['role'] ?? '') === 'admin' ? $global['admin_email'] : '',
            'recoveryContact' => $global['recovery_contact'],
            'recoveryNote' => $global['recovery_note'],
            'amtPerShare' => $this->number($committee['installmentAmount']),
            'totalMonths' => $committeeSettings['totalMonths'],
            'prizePerShare' => $this->number($committeeSettings['prizePerShare']),
            'startMonth' => $committee['startDate'] ? substr($committee['startDate'], 0, 7) : '',
            'currentMonth' => $committeeSettings['currentMonth'],
            'committeeRules' => (object) $committeeSettings['winnerRules'],
            'auditLog' => $auditLog,
            'members' => $members,
            'trashedMembers' => $trashedMembers,
            'winners' => $winners,
            'nextId' => $this->nextMemberId(),
            'updatedAt' => $committee['updatedAt'],

            // Enterprise extension.
            'committeeId' => $committeeId,
            'version' => $committee['version'],
            'committee' => $committee,
            'committees' => $portfolio['items'],
            'committeesPagination' => $portfolio['pagination'],
            'enterpriseSummary' => $this->enterpriseSummary($user),
            'committeeSettings' => $committeeSettings,
            'events' => $events,
            'notifications' => $notifications,
            'monthClosures' => $this->loadMonthClosures($committeeId),
            'viewMode' => ($user['role'] ?? '') === 'admin' ? 'admin' : 'member',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function loadMonthClosures(int $committeeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT month_num, status, notes, closed_by, closed_at, reopened_by, reopened_at
             FROM committee_month_closures WHERE committee_id = :committee_id ORDER BY month_num'
        );
        $stmt->execute(['committee_id' => $committeeId]);

        return array_map(static fn (array $row): array => [
            'month' => (int) $row['month_num'],
            'status' => (string) $row['status'],
            'notes' => (string) ($row['notes'] ?? ''),
            'closedBy' => (string) ($row['closed_by'] ?? ''),
            'closedAt' => $row['closed_at'] === null ? null : (int) $row['closed_at'],
            'reopenedBy' => $row['reopened_by'] ?? null,
            'reopenedAt' => $row['reopened_at'] === null ? null : (int) $row['reopened_at'],
        ], $stmt->fetchAll());
    }

    public function setMonthClosure(int $committeeId, int $month, bool $closed, string $notes, array $user): array
    {
        $this->requireAdmin($user);
        $committeeId = $this->resolveCommitteeId($committeeId, $user, true);
        [, $settings] = $this->loadCommitteeRecord($committeeId);
        $month = $this->positiveInt($month, 'month', 1, (int) $settings['totalMonths']);
        $now = $this->now();
        $actor = (string) ($user['role'] ?? 'admin');

        if ($closed) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO committee_month_closures (committee_id, month_num, status, notes, closed_by, closed_at, reopened_by, reopened_at)
                 VALUES (:committee_id, :month_num, 'closed', :notes, :actor, :now, NULL, NULL)
                 ON DUPLICATE KEY UPDATE status = 'closed', notes = VALUES(notes), closed_by = VALUES(closed_by), closed_at = VALUES(closed_at), reopened_by = NULL, reopened_at = NULL"
            );
            $stmt->execute(['committee_id' => $committeeId, 'month_num' => $month, 'notes' => trim($notes), 'actor' => $actor, 'now' => $now]);
            $this->writeAudit($committeeId, $user, 'Month Closed', 'month_closure', (string) $month, null, ['month' => $month, 'notes' => trim($notes)]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE committee_month_closures SET status = 'open', reopened_by = :actor, reopened_at = :now WHERE committee_id = :committee_id AND month_num = :month_num"
            );
            $stmt->execute(['committee_id' => $committeeId, 'month_num' => $month, 'actor' => $actor, 'now' => $now]);
            $this->writeAudit($committeeId, $user, 'Month Reopened', 'month_closure', (string) $month, null, ['month' => $month]);
        }

        return $this->loadData($committeeId, $user);
    }

    public function listCommitteeSummaries(
        array $user,
        int $page = 1,
        int $perPage = 50,
        string $search = '',
        ?string $status = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where = ['1 = 1'];

        $role = (string) ($user['role'] ?? '');
        if ($role === 'owner') {
            $where[] = 'EXISTS (
                SELECT 1 FROM committee_users cu
                WHERE cu.committee_id = c.id AND cu.user_id = ?
            )';
            $params[] = (int) ($user['userId'] ?? 0);
        } elseif ($role !== 'admin') {
            $where[] = 'EXISTS (
                SELECT 1 FROM members access_member
                WHERE access_member.committee_id = c.id
                  AND access_member.id = ? AND access_member.deleted_at IS NULL
            )';
            $params[] = (int) ($user['memberId'] ?? 0);
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(LOWER(c.name) LIKE ? OR LOWER(COALESCE(c.description, \'\')) LIKE ?)';
            $needle = '%' . strtolower($search) . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'c.status = ?';
            $params[] = $this->validateStatus($status, self::committeeStatuses(), 'committee status');
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM committees c WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = "SELECT c.*, cs.subtitle, cs.total_months, cs.current_month,
                       cs.prize_per_share, cs.currency, cs.theme_color
                FROM committees c
                JOIN committee_settings cs ON cs.committee_id = c.id
                WHERE {$whereSql}
                ORDER BY c.archived_at IS NOT NULL, c.updated_at DESC, c.id DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $aggregates = $this->portfolioAggregates(
            $ids,
            ($user['role'] ?? '') === 'user' ? (int) ($user['memberId'] ?? 0) : null
        );

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $stats = $aggregates[$id] ?? [
                'members' => 0,
                'collection' => 0,
                'paidInstallments' => 0,
                'winners' => 0,
                'nextDrawAt' => null,
            ];
            $expected = (int) $stats['members'] * max(1, (int) $row['total_months']);
            $progress = $expected > 0
                ? min(100, round(((int) $stats['paidInstallments'] / $expected) * 100, 1))
                : 0;
            $items[] = [
                'id' => $id,
                'committeeId' => $id,
                'name' => (string) $row['name'],
                'committeeName' => (string) $row['name'],
                'description' => (string) ($row['description'] ?? ''),
                'logo' => (string) $row['logo'],
                'status' => (string) $row['status'],
                'startDate' => $row['start_date'],
                'endDate' => $row['end_date'],
                'currency' => (string) $row['currency'],
                'color' => (string) $row['theme_color'],
                'totalMembers' => (int) $stats['members'],
                'totalCollection' => $this->number($stats['collection']),
                'progress' => $progress,
                'nextDrawDate' => $stats['nextDrawAt'] !== null ? (int) $stats['nextDrawAt'] : null,
                'totalWinners' => (int) $stats['winners'],
                'version' => (int) $row['version'],
                'updatedAt' => (int) $row['updated_at'],
            ];
        }

        return [
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    public function enterpriseSummary(array $user): array
    {
        $role = (string) ($user['role'] ?? '');
        $memberId = $role === 'user' ? (int) ($user['memberId'] ?? 0) : null;
        $ownerId  = $role === 'owner' ? (int) ($user['userId'] ?? 0) : null;
        $access = '';
        $params = [];
        if ($ownerId !== null) {
            $access = ' AND EXISTS (
                SELECT 1 FROM committee_users cu
                WHERE cu.committee_id = c.id AND cu.user_id = ?
            )';
            $params[] = $ownerId;
        } elseif ($memberId !== null) {
            $access = ' AND EXISTS (
                SELECT 1 FROM members am
                WHERE am.committee_id = c.id AND am.id = ? AND am.deleted_at IS NULL
            )';
            $params[] = $memberId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(c.status = 'running'), 0) AS running,
                    COALESCE(SUM(c.status = 'completed'), 0) AS completed
             FROM committees c WHERE 1 = 1 {$access}"
        );
        $stmt->execute($params);
        $committeeStats = $stmt->fetch() ?: ['total' => 0, 'running' => 0, 'completed' => 0];

        // Sub-filters for the remaining queries.
        $ownerJoin  = $ownerId  !== null ? ' JOIN committee_users _cu ON _cu.committee_id = m.committee_id AND _cu.user_id = ?' : '';
        $memberOnly = $memberId !== null ? ' AND m.id = ?' : '';
        $ownerParam  = $ownerId  !== null ? [$ownerId]  : [];
        $memberParam = $memberId !== null ? [$memberId] : [];

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM members m
             JOIN committees c ON c.id = m.committee_id
             {$ownerJoin}
             WHERE m.deleted_at IS NULL {$memberOnly}"
        );
        $stmt->execute(array_merge($ownerParam, $memberParam));
        $totalMembers = (int) $stmt->fetchColumn();

        $ownerPayJoin = $ownerId !== null ? ' JOIN committee_users _cu ON _cu.committee_id = m.committee_id AND _cu.user_id = ?' : '';
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(
                 CASE WHEN p.amount_paid > 0 THEN p.amount_paid ELSE c.installment_amount * m.shares END
             ), 0)
             FROM payments p
             JOIN members m ON m.id = p.member_id AND m.committee_id = p.committee_id
             JOIN committees c ON c.id = p.committee_id
             JOIN committee_settings cs ON cs.committee_id = c.id
             {$ownerPayJoin}
             WHERE p.status IN ('paid', 'late') AND p.month_num = cs.current_month {$memberOnly}"
        );
        $stmt->execute(array_merge($ownerParam, $memberParam));
        $monthlyCollection = $this->number($stmt->fetchColumn());

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(c.installment_amount * m.shares), 0)
             FROM members m
             JOIN committees c ON c.id = m.committee_id
             JOIN committee_settings cs ON cs.committee_id = c.id
             {$ownerJoin}
             LEFT JOIN payments p
               ON p.committee_id = m.committee_id AND p.member_id = m.id
              AND p.month_num = cs.current_month AND p.status IN ('paid', 'late')
             WHERE m.deleted_at IS NULL AND p.member_id IS NULL {$memberOnly}"
        );
        $stmt->execute(array_merge($ownerParam, $memberParam));
        $pendingPayments = $this->number($stmt->fetchColumn());

        $ownerWinJoin = $ownerId !== null
            ? ' JOIN committee_users _cu ON _cu.committee_id = w.committee_id AND _cu.user_id = ?' : '';
        $winnerOnly = $memberId !== null ? ' AND w.member_id = ?' : '';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM winners w
             {$ownerWinJoin}
             WHERE w.deleted_at IS NULL AND w.status <> 'voided' {$winnerOnly}"
        );
        $stmt->execute(array_merge($ownerParam, $memberParam));
        $totalWinners = (int) $stmt->fetchColumn();

        $todayStart = strtotime('today') * 1000;
        $todayEnd = strtotime('tomorrow') * 1000;
        $ownerEvJoin = $ownerId !== null
            ? ' JOIN committee_users _cu ON _cu.committee_id = e.committee_id AND _cu.user_id = ?' : '';
        $eventMemberFilter = $memberId !== null
            ? ' AND EXISTS (SELECT 1 FROM members em WHERE em.committee_id = e.committee_id AND em.id = ? AND em.deleted_at IS NULL)'
            : '';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM committee_events e
             {$ownerEvJoin}
             WHERE e.deleted_at IS NULL AND e.type = 'draw'
               AND e.start_at >= ? AND e.start_at < ? {$eventMemberFilter}"
        );
        $eventParams = array_merge($ownerParam, [$todayStart, $todayEnd], $memberParam);
        $stmt->execute($eventParams);

        return [
            'totalCommittees' => (int) $committeeStats['total'],
            'running' => (int) $committeeStats['running'],
            'completed' => (int) $committeeStats['completed'],
            'totalMembers' => $totalMembers,
            'monthlyCollection' => $monthlyCollection,
            'pendingPayments' => $pendingPayments,
            'todaysDraw' => (int) $stmt->fetchColumn(),
            'totalWinners' => $totalWinners,
        ];
    }

    /** @return array{0: array, 1: array} */
    private function loadCommitteeRecord(int $committeeId, bool $forUpdate = false): array
    {
        $sql = 'SELECT c.*, cs.subtitle, cs.total_months, cs.current_month,
                       cs.prize_per_share, cs.draw_method, cs.installment_frequency,
                       cs.winner_rules, cs.penalty_rules, cs.payment_grace_period,
                       cs.notification_settings, cs.certificate_template,
                       cs.currency, cs.theme_color, cs.updated_at AS settings_updated_at
                FROM committees c
                JOIN committee_settings cs ON cs.committee_id = c.id
                WHERE c.id = :id';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $committeeId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Committee not found.', 404);
        }

        $committee = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'logo' => (string) $row['logo'],
            'status' => (string) $row['status'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'committeeAmount' => $this->number($row['committee_amount']),
            'installmentAmount' => $this->number($row['installment_amount']),
            'drawFrequency' => (string) $row['draw_frequency'],
            'currency' => (string) $row['currency'],
            'color' => (string) $row['theme_color'],
            'notes' => (string) ($row['notes'] ?? ''),
            'version' => (int) $row['version'],
            'createdAt' => (int) $row['created_at'],
            'updatedAt' => (int) $row['updated_at'],
            'archivedAt' => $row['archived_at'] !== null ? (int) $row['archived_at'] : null,
        ];
        $settings = [
            'committeeId' => (int) $row['id'],
            'subtitle' => (string) $row['subtitle'],
            'totalMonths' => (int) $row['total_months'],
            'currentMonth' => (int) $row['current_month'],
            'prizePerShare' => $this->number($row['prize_per_share']),
            'drawMethod' => (string) $row['draw_method'],
            'installmentFrequency' => (string) $row['installment_frequency'],
            'winnerRules' => $this->decodeObject($row['winner_rules']),
            'penaltyRules' => $this->decodeObject($row['penalty_rules']),
            'paymentGracePeriod' => (int) $row['payment_grace_period'],
            'notificationSettings' => $this->decodeObject($row['notification_settings']),
            'certificateTemplate' => $this->decodeObject($row['certificate_template']),
            'currency' => (string) $row['currency'],
            'themeColor' => (string) $row['theme_color'],
            'updatedAt' => (int) $row['settings_updated_at'],
        ];

        return [$committee, $settings];
    }

    private function loadGlobalSettings(): array
    {
        $stmt = $this->pdo->query(
            'SELECT admin_user, admin_email, recovery_contact, recovery_note
             FROM settings WHERE id = 1 LIMIT 1'
        );
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Settings row missing.', 503);
        }

        return $row;
    }

    /** @return array{0: array, 1: array} */
    private function loadMembersWithPayments(int $committeeId, ?int $memberScope): array
    {
        $params = [$committeeId];
        $scopeSql = '';
        if ($memberScope !== null) {
            $scopeSql = ' AND id = ?';
            $params[] = $memberScope;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, name, shares, phone, email, photo, documents, notes,
                    pref_month, created_at, updated_at, deleted_at, deleted_by
             FROM members
             WHERE committee_id = ? {$scopeSql}
             ORDER BY id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $memberIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $payments = [];
        if ($memberIds !== []) {
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $paymentParams = array_merge([$committeeId], $memberIds);
            $paymentStmt = $this->pdo->prepare(
                "SELECT member_id, month_num, amount_due, amount_paid, status,
                        due_at, paid_at, payment_method, reference_no, notes, updated_at
                 FROM payments
                 WHERE committee_id = ? AND member_id IN ({$placeholders})
                 ORDER BY member_id, month_num"
            );
            $paymentStmt->execute($paymentParams);
            foreach ($paymentStmt->fetchAll() as $payment) {
                $id = (int) $payment['member_id'];
                $month = (int) $payment['month_num'];
                $isPaid = in_array((string) $payment['status'], ['paid', 'late'], true)
                    && (float) $payment['amount_paid'] >= (float) $payment['amount_due'];
                if ($isPaid) {
                    $payments[$id]['M' . $month] = true;
                }
                $payments[$id]['_history'][] = [
                    'month' => $month,
                    'amountDue' => $this->number($payment['amount_due']),
                    'amountPaid' => $this->number($payment['amount_paid']),
                    'status' => (string) $payment['status'],
                    'dueAt' => $payment['due_at'] !== null ? (int) $payment['due_at'] : null,
                    'paidAt' => $payment['paid_at'] !== null ? (int) $payment['paid_at'] : null,
                    'paymentMethod' => (string) $payment['payment_method'],
                    'referenceNo' => (string) $payment['reference_no'],
                    'notes' => (string) ($payment['notes'] ?? ''),
                    'updatedAt' => (int) $payment['updated_at'],
                ];
            }
        }

        $active = [];
        $trashed = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $member = [
                'id' => $id,
                'committeeId' => $committeeId,
                'name' => (string) $row['name'],
                'shares' => (int) $row['shares'],
                'phone' => (string) $row['phone'],
                'email' => (string) $row['email'],
                'photo' => (string) $row['photo'],
                'documents' => $this->decodeArray($row['documents']),
                'notes' => (string) ($row['notes'] ?? ''),
                'pin' => '',
                'prefMonth' => (int) $row['pref_month'],
                'payments' => $payments[$id] ?? [],
                'paymentHistory' => $payments[$id]['_history'] ?? [],
                'createdAt' => (int) $row['created_at'],
                'updatedAt' => (int) $row['updated_at'],
            ];
            unset($member['payments']['_history']);
            if ($row['deleted_at'] === null) {
                $active[] = $member;
            } else {
                $member['deletedAt'] = (int) $row['deleted_at'];
                $member['deletedBy'] = (string) ($row['deleted_by'] ?? '');
                $trashed[] = $member;
            }
        }

        return [$active, $trashed];
    }

    private function loadWinners(int $committeeId, ?int $memberScope, bool $includeDeleted): array
    {
        $where = ['committee_id = ?'];
        $params = [$committeeId];
        if ($memberScope !== null) {
            $where[] = 'member_id = ?';
            $params[] = $memberScope;
        }
        if (!$includeDeleted) {
            $where[] = "deleted_at IS NULL AND status <> 'voided'";
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, committee_id, member_id, name, month_num, draw_number,
                    shares_won, amount, winner_date, status, payment_status,
                    certificate_number, certificate_data, notes, declared_at,
                    updated_at, deleted_at, deleted_by
             FROM winners WHERE ' . implode(' AND ', $where) . '
             ORDER BY month_num, draw_number, id'
        );
        $stmt->execute($params);

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'committeeId' => (int) $row['committee_id'],
                'memberId' => $row['member_id'] !== null ? (int) $row['member_id'] : null,
                'name' => (string) $row['name'],
                'month' => (int) $row['month_num'],
                'drawNumber' => (int) $row['draw_number'],
                'sharesWon' => (int) $row['shares_won'],
                'amount' => $this->number($row['amount']),
                'date' => $row['winner_date'],
                'status' => (string) $row['status'],
                'paymentStatus' => (string) $row['payment_status'],
                'certificateNumber' => (string) $row['certificate_number'],
                'certificate' => $this->decodeObject($row['certificate_data']),
                'notes' => (string) ($row['notes'] ?? ''),
                'declaredAt' => (int) $row['declared_at'],
                'updatedAt' => (int) $row['updated_at'],
                'deletedAt' => $row['deleted_at'] !== null ? (int) $row['deleted_at'] : null,
                'deletedBy' => $row['deleted_by'],
            ];
        }, $stmt->fetchAll());
    }

    private function loadEvents(int $committeeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, title, description, start_at, end_at, status,
                    created_by, created_at, updated_at
             FROM committee_events
             WHERE committee_id = :committee_id AND deleted_at IS NULL
             ORDER BY start_at, id LIMIT 250'
        );
        $stmt->execute(['committee_id' => $committeeId]);

        return array_map(static function (array $row) use ($committeeId): array {
            return [
                'id' => (int) $row['id'],
                'committeeId' => $committeeId,
                'type' => (string) $row['type'],
                'title' => (string) $row['title'],
                'description' => (string) ($row['description'] ?? ''),
                'startAt' => (int) $row['start_at'],
                'endAt' => $row['end_at'] !== null ? (int) $row['end_at'] : null,
                'status' => (string) $row['status'],
                'createdBy' => (string) $row['created_by'],
                'createdAt' => (int) $row['created_at'],
                'updatedAt' => (int) $row['updated_at'],
            ];
        }, $stmt->fetchAll());
    }

    private function loadNotifications(int $committeeId, array $user): array
    {
        $params = [$committeeId];
        $scope = '';
        if (($user['role'] ?? '') === 'user') {
            $scope = ' AND (member_id IS NULL OR member_id = ?)';
            $params[] = (int) ($user['memberId'] ?? 0);
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, member_id, channel, type, title, message, status,
                    recipient, sent_at, read_at, error_message, created_at
             FROM notification_logs
             WHERE committee_id = ? {$scope}
             ORDER BY created_at DESC, id DESC LIMIT 100"
        );
        $stmt->execute($params);

        return array_map(static function (array $row) use ($committeeId): array {
            return [
                'id' => (int) $row['id'],
                'committeeId' => $committeeId,
                'memberId' => $row['member_id'] !== null ? (int) $row['member_id'] : null,
                'channel' => (string) $row['channel'],
                'type' => (string) $row['type'],
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'status' => (string) $row['status'],
                'recipient' => (string) $row['recipient'],
                'sentAt' => $row['sent_at'] !== null ? (int) $row['sent_at'] : null,
                'readAt' => $row['read_at'] !== null ? (int) $row['read_at'] : null,
                'error' => $row['error_message'],
                'createdAt' => (int) $row['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    private function loadAudit(int $committeeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, actor_role, actor_member_id, actor_name, action,
                    entity_type, entity_id, before_data, after_data, ip,
                    user_agent, created_at
             FROM audit_logs WHERE committee_id = :committee_id
             ORDER BY created_at DESC, id DESC LIMIT 200'
        );
        $stmt->execute(['committee_id' => $committeeId]);

        return array_map(function (array $row): array {
            $before = $this->decodeObject($row['before_data']);
            $after = $this->decodeObject($row['after_data']);
            return [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'oldValue' => $before['value'] ?? $before,
                'newValue' => $after['value'] ?? $after,
                'admin' => (string) $row['actor_name'],
                'at' => (int) $row['created_at'],
                'ip' => (string) $row['ip'],
                'actorRole' => (string) $row['actor_role'],
                'actorMemberId' => $row['actor_member_id'] !== null ? (int) $row['actor_member_id'] : null,
                'entityType' => (string) $row['entity_type'],
                'entityId' => (string) $row['entity_id'],
                'before' => $before,
                'after' => $after,
                'userAgent' => (string) $row['user_agent'],
            ];
        }, $stmt->fetchAll());
    }

    private function portfolioAggregates(array $committeeIds, ?int $memberScope): array
    {
        if ($committeeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($committeeIds), '?'));
        $result = [];
        foreach ($committeeIds as $id) {
            $result[$id] = [
                'members' => 0,
                'collection' => 0,
                'paidInstallments' => 0,
                'winners' => 0,
                'nextDrawAt' => null,
            ];
        }

        $scope = $memberScope !== null ? ' AND id = ?' : '';
        $params = $committeeIds;
        if ($memberScope !== null) {
            $params[] = $memberScope;
        }
        $stmt = $this->pdo->prepare(
            "SELECT committee_id, COUNT(*) AS total
             FROM members
             WHERE committee_id IN ({$placeholders}) AND deleted_at IS NULL {$scope}
             GROUP BY committee_id"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['committee_id']]['members'] = (int) $row['total'];
        }

        $paymentScope = $memberScope !== null ? ' AND p.member_id = ?' : '';
        $params = $committeeIds;
        if ($memberScope !== null) {
            $params[] = $memberScope;
        }
        $stmt = $this->pdo->prepare(
            "SELECT p.committee_id,
                    COUNT(*) AS paid_installments,
                    COALESCE(SUM(CASE WHEN p.amount_paid > 0 THEN p.amount_paid
                                      ELSE c.installment_amount * m.shares END), 0) AS collection
             FROM payments p
             JOIN members m ON m.id = p.member_id AND m.committee_id = p.committee_id
             JOIN committees c ON c.id = p.committee_id
             WHERE p.committee_id IN ({$placeholders})
               AND p.status IN ('paid', 'late') {$paymentScope}
             GROUP BY p.committee_id"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['committee_id'];
            $result[$id]['paidInstallments'] = (int) $row['paid_installments'];
            $result[$id]['collection'] = $this->number($row['collection']);
        }

        $winnerScope = $memberScope !== null ? ' AND member_id = ?' : '';
        $params = $committeeIds;
        if ($memberScope !== null) {
            $params[] = $memberScope;
        }
        $stmt = $this->pdo->prepare(
            "SELECT committee_id, COUNT(*) AS total
             FROM winners
             WHERE committee_id IN ({$placeholders})
               AND deleted_at IS NULL AND status <> 'voided' {$winnerScope}
             GROUP BY committee_id"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['committee_id']]['winners'] = (int) $row['total'];
        }

        $now = (int) (microtime(true) * 1000);
        $stmt = $this->pdo->prepare(
            "SELECT committee_id, MIN(start_at) AS next_draw
             FROM committee_events
             WHERE committee_id IN ({$placeholders}) AND type = 'draw'
               AND deleted_at IS NULL AND start_at >= ?
             GROUP BY committee_id"
        );
        $stmt->execute(array_merge($committeeIds, [$now]));
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['committee_id']]['nextDrawAt'] = $row['next_draw'] !== null
                ? (int) $row['next_draw']
                : null;
        }

        return $result;
    }

    private function nextMemberId(): int
    {
        return max(1, (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM members')->fetchColumn());
    }

    public function createCommittee(array $input, array $user): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Only the superadmin can create committees.', 403);
        }
        $committeeInput = is_array($input['committee'] ?? null) ? $input['committee'] : $input;
        $settingsInput = is_array($input['committeeSettings'] ?? null) ? $input['committeeSettings'] : $input;
        $name = trim((string) ($committeeInput['name'] ?? $committeeInput['committeeName'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Committee name is required.', 422);
        }
        if (strlen($name) > 255) {
            throw new InvalidArgumentException('Committee name is too long.', 422);
        }

        $now = $this->now();
        $status = $this->validateStatus(
            (string) ($committeeInput['status'] ?? 'running'),
            self::committeeStatuses(),
            'committee status'
        );
        $startDate = $this->dateOrNull($committeeInput['startDate'] ?? $committeeInput['start_date'] ?? null);
        $endDate = $this->dateOrNull($committeeInput['endDate'] ?? $committeeInput['end_date'] ?? null);
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new InvalidArgumentException('End date cannot be before start date.', 422);
        }
        $installmentAmount = $this->nonNegativeMoney(
            $committeeInput['installmentAmount'] ?? $committeeInput['amtPerShare'] ?? 0,
            'installment amount'
        );
        $committeeAmount = $this->nonNegativeMoney(
            $committeeInput['committeeAmount'] ?? $committeeInput['prizePerShare'] ?? 0,
            'committee amount'
        );
        $totalMonths = $this->positiveInt($settingsInput['totalMonths'] ?? 25, 'total months', 1, 1200);
        $currentMonth = $this->positiveInt($settingsInput['currentMonth'] ?? 1, 'current month', 1, $totalMonths);
        $prizePerShare = $this->nonNegativeMoney(
            $settingsInput['prizePerShare'] ?? $committeeAmount,
            'prize per share'
        );
        $currency = $this->currency($settingsInput['currency'] ?? $committeeInput['currency'] ?? 'PKR');
        $themeColor = $this->color($settingsInput['themeColor'] ?? $committeeInput['color'] ?? '#6366F1');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO committees (
                    name, description, logo, status, start_date, end_date,
                    committee_amount, installment_amount, draw_frequency, notes,
                    version, created_at, updated_at, archived_at
                 ) VALUES (
                    :name, :description, :logo, :status, :start_date, :end_date,
                    :committee_amount, :installment_amount, :draw_frequency, :notes,
                    1, :created_at, :updated_at, NULL
                 )'
            );
            $stmt->execute([
                'name' => $name,
                'description' => trim((string) ($committeeInput['description'] ?? '')),
                'logo' => trim((string) ($committeeInput['logo'] ?? '')),
                'status' => $status,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'committee_amount' => $committeeAmount,
                'installment_amount' => $installmentAmount,
                'draw_frequency' => $this->frequency($committeeInput['drawFrequency'] ?? 'monthly'),
                'notes' => trim((string) ($committeeInput['notes'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $committeeId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare(
                'INSERT INTO committee_settings (
                    committee_id, subtitle, total_months, current_month,
                    prize_per_share, draw_method, installment_frequency,
                    winner_rules, penalty_rules, payment_grace_period,
                    notification_settings, certificate_template, currency,
                    theme_color, updated_at
                 ) VALUES (
                    :committee_id, :subtitle, :total_months, :current_month,
                    :prize_per_share, :draw_method, :installment_frequency,
                    :winner_rules, :penalty_rules, :payment_grace_period,
                    :notification_settings, :certificate_template, :currency,
                    :theme_color, :updated_at
                 )'
            );
            $stmt->execute([
                'committee_id' => $committeeId,
                'subtitle' => trim((string) ($settingsInput['subtitle'] ?? $input['committeeSubtitle'] ?? 'Committee Management System')),
                'total_months' => $totalMonths,
                'current_month' => $currentMonth,
                'prize_per_share' => $prizePerShare,
                'draw_method' => $this->drawMethod($settingsInput['drawMethod'] ?? 'manual'),
                'installment_frequency' => $this->frequency($settingsInput['installmentFrequency'] ?? 'monthly'),
                'winner_rules' => $this->encodeObject($settingsInput['winnerRules'] ?? $input['committeeRules'] ?? []),
                'penalty_rules' => $this->encodeObject($settingsInput['penaltyRules'] ?? []),
                'payment_grace_period' => $this->nonNegativeInt($settingsInput['paymentGracePeriod'] ?? 0, 'payment grace period', 3650),
                'notification_settings' => $this->encodeObject($settingsInput['notificationSettings'] ?? ['inApp' => true]),
                'certificate_template' => $this->encodeObject($settingsInput['certificateTemplate'] ?? []),
                'currency' => $currency,
                'theme_color' => $themeColor,
                'updated_at' => $now,
            ]);
            $this->writeAudit(
                $committeeId,
                $user,
                'Committee Created',
                'committee',
                (string) $committeeId,
                null,
                ['name' => $name, 'status' => $status]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    public function archiveCommittee(int $committeeId, array $user, ?int $expectedVersion = null): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Only the superadmin can archive committees.', 403);
        }
        $committeeId = $this->resolveCommitteeId($committeeId, $user);
        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            [$committee] = $this->loadCommitteeRecord($committeeId, true);
            $this->assertVersion($committee, $expectedVersion);
            $stmt = $this->pdo->prepare(
                "UPDATE committees
                 SET status = 'archived', archived_at = :now,
                     updated_at = :now, version = version + 1
                 WHERE id = :id"
            );
            $stmt->execute(['now' => $now, 'id' => $committeeId]);
            $this->writeAudit(
                $committeeId,
                $user,
                'Committee Archived',
                'committee',
                (string) $committeeId,
                ['status' => $committee['status']],
                ['status' => 'archived']
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    public function saveCommitteeData(int $committeeId, array $data, array $user): array
    {
        $this->requireAdmin($user);
        $committeeId = $this->resolveCommitteeId($committeeId, $user, true);
        $expectedVersion = isset($data['version']) ? (int) $data['version'] : null;
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            [$beforeCommittee, $beforeSettings] = $this->loadCommitteeRecord($committeeId, true);
            $this->assertVersion($beforeCommittee, $expectedVersion);
            [$committee, $settings] = $this->normalizeCommitteeUpdate($data, $beforeCommittee, $beforeSettings);

            $stmt = $this->pdo->prepare(
                'UPDATE committees SET
                    name = :name, description = :description, logo = :logo,
                    status = :status, start_date = :start_date, end_date = :end_date,
                    committee_amount = :committee_amount,
                    installment_amount = :installment_amount,
                    draw_frequency = :draw_frequency, notes = :notes,
                    version = version + 1, updated_at = :updated_at,
                    archived_at = CASE WHEN :status_for_archive = \'archived\'
                                       THEN COALESCE(archived_at, :updated_at_for_archive)
                                       ELSE NULL END
                 WHERE id = :id'
            );
            $stmt->execute([
                'name' => $committee['name'],
                'description' => $committee['description'],
                'logo' => $committee['logo'],
                'status' => $committee['status'],
                'start_date' => $committee['startDate'],
                'end_date' => $committee['endDate'],
                'committee_amount' => $committee['committeeAmount'],
                'installment_amount' => $committee['installmentAmount'],
                'draw_frequency' => $committee['drawFrequency'],
                'notes' => $committee['notes'],
                'updated_at' => $now,
                'status_for_archive' => $committee['status'],
                'updated_at_for_archive' => $now,
                'id' => $committeeId,
            ]);

            $stmt = $this->pdo->prepare(
                'UPDATE committee_settings SET
                    subtitle = :subtitle, total_months = :total_months,
                    current_month = :current_month, prize_per_share = :prize_per_share,
                    draw_method = :draw_method,
                    installment_frequency = :installment_frequency,
                    winner_rules = :winner_rules, penalty_rules = :penalty_rules,
                    payment_grace_period = :payment_grace_period,
                    notification_settings = :notification_settings,
                    certificate_template = :certificate_template,
                    currency = :currency, theme_color = :theme_color,
                    updated_at = :updated_at
                 WHERE committee_id = :committee_id'
            );
            $stmt->execute([
                'subtitle' => $settings['subtitle'],
                'total_months' => $settings['totalMonths'],
                'current_month' => $settings['currentMonth'],
                'prize_per_share' => $settings['prizePerShare'],
                'draw_method' => $settings['drawMethod'],
                'installment_frequency' => $settings['installmentFrequency'],
                'winner_rules' => $this->encodeObject($settings['winnerRules']),
                'penalty_rules' => $this->encodeObject($settings['penaltyRules']),
                'payment_grace_period' => $settings['paymentGracePeriod'],
                'notification_settings' => $this->encodeObject($settings['notificationSettings']),
                'certificate_template' => $this->encodeObject($settings['certificateTemplate']),
                'currency' => $settings['currency'],
                'theme_color' => $settings['themeColor'],
                'updated_at' => $now,
                'committee_id' => $committeeId,
            ]);

            $allIncomingMembers = array_merge(
                is_array($data['members'] ?? null) ? $data['members'] : [],
                is_array($data['trashedMembers'] ?? null) ? $data['trashedMembers'] : []
            );
            $this->saveMembers($committeeId, $data, $user, $now);
            $this->savePayments($committeeId, $allIncomingMembers, $committee['installmentAmount'], $user, $now);
            if (array_key_exists('winners', $data)) {
                $this->saveWinners(
                    $committeeId,
                    is_array($data['winners']) ? $data['winners'] : [],
                    $settings,
                    $user,
                    $now
                );
            }
            if (array_key_exists('events', $data)) {
                $this->saveEvents($committeeId, is_array($data['events']) ? $data['events'] : [], $user, $now);
            }

            if ($committee != $beforeCommittee || $settings != $beforeSettings) {
                $this->writeAudit(
                    $committeeId,
                    $user,
                    'Settings Changed',
                    'committee_settings',
                    (string) $committeeId,
                    ['committee' => $beforeCommittee, 'settings' => $beforeSettings],
                    ['committee' => $committee, 'settings' => $settings]
                );
            }
            $this->updateLegacyMirror($committeeId, $committee, $settings, $data, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    /** @return array{0: array, 1: array} */
    private function normalizeCommitteeUpdate(array $data, array $current, array $currentSettings): array
    {
        $source = is_array($data['committee'] ?? null) ? $data['committee'] : [];
        $settingsSource = is_array($data['committeeSettings'] ?? null) ? $data['committeeSettings'] : [];

        $name = trim((string) ($source['name'] ?? $data['committeeName'] ?? $current['name']));
        if ($name === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('A valid committee name is required.', 422);
        }
        $startDate = $this->dateOrNull($source['startDate'] ?? ($data['startMonth'] ?? $current['startDate']));
        if (isset($data['startMonth']) && preg_match('/^\d{4}-\d{2}$/', (string) $data['startMonth'])) {
            $startDate = (string) $data['startMonth'] . '-01';
        }
        $endDate = $this->dateOrNull($source['endDate'] ?? $current['endDate']);
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            throw new InvalidArgumentException('End date cannot be before start date.', 422);
        }

        $committee = $current;
        $committee['name'] = $name;
        $committee['description'] = trim((string) ($source['description'] ?? $current['description']));
        $committee['logo'] = trim((string) ($source['logo'] ?? $current['logo']));
        $committee['status'] = $this->validateStatus(
            (string) ($source['status'] ?? $current['status']),
            self::committeeStatuses(),
            'committee status'
        );
        $committee['startDate'] = $startDate;
        $committee['endDate'] = $endDate;
        $committee['committeeAmount'] = $this->nonNegativeMoney(
            $source['committeeAmount'] ?? $current['committeeAmount'],
            'committee amount'
        );
        $committee['installmentAmount'] = $this->nonNegativeMoney(
            $source['installmentAmount'] ?? $data['amtPerShare'] ?? $current['installmentAmount'],
            'installment amount'
        );
        $committee['drawFrequency'] = $this->frequency($source['drawFrequency'] ?? $current['drawFrequency']);
        $committee['notes'] = trim((string) ($source['notes'] ?? $current['notes']));

        $settings = $currentSettings;
        $settings['subtitle'] = trim((string) (
            $settingsSource['subtitle'] ?? $data['committeeSubtitle'] ?? $currentSettings['subtitle']
        ));
        $settings['totalMonths'] = $this->positiveInt(
            $settingsSource['totalMonths'] ?? $data['totalMonths'] ?? $currentSettings['totalMonths'],
            'total months',
            1,
            1200
        );
        $settings['currentMonth'] = $this->positiveInt(
            $settingsSource['currentMonth'] ?? $data['currentMonth'] ?? $currentSettings['currentMonth'],
            'current month',
            1,
            $settings['totalMonths']
        );
        $settings['prizePerShare'] = $this->nonNegativeMoney(
            $settingsSource['prizePerShare'] ?? $data['prizePerShare'] ?? $currentSettings['prizePerShare'],
            'prize per share'
        );
        $settings['drawMethod'] = $this->drawMethod($settingsSource['drawMethod'] ?? $currentSettings['drawMethod']);
        $settings['installmentFrequency'] = $this->frequency(
            $settingsSource['installmentFrequency'] ?? $currentSettings['installmentFrequency']
        );
        $settings['winnerRules'] = $this->arrayValue(
            $settingsSource['winnerRules'] ?? $data['committeeRules'] ?? $currentSettings['winnerRules']
        );
        $settings['penaltyRules'] = $this->arrayValue(
            $settingsSource['penaltyRules'] ?? $currentSettings['penaltyRules']
        );
        $settings['paymentGracePeriod'] = $this->nonNegativeInt(
            $settingsSource['paymentGracePeriod'] ?? $currentSettings['paymentGracePeriod'],
            'payment grace period',
            3650
        );
        $settings['notificationSettings'] = $this->arrayValue(
            $settingsSource['notificationSettings'] ?? $currentSettings['notificationSettings']
        );
        $settings['certificateTemplate'] = $this->arrayValue(
            $settingsSource['certificateTemplate'] ?? $currentSettings['certificateTemplate']
        );
        $settings['currency'] = $this->currency(
            $settingsSource['currency'] ?? $source['currency'] ?? $currentSettings['currency']
        );
        $settings['themeColor'] = $this->color(
            $settingsSource['themeColor'] ?? $source['color'] ?? $currentSettings['themeColor']
        );

        return [$committee, $settings];
    }

    private function saveMembers(int $committeeId, array $data, array $user, int $now): void
    {
        $active = is_array($data['members'] ?? null) ? $data['members'] : [];
        $trashed = is_array($data['trashedMembers'] ?? null) ? $data['trashedMembers'] : [];
        if ($active === [] && $trashed === [] && !array_key_exists('members', $data)) {
            return;
        }

        $existingRows = $this->pdo->query(
            'SELECT id, committee_id, name, pin_hash, deleted_at FROM members'
        )->fetchAll();
        $byId = [];
        $activeNames = [];
        foreach ($existingRows as $row) {
            $id = (int) $row['id'];
            $byId[$id] = $row;
            if ($row['deleted_at'] === null) {
                $activeNames[$this->normalizeName((string) $row['name'])] = $id;
            }
        }

        $incomingNames = [];
        foreach ($active as $member) {
            if (!is_array($member)) {
                throw new InvalidArgumentException('Invalid member payload.', 422);
            }
            $name = trim((string) ($member['name'] ?? ''));
            if ($name === '' || strlen($name) > 255) {
                throw new InvalidArgumentException('Every member requires a valid name.', 422);
            }
            $normalized = $this->normalizeName($name);
            $id = (int) ($member['id'] ?? 0);
            if (isset($incomingNames[$normalized]) && $incomingNames[$normalized] !== $id) {
                throw new InvalidArgumentException('Member name already exists.', 422);
            }
            if (isset($activeNames[$normalized]) && $activeNames[$normalized] !== $id) {
                throw new InvalidArgumentException(
                    'Member name already exists in an active committee. Active member names must remain globally unique for login.',
                    422
                );
            }
            $incomingNames[$normalized] = $id;
        }

        $upsert = $this->pdo->prepare(
            'INSERT INTO members (
                id, committee_id, name, shares, phone, email, photo, documents,
                notes, pin_hash, pref_month, created_at, updated_at,
                deleted_at, deleted_by
             ) VALUES (
                :id, :committee_id, :name, :shares, :phone, :email, :photo,
                :documents, :notes, :pin_hash, :pref_month, :created_at,
                :updated_at, :deleted_at, :deleted_by
             )
             ON DUPLICATE KEY UPDATE
                committee_id = VALUES(committee_id), name = VALUES(name),
                shares = VALUES(shares), phone = VALUES(phone), email = VALUES(email),
                photo = VALUES(photo), documents = VALUES(documents), notes = VALUES(notes),
                pin_hash = VALUES(pin_hash), pref_month = VALUES(pref_month),
                updated_at = VALUES(updated_at), deleted_at = VALUES(deleted_at),
                deleted_by = VALUES(deleted_by)'
        );

        $nextId = $this->nextMemberId();
        foreach ([[$active, false], [$trashed, true]] as [$members, $isDeleted]) {
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $id = (int) ($member['id'] ?? 0);
                if ($id <= 0) {
                    $id = $nextId++;
                }
                if (isset($byId[$id]) && (int) $byId[$id]['committee_id'] !== $committeeId) {
                    throw new InvalidArgumentException('Member ID already belongs to another committee.', 422);
                }
                $name = trim((string) ($member['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Every member requires a name.', 422);
                }
                $email = trim((string) ($member['email'] ?? ''));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException("Invalid email for {$name}.", 422);
                }
                $pin = trim((string) ($member['pin'] ?? ''));
                if ($pin !== '' && (!ctype_digit($pin) || strlen($pin) > 12)) {
                    throw new InvalidArgumentException("PIN for {$name} must contain digits only.", 422);
                }
                $pinHash = isset($byId[$id])
                    ? (string) $byId[$id]['pin_hash']
                    : password_hash($pin !== '' ? $pin : (string) $id, PASSWORD_DEFAULT);
                if ($pin !== '' && (!isset($byId[$id]) || !password_verify($pin, $pinHash))) {
                    $pinHash = password_hash($pin, PASSWORD_DEFAULT);
                }
                $deletedAt = $isDeleted
                    ? max(1, (int) ($member['deletedAt'] ?? $now))
                    : null;
                $createdAt = isset($byId[$id])
                    ? max(0, (int) ($member['createdAt'] ?? 0))
                    : $now;
                if (isset($byId[$id]) && $createdAt === 0) {
                    // Preserve unknown legacy creation time rather than inventing it.
                    $createdAt = 0;
                }
                $upsert->execute([
                    'id' => $id,
                    'committee_id' => $committeeId,
                    'name' => $name,
                    'shares' => $this->positiveInt($member['shares'] ?? 1, 'member shares', 1, 1000000),
                    'phone' => trim((string) ($member['phone'] ?? '')),
                    'email' => $email,
                    'photo' => trim((string) ($member['photo'] ?? '')),
                    'documents' => $this->encodeArray($member['documents'] ?? []),
                    'notes' => trim((string) ($member['notes'] ?? '')),
                    'pin_hash' => $pinHash,
                    'pref_month' => max(0, (int) ($member['prefMonth'] ?? 0)),
                    'created_at' => $createdAt,
                    'updated_at' => $now,
                    'deleted_at' => $deletedAt,
                    'deleted_by' => $isDeleted ? $this->actorName($user) : null,
                ]);
                if (!isset($byId[$id]) && !$isDeleted) {
                    $this->writeAudit(
                        $committeeId,
                        $user,
                        'Member Added',
                        'member',
                        (string) $id,
                        null,
                        ['name' => $name, 'shares' => (int) ($member['shares'] ?? 1)]
                    );
                }
                $byId[$id] = [
                    'id' => $id,
                    'committee_id' => $committeeId,
                    'name' => $name,
                    'pin_hash' => $pinHash,
                    'deleted_at' => $deletedAt,
                ];
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE settings SET next_id = :next_id WHERE id = 1 AND next_id < :next_id_check'
        );
        $stmt->execute(['next_id' => $nextId, 'next_id_check' => $nextId]);
    }

    private function savePayments(
        int $committeeId,
        array $members,
        int|float $installmentAmount,
        array $user,
        int $now
    ): void {
        if ($members === []) {
            return;
        }
        $ids = [];
        $incoming = [];
        foreach ($members as $member) {
            if (!is_array($member)) {
                continue;
            }
            $memberId = (int) ($member['id'] ?? 0);
            if ($memberId <= 0) {
                continue;
            }
            $ids[] = $memberId;
            $paidMonths = [];
            foreach ((array) ($member['payments'] ?? []) as $key => $paid) {
                if ($paid && preg_match('/^M(\d+)$/', (string) $key, $match)) {
                    $paidMonths[(int) $match[1]] = true;
                }
            }
            $incoming[$memberId] = [
                'shares' => max(1, (int) ($member['shares'] ?? 1)),
                'months' => $paidMonths,
            ];
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT member_id, month_num, status, amount_due, amount_paid
             FROM payments WHERE committee_id = ? AND member_id IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$committeeId], $ids));
        $existing = [];
        foreach ($stmt->fetchAll() as $row) {
            $existing[(int) $row['member_id']][(int) $row['month_num']] = $row;
        }

        $upsert = $this->pdo->prepare(
            "INSERT INTO payments (
                committee_id, member_id, month_num, amount_due, amount_paid,
                status, paid_at, created_at, updated_at
             ) VALUES (
                :committee_id, :member_id, :month_num, :amount_due, :amount_paid,
                'paid', :paid_at, :created_at, :updated_at
             )
             ON DUPLICATE KEY UPDATE
                amount_due = VALUES(amount_due), amount_paid = VALUES(amount_paid),
                status = 'paid', paid_at = COALESCE(payments.paid_at, VALUES(paid_at)),
                updated_at = VALUES(updated_at)"
        );
        $markPending = $this->pdo->prepare(
            "UPDATE payments SET amount_paid = 0, status = 'pending',
                    paid_at = NULL, updated_at = :updated_at
             WHERE committee_id = :committee_id AND member_id = :member_id
               AND month_num = :month_num"
        );
        $changes = 0;
        foreach ($incoming as $memberId => $memberData) {
            $due = $this->moneyValue((float) $installmentAmount * (int) $memberData['shares']);
            foreach ($memberData['months'] as $month => $_) {
                if ($month <= 0 || $month > 1200) {
                    throw new InvalidArgumentException('Invalid payment month.', 422);
                }
                $was = $existing[$memberId][$month] ?? null;
                $upsert->execute([
                    'committee_id' => $committeeId,
                    'member_id' => $memberId,
                    'month_num' => $month,
                    'amount_due' => $due,
                    'amount_paid' => $due,
                    'paid_at' => $now,
                    'created_at' => $was ? 0 : $now,
                    'updated_at' => $now,
                ]);
                if (!$was || !in_array((string) $was['status'], ['paid', 'late'], true)
                    || (float) $was['amount_paid'] !== (float) $due) {
                    $changes++;
                }
            }
            foreach ($existing[$memberId] ?? [] as $month => $was) {
                if (!isset($memberData['months'][$month]) && (string) $was['status'] !== 'pending') {
                    $markPending->execute([
                        'updated_at' => $now,
                        'committee_id' => $committeeId,
                        'member_id' => $memberId,
                        'month_num' => $month,
                    ]);
                    $changes++;
                }
            }
        }
        if ($changes > 0) {
            $this->writeAudit(
                $committeeId,
                $user,
                'Payment Updated',
                'payment',
                '',
                null,
                ['changedInstallments' => $changes]
            );
        }
    }

    private function saveWinners(
        int $committeeId,
        array $incomingWinners,
        array $settings,
        array $user,
        int $now
    ): void {
        $existingRows = $this->loadWinners($committeeId, null, false);
        $existing = [];
        $signatures = [];
        foreach ($existingRows as $winner) {
            $existing[(int) $winner['id']] = $winner;
            $signatures[$this->winnerSignature($winner)] = (int) $winner['id'];
        }
        $retainedIds = [];

        $update = $this->pdo->prepare(
            'UPDATE winners SET payment_status = :payment_status,
                    certificate_number = :certificate_number,
                    certificate_data = :certificate_data, notes = :notes,
                    updated_at = :updated_at
             WHERE id = :id AND committee_id = :committee_id AND deleted_at IS NULL'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO winners (
                committee_id, member_id, name, month_num, draw_number,
                shares_won, amount, winner_date, status, payment_status,
                certificate_number, certificate_data, notes, declared_at,
                updated_at, deleted_at, deleted_by
             ) VALUES (
                :committee_id, :member_id, :name, :month_num, :draw_number,
                :shares_won, :amount, :winner_date, :status, :payment_status,
                :certificate_number, :certificate_data, :notes, :declared_at,
                :updated_at, NULL, NULL
             )'
        );

        foreach ($incomingWinners as $winner) {
            if (!is_array($winner)) {
                throw new InvalidArgumentException('Invalid winner payload.', 422);
            }
            $winnerId = (int) ($winner['id'] ?? 0);
            if ($winnerId > 0) {
                if (!isset($existing[$winnerId])) {
                    throw new InvalidArgumentException('Winner does not belong to this committee.', 422);
                }
                $retainedIds[$winnerId] = true;
                $paymentStatus = $this->validateStatus(
                    (string) ($winner['paymentStatus'] ?? $existing[$winnerId]['paymentStatus']),
                    self::paymentStatuses(),
                    'winner payment status'
                );
                $update->execute([
                    'payment_status' => $paymentStatus,
                    'certificate_number' => trim((string) ($winner['certificateNumber'] ?? $existing[$winnerId]['certificateNumber'])),
                    'certificate_data' => $this->encodeObject($winner['certificate'] ?? $existing[$winnerId]['certificate']),
                    'notes' => trim((string) ($winner['notes'] ?? $existing[$winnerId]['notes'])),
                    'updated_at' => $now,
                    'id' => $winnerId,
                    'committee_id' => $committeeId,
                ]);
                continue;
            }

            $validated = $this->validateNewWinner($committeeId, $winner, $settings);
            $signature = $this->winnerSignature($validated);
            if (isset($signatures[$signature])) {
                // A queued retry of the same aggregate save must not duplicate a win.
                $retainedIds[$signatures[$signature]] = true;
                continue;
            }
            $insert->execute([
                'committee_id' => $committeeId,
                'member_id' => $validated['memberId'],
                'name' => $validated['name'],
                'month_num' => $validated['month'],
                'draw_number' => $validated['drawNumber'],
                'shares_won' => $validated['sharesWon'],
                'amount' => $validated['amount'],
                'winner_date' => $validated['date'],
                'status' => 'declared',
                'payment_status' => $validated['paymentStatus'],
                'certificate_number' => $validated['certificateNumber'],
                'certificate_data' => $this->encodeObject($validated['certificate']),
                'notes' => $validated['notes'],
                'declared_at' => $now,
                'updated_at' => $now,
            ]);
            $newId = (int) $this->pdo->lastInsertId();
            $retainedIds[$newId] = true;
            $signatures[$signature] = $newId;
            $this->writeAudit(
                $committeeId,
                $user,
                'Winner Declared',
                'winner',
                (string) $newId,
                null,
                $validated
            );
            $this->createNotification(
                $committeeId,
                (int) $validated['memberId'],
                'winner_selected',
                'Winner Selected',
                sprintf('%s was selected as a winner for draw #%d.', $validated['name'], $validated['drawNumber']),
                $now
            );
        }

        $void = $this->pdo->prepare(
            "UPDATE winners SET status = 'voided', deleted_at = :deleted_at,
                    deleted_by = :deleted_by, updated_at = :updated_at
             WHERE id = :id AND committee_id = :committee_id AND deleted_at IS NULL"
        );
        foreach ($existing as $id => $winner) {
            if (isset($retainedIds[$id])) {
                continue;
            }
            $void->execute([
                'deleted_at' => $now,
                'deleted_by' => $this->actorName($user),
                'updated_at' => $now,
                'id' => $id,
                'committee_id' => $committeeId,
            ]);
            $this->writeAudit(
                $committeeId,
                $user,
                'Winner Voided',
                'winner',
                (string) $id,
                $winner,
                ['status' => 'voided']
            );
        }
    }

    private function validateNewWinner(int $committeeId, array $winner, array $settings): array
    {
        $memberId = (int) ($winner['memberId'] ?? 0);
        $stmt = $this->pdo->prepare(
            'SELECT id, name, shares FROM members
             WHERE id = :id AND committee_id = :committee_id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $memberId, 'committee_id' => $committeeId]);
        $member = $stmt->fetch();
        if (!$member) {
            throw new InvalidArgumentException('Winner must be an active member of this committee.', 422);
        }

        [$committee] = $this->loadCommitteeRecord($committeeId);

        $month = $this->positiveInt($winner['month'] ?? 0, 'winner month', 1, (int) $settings['totalMonths']);
        $sharesWon = $this->positiveInt($winner['sharesWon'] ?? 1, 'shares won', 1, 1000000);
        $drawNumber = $this->positiveInt($winner['drawNumber'] ?? $month, 'draw number', 1, 1000000);
        $amount = $this->nonNegativeMoney(
            $winner['amount'] ?? ((float) $settings['prizePerShare'] * $sharesWon),
            'winner amount'
        );
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException('Winner amount must be greater than zero.', 422);
        }
        $winnerDate = $this->dateOrNull($winner['date'] ?? $winner['winnerDate'] ?? null);
        if ($winnerDate === null) {
            $base = $committee['startDate'] ?: (new \DateTimeImmutable('now'))->format('Y-m-01');
            $monthDate = (new \DateTimeImmutable($base))->modify('+' . max(0, $month - 1) . ' months');
            $winnerDate = $monthDate->setDate((int) $monthDate->format('Y'), (int) $monthDate->format('m'), 20)->format('Y-m-d');
        }

        $rules = $settings['winnerRules'];
        $this->assertMonthOpen($committeeId, $month);
        if (!empty($rules['winnerDeclarationLock'])) {
            throw new RuntimeException('Winner declaration is locked in committee settings.', 409);
        }
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(shares_won), 0) AS member_shares,
                    COALESCE(SUM(CASE WHEN month_num = :month_a THEN shares_won ELSE 0 END), 0) AS month_shares,
                    COALESCE(SUM(CASE WHEN month_num = :month_b THEN amount ELSE 0 END), 0) AS month_amount,
                    COUNT(DISTINCT CASE WHEN month_num = :month_c THEN member_id END) AS month_winners,
                    COUNT(CASE WHEN month_num = :month_d THEN 1 END) AS month_entries
             FROM winners
             WHERE committee_id = :committee_id AND deleted_at IS NULL
               AND status <> 'voided' AND (member_id = :member_id OR month_num = :month_e)"
        );
        $stmt->execute([
            'month_a' => $month,
            'month_b' => $month,
            'month_c' => $month,
            'month_d' => $month,
            'committee_id' => $committeeId,
            'member_id' => $memberId,
            'month_e' => $month,
        ]);
        $stats = $stmt->fetch();
        $alreadyWon = (int) ($stats['member_shares'] ?? 0);
        if ($alreadyWon + $sharesWon > (int) $member['shares']) {
            throw new InvalidArgumentException('Winner shares exceed the member\'s remaining shares.', 422);
        }
        $maxMember = (int) ($rules['maxWinsPerMember'] ?? 0);
        if ($maxMember > 0 && $alreadyWon + $sharesWon > $maxMember) {
            throw new InvalidArgumentException('Winner exceeds the configured maximum wins per member.', 422);
        }
        $maxMonthShares = (int) ($rules['maxSharesPerMonth'] ?? 0);
        if ($maxMonthShares > 0 && (int) $stats['month_shares'] + $sharesWon > $maxMonthShares) {
            throw new InvalidArgumentException('Winner exceeds the configured maximum shares per draw.', 422);
        }
        $maxPrize = (float) ($rules['maxPrizePerMonth'] ?? 0);
        if ($maxPrize > 0 && (float) $stats['month_amount'] + (float) $amount > $maxPrize) {
            throw new InvalidArgumentException('Winner exceeds the configured maximum prize per draw.', 422);
        }
        if (($rules['allowMultipleWinners'] ?? true) === false && (int) $stats['month_entries'] > 0) {
            throw new InvalidArgumentException('Multiple winners are disabled for this draw.', 422);
        }
        $maxWinners = (int) ($rules['maxWinnersPerMonth'] ?? 0);
        if ($maxWinners > 0 && (int) $stats['month_winners'] >= $maxWinners) {
            throw new InvalidArgumentException('The draw already has the maximum number of winners.', 422);
        }
        $minPayments = (int) ($rules['minPaymentBeforeWinning'] ?? 0);
        if ($minPayments > 0) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM payments
                 WHERE committee_id = :committee_id AND member_id = :member_id
                   AND status IN ('paid', 'late')"
            );
            $stmt->execute(['committee_id' => $committeeId, 'member_id' => $memberId]);
            if ((int) $stmt->fetchColumn() < $minPayments) {
                throw new InvalidArgumentException('Member has not paid the minimum installments required to win.', 422);
            }
        }

        return [
            'memberId' => $memberId,
            'name' => (string) $member['name'],
            'month' => $month,
            'drawNumber' => $drawNumber,
            'sharesWon' => $sharesWon,
            'amount' => $amount,
            'date' => $winnerDate,
            'status' => 'declared',
            'paymentStatus' => $this->validateStatus(
                (string) ($winner['paymentStatus'] ?? 'paid'),
                self::paymentStatuses(),
                'winner payment status'
            ),
            'certificateNumber' => trim((string) ($winner['certificateNumber'] ?? '')),
            'certificate' => $this->arrayValue($winner['certificate'] ?? []),
            'notes' => trim((string) ($winner['notes'] ?? '')),
        ];
    }

    private function assertMonthOpen(int $committeeId, int $month): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT status FROM committee_month_closures WHERE committee_id = :committee_id AND month_num = :month_num LIMIT 1"
        );
        $stmt->execute(['committee_id' => $committeeId, 'month_num' => $month]);
        if ((string) $stmt->fetchColumn() === 'closed') {
            throw new RuntimeException('This month is closed. Reopen it before changing payments or winners.', 409);
        }
    }

    private function saveEvents(int $committeeId, array $events, array $user, int $now): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM committee_events
             WHERE committee_id = :committee_id AND deleted_at IS NULL'
        );
        $stmt->execute(['committee_id' => $committeeId]);
        $existingIds = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $retained = [];
        $upsert = $this->pdo->prepare(
            'INSERT INTO committee_events (
                id, committee_id, type, title, description, start_at, end_at,
                status, created_by, created_at, updated_at, deleted_at
             ) VALUES (
                :id, :committee_id, :type, :title, :description, :start_at,
                :end_at, :status, :created_by, :created_at, :updated_at, NULL
             )
             ON DUPLICATE KEY UPDATE
                type = VALUES(type), title = VALUES(title),
                description = VALUES(description), start_at = VALUES(start_at),
                end_at = VALUES(end_at), status = VALUES(status),
                updated_at = VALUES(updated_at), deleted_at = NULL'
        );
        foreach ($events as $event) {
            if (!is_array($event)) {
                throw new InvalidArgumentException('Invalid calendar event.', 422);
            }
            $title = trim((string) ($event['title'] ?? ''));
            $startAt = (int) ($event['startAt'] ?? 0);
            if ($title === '' || $startAt <= 0) {
                throw new InvalidArgumentException('Calendar events require a title and start time.', 422);
            }
            $id = (int) ($event['id'] ?? 0);
            if ($id > 0 && !isset($existingIds[$id])) {
                throw new InvalidArgumentException('Calendar event does not belong to this committee.', 422);
            }
            $upsert->execute([
                'id' => $id > 0 ? $id : null,
                'committee_id' => $committeeId,
                'type' => $this->validateStatus(
                    (string) ($event['type'] ?? 'announcement'),
                    self::eventTypes(),
                    'event type'
                ),
                'title' => $title,
                'description' => trim((string) ($event['description'] ?? '')),
                'start_at' => $startAt,
                'end_at' => !empty($event['endAt']) ? (int) $event['endAt'] : null,
                'status' => $this->validateStatus(
                    (string) ($event['status'] ?? 'scheduled'),
                    ['scheduled', 'completed', 'cancelled'],
                    'event status'
                ),
                'created_by' => $this->actorName($user),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $retained[$id > 0 ? $id : (int) $this->pdo->lastInsertId()] = true;
        }

        $softDelete = $this->pdo->prepare(
            'UPDATE committee_events SET deleted_at = :deleted_at, updated_at = :updated_at
             WHERE id = :id AND committee_id = :committee_id'
        );
        foreach ($existingIds as $id => $_) {
            if (!isset($retained[$id])) {
                $softDelete->execute([
                    'deleted_at' => $now,
                    'updated_at' => $now,
                    'id' => $id,
                    'committee_id' => $committeeId,
                ]);
            }
        }
        $this->writeAudit(
            $committeeId,
            $user,
            'Calendar Updated',
            'committee_event',
            '',
            ['count' => count($existingIds)],
            ['count' => count($events)]
        );
    }

    private function updateLegacyMirror(
        int $committeeId,
        array $committee,
        array $settings,
        array $data,
        int $now
    ): void {
        if ($committeeId !== $this->defaultCommitteeId()) {
            return;
        }
        $row = $this->pdo->query(
            'SELECT committee_rules FROM settings WHERE id = 1 LIMIT 1'
        )->fetch();
        $envelope = $this->decodeObject($row['committee_rules'] ?? null);
        $envelope['rules'] = $settings['winnerRules'];
        if (!isset($envelope['auditLog']) || !is_array($envelope['auditLog'])) {
            $envelope['auditLog'] = [];
        }
        $stmt = $this->pdo->prepare(
            'UPDATE settings SET
                committee_name = :committee_name,
                committee_subtitle = :committee_subtitle,
                admin_email = :admin_email,
                recovery_contact = :recovery_contact,
                recovery_note = :recovery_note,
                amt_per_share = :amt_per_share,
                total_months = :total_months,
                prize_per_share = :prize_per_share,
                start_month = :start_month,
                current_month = :current_month,
                next_id = :next_id,
                committee_rules = :committee_rules,
                updated_at = :updated_at
             WHERE id = 1'
        );
        $global = $this->loadGlobalSettings();
        $stmt->execute([
            'committee_name' => $committee['name'],
            'committee_subtitle' => $settings['subtitle'],
            'admin_email' => strtolower(trim((string) ($data['adminEmail'] ?? $global['admin_email']))),
            'recovery_contact' => trim((string) ($data['recoveryContact'] ?? $global['recovery_contact'])),
            'recovery_note' => trim((string) ($data['recoveryNote'] ?? $global['recovery_note'])),
            'amt_per_share' => (int) round((float) $committee['installmentAmount']),
            'total_months' => $settings['totalMonths'],
            'prize_per_share' => (int) round((float) $settings['prizePerShare']),
            'start_month' => $committee['startDate'] ? substr($committee['startDate'], 0, 7) : '',
            'current_month' => $settings['currentMonth'],
            'next_id' => $this->nextMemberId(),
            'committee_rules' => json_encode($envelope, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);
    }

    public function saveMemberSelf(int $committeeId, int $memberId, array $data, array $user): array
    {
        if (($user['role'] ?? '') !== 'user' || (int) ($user['memberId'] ?? 0) !== $memberId) {
            throw new RuntimeException('Member access required.', 403);
        }
        $committeeId = $this->resolveCommitteeId($committeeId, $user, true);
        $incoming = null;
        foreach ((array) ($data['members'] ?? []) as $member) {
            if (is_array($member) && (int) ($member['id'] ?? 0) === $memberId) {
                $incoming = $member;
                break;
            }
        }
        if ($incoming === null) {
            throw new InvalidArgumentException('Member payload missing.', 422);
        }

        $now = $this->now();
        $this->pdo->beginTransaction();
        try {
            [$committee] = $this->loadCommitteeRecord($committeeId, true);
            $this->assertVersion($committee, isset($data['version']) ? (int) $data['version'] : null);
            $sets = ['pref_month = :pref_month', 'updated_at = :updated_at'];
            $params = [
                'pref_month' => max(0, (int) ($incoming['prefMonth'] ?? 0)),
                'updated_at' => $now,
                'id' => $memberId,
                'committee_id' => $committeeId,
            ];
            $pin = trim((string) ($incoming['pin'] ?? ''));
            if ($pin !== '') {
                if (!ctype_digit($pin) || strlen($pin) !== 4) {
                    throw new InvalidArgumentException('PIN must be 4 digits.', 422);
                }
                $sets[] = 'pin_hash = :pin_hash';
                $params['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE members SET ' . implode(', ', $sets) . '
                 WHERE id = :id AND committee_id = :committee_id AND deleted_at IS NULL'
            );
            $stmt->execute($params);
            if ($stmt->rowCount() === 0) {
                $check = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM members
                     WHERE id = :id AND committee_id = :committee_id AND deleted_at IS NULL'
                );
                $check->execute(['id' => $memberId, 'committee_id' => $committeeId]);
                if ((int) $check->fetchColumn() === 0) {
                    throw new RuntimeException('Member not found.', 404);
                }
            }
            $this->pdo->prepare(
                'UPDATE committees SET version = version + 1, updated_at = :now WHERE id = :id'
            )->execute(['now' => $now, 'id' => $committeeId]);
            $this->writeAudit(
                $committeeId,
                $user,
                $pin !== '' ? 'Member Security Updated' : 'Member Preference Updated',
                'member',
                (string) $memberId,
                null,
                ['preferredMonth' => $params['pref_month'], 'pinChanged' => $pin !== '']
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    /** Used by the existing authenticated change-member-pin action. */
    public function updateMemberPin(int $memberId, string $newPin): void
    {
        if (strlen($newPin) !== 4 || !ctype_digit($newPin)) {
            throw new InvalidArgumentException('PIN must be 4 digits.', 422);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE members SET pin_hash = :pin_hash, updated_at = :updated_at
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            'pin_hash' => password_hash($newPin, PASSWORD_DEFAULT),
            'updated_at' => $this->now(),
            'id' => $memberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Member not found.', 404);
        }
    }

    public function permanentDeleteMembers(int $committeeId, array $memberIds, array $user): array
    {
        $this->requireAdmin($user);
        $committeeId = $this->resolveCommitteeId($committeeId, $user, true);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $memberIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return $this->loadData($committeeId, $user);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $params = array_merge([$committeeId], $ids);
            // Winner rows keep their committee/name snapshot. ON DELETE SET NULL
            // severs only the optional member identity reference.
            $stmt = $this->pdo->prepare(
                "DELETE FROM members
                 WHERE committee_id = ? AND deleted_at IS NOT NULL
                   AND id IN ({$placeholders})"
            );
            $stmt->execute($params);
            $deleted = $stmt->rowCount();
            if ($deleted > 0) {
                $this->pdo->prepare(
                    'UPDATE committees SET version = version + 1, updated_at = :now WHERE id = :id'
                )->execute(['now' => $now, 'id' => $committeeId]);
                $this->writeAudit(
                    $committeeId,
                    $user,
                    'Member Permanently Deleted',
                    'member',
                    '',
                    ['memberIds' => $ids],
                    ['deletedCount' => $deleted, 'winnerHistoryPreserved' => true]
                );
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    public function resetCommittee(int $committeeId, array $user, ?int $expectedVersion = null): array
    {
        $this->requireAdmin($user);
        $committeeId = $this->resolveCommitteeId($committeeId, $user, true);
        $now = $this->now();
        $actor = $this->actorName($user);
        $this->pdo->beginTransaction();
        try {
            [$committee] = $this->loadCommitteeRecord($committeeId, true);
            $this->assertVersion($committee, $expectedVersion);
            $stmt = $this->pdo->prepare(
                'UPDATE members SET deleted_at = COALESCE(deleted_at, :deleted_at),
                        deleted_by = COALESCE(deleted_by, :deleted_by), updated_at = :updated_at
                 WHERE committee_id = :committee_id'
            );
            $stmt->execute([
                'deleted_at' => $now,
                'deleted_by' => $actor,
                'updated_at' => $now,
                'committee_id' => $committeeId,
            ]);
            $membersAffected = $stmt->rowCount();
            $this->pdo->prepare(
                "UPDATE payments SET status = 'pending', amount_paid = 0,
                        paid_at = NULL, updated_at = :now
                 WHERE committee_id = :committee_id"
            )->execute(['now' => $now, 'committee_id' => $committeeId]);
            $this->pdo->prepare(
                "UPDATE winners SET status = 'voided', deleted_at = COALESCE(deleted_at, :now),
                        deleted_by = COALESCE(deleted_by, :actor), updated_at = :updated_at
                 WHERE committee_id = :committee_id"
            )->execute([
                'now' => $now,
                'actor' => $actor,
                'updated_at' => $now,
                'committee_id' => $committeeId,
            ]);
            $this->pdo->prepare(
                'UPDATE committee_events SET deleted_at = COALESCE(deleted_at, :now),
                        updated_at = :updated_at WHERE committee_id = :committee_id'
            )->execute(['now' => $now, 'updated_at' => $now, 'committee_id' => $committeeId]);
            $this->pdo->prepare(
                'UPDATE committees SET version = version + 1, updated_at = :now WHERE id = :id'
            )->execute(['now' => $now, 'id' => $committeeId]);
            $this->writeAudit(
                $committeeId,
                $user,
                'Committee Reset',
                'committee',
                (string) $committeeId,
                ['activeMembersAffected' => $membersAffected],
                ['winnerHistoryPreserved' => true]
            );
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->loadData($committeeId, $user);
    }

    public function markNotificationRead(int $committeeId, int $notificationId, array $user): array
    {
        $committeeId = $this->resolveCommitteeId($committeeId, $user);
        $params = [
            'read_at' => $this->now(),
            'id' => $notificationId,
            'committee_id' => $committeeId,
        ];
        $scope = '';
        if (($user['role'] ?? '') === 'user') {
            $scope = ' AND member_id = :member_id';
            $params['member_id'] = (int) ($user['memberId'] ?? 0);
        }
        $stmt = $this->pdo->prepare(
            "UPDATE notification_logs SET read_at = COALESCE(read_at, :read_at)
             WHERE id = :id AND committee_id = :committee_id {$scope}"
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM notification_logs
                 WHERE id = :id AND committee_id = :committee_id'
            );
            $check->execute(['id' => $notificationId, 'committee_id' => $committeeId]);
            if ((int) $check->fetchColumn() === 0) {
                throw new RuntimeException('Notification not found.', 404);
            }
        }

        return $this->loadNotifications($committeeId, $user);
    }

    public function logReportExport(
        int $committeeId,
        string $reportType,
        string $format,
        array $filters,
        array $user
    ): void {
        $committeeId = $this->resolveCommitteeId($committeeId, $user);
        $reportType = $this->reportType($reportType);
        $format = strtolower(trim($format));
        if (!in_array($format, ['pdf', 'excel', 'xlsx', 'csv', 'print'], true)) {
            throw new InvalidArgumentException('Unsupported report export format.', 422);
        }
        $this->writeAudit(
            $committeeId,
            $user,
            'Report Exported',
            'report',
            $reportType,
            null,
            ['format' => $format, 'filters' => $filters]
        );
    }

    private function createNotification(
        int $committeeId,
        ?int $memberId,
        string $type,
        string $title,
        string $message,
        int $now
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notification_logs (
                committee_id, member_id, channel, type, title, message,
                status, recipient, sent_at, read_at, created_at
             ) VALUES (
                :committee_id, :member_id, 'in_app', :type, :title, :message,
                'sent', '', :sent_at, NULL, :created_at
             )"
        );
        $stmt->execute([
            'committee_id' => $committeeId,
            'member_id' => $memberId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'sent_at' => $now,
            'created_at' => $now,
        ]);
    }

    private function writeAudit(
        ?int $committeeId,
        array $user,
        string $action,
        string $entityType,
        string $entityId,
        ?array $before,
        ?array $after
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (
                committee_id, actor_role, actor_member_id, actor_name, action,
                entity_type, entity_id, before_data, after_data, ip,
                user_agent, created_at
             ) VALUES (
                :committee_id, :actor_role, :actor_member_id, :actor_name, :action,
                :entity_type, :entity_id, :before_data, :after_data, :ip,
                :user_agent, :created_at
             )'
        );
        $stmt->execute([
            'committee_id' => $committeeId,
            'actor_role' => (string) ($user['role'] ?? 'system'),
            'actor_member_id' => !empty($user['memberId']) ? (int) $user['memberId'] : null,
            'actor_name' => $this->actorName($user),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_data' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'created_at' => $this->now(),
        ]);
    }

    public function getReport(int $committeeId, string $type, array $options, array $user): array
    {
        $committeeId = $this->resolveCommitteeId($committeeId, $user);
        $type = $this->reportType($type);
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($options['perPage'] ?? 25)));
        $search = trim((string) ($options['search'] ?? ''));
        $month = max(0, (int) ($options['month'] ?? 0));
        $status = trim((string) ($options['status'] ?? 'all'));
        $sort = trim((string) ($options['sort'] ?? ''));
        $memberScope = ($user['role'] ?? '') === 'user' ? (int) ($user['memberId'] ?? 0) : null;

        $result = match ($type) {
            'committee-summary' => $this->summaryReport($committeeId, $memberScope),
            'member' => $this->memberReport($committeeId, $memberScope, $page, $perPage, $search, $status, $sort),
            'payment', 'collection' => $this->paymentReport(
                $committeeId,
                $memberScope,
                $page,
                $perPage,
                $search,
                $month,
                $status,
                $sort,
                $type === 'collection'
            ),
            'pending' => $this->pendingReport($committeeId, $memberScope, $page, $perPage, $search, $month, $sort),
            'winner' => $this->winnerReport($committeeId, $memberScope, $page, $perPage, $search, $month, $status, $sort),
        };

        return [
            'committeeId' => $committeeId,
            'type' => $type,
            'filters' => [
                'search' => $search,
                'month' => $month,
                'status' => $status,
                'sort' => $sort,
            ],
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'pagination' => $result['pagination'],
            'generatedAt' => $this->now(),
        ];
    }

    private function summaryReport(int $committeeId, ?int $memberScope): array
    {
        [$committee, $settings] = $this->loadCommitteeRecord($committeeId);
        $summary = $this->portfolioAggregates([$committeeId], $memberScope)[$committeeId];
        $memberFilter = $memberScope !== null ? ' AND m.id = ?' : '';
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(
                 GREATEST(p.amount_due - p.amount_paid, 0)
             ), 0) AS outstanding,
             COALESCE(SUM(CASE WHEN p.status = 'late'
                               THEN GREATEST(p.amount_due - p.amount_paid, 0) ELSE 0 END), 0) AS late
             FROM payments p
             JOIN members m ON m.id = p.member_id AND m.committee_id = p.committee_id
             WHERE p.committee_id = ? {$memberFilter}"
        );
        $params = [$committeeId];
        if ($memberScope !== null) {
            $params[] = $memberScope;
        }
        $stmt->execute($params);
        $financial = $stmt->fetch() ?: ['outstanding' => 0, 'late' => 0];
        $row = [
            'committee' => $committee,
            'settings' => $settings,
            'totalMembers' => (int) $summary['members'],
            'totalCollection' => $this->number($summary['collection']),
            'paidInstallments' => (int) $summary['paidInstallments'],
            'pendingAmount' => $this->number($financial['outstanding']),
            'lateAmount' => $this->number($financial['late']),
            'totalWinners' => (int) $summary['winners'],
        ];

        return [
            'rows' => [$row],
            'totals' => $row,
            'pagination' => $this->pagination(1, 1, 1),
        ];
    }

    private function memberReport(
        int $committeeId,
        ?int $memberScope,
        int $page,
        int $perPage,
        string $search,
        string $status,
        string $sort
    ): array {
        $where = ['m.committee_id = ?'];
        $params = [$committeeId];
        if ($memberScope !== null) {
            $where[] = 'm.id = ?';
            $params[] = $memberScope;
        }
        if ($search !== '') {
            $where[] = '(LOWER(m.name) LIKE ? OR CAST(m.id AS CHAR) LIKE ?)';
            $needle = '%' . strtolower($search) . '%';
            $params[] = $needle;
            $params[] = '%' . $search . '%';
        }
        if ($status === 'active' || $status === 'all' || $status === '') {
            if ($status !== 'all') {
                $where[] = 'm.deleted_at IS NULL';
            }
        } elseif ($status === 'deleted') {
            $where[] = 'm.deleted_at IS NOT NULL';
        } else {
            throw new InvalidArgumentException('Unsupported member status filter.', 422);
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM members m WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sortSql = match ($sort) {
            'name', 'name-asc' => 'm.name ASC, m.id ASC',
            'name-desc' => 'm.name DESC, m.id DESC',
            'shares', 'shares-desc' => 'm.shares DESC, m.id ASC',
            'shares-asc' => 'm.shares ASC, m.id ASC',
            default => 'm.id ASC',
        };
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT m.id, m.name, m.shares, m.phone, m.email, m.photo,
                       m.pref_month, m.deleted_at,
                       COALESCE(pa.paid_installments, 0) AS paid_installments,
                       COALESCE(pa.amount_paid, 0) AS amount_paid,
                       COALESCE(wa.shares_won, 0) AS shares_won,
                       COALESCE(wa.prize_won, 0) AS prize_won
                FROM members m
                LEFT JOIN (
                    SELECT member_id, COUNT(CASE WHEN status IN ('paid', 'late') THEN 1 END) AS paid_installments,
                           SUM(amount_paid) AS amount_paid
                    FROM payments WHERE committee_id = ? GROUP BY member_id
                ) pa ON pa.member_id = m.id
                LEFT JOIN (
                    SELECT member_id, SUM(shares_won) AS shares_won, SUM(amount) AS prize_won
                    FROM winners WHERE committee_id = ? AND deleted_at IS NULL
                      AND status <> 'voided' GROUP BY member_id
                ) wa ON wa.member_id = m.id
                WHERE {$whereSql}
                ORDER BY {$sortSql} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$committeeId, $committeeId], $params));
        $rows = array_map(function (array $row): array {
            return [
                'memberId' => (int) $row['id'],
                'name' => (string) $row['name'],
                'shares' => (int) $row['shares'],
                'phone' => (string) $row['phone'],
                'email' => (string) $row['email'],
                'photo' => (string) $row['photo'],
                'preferredMonth' => (int) $row['pref_month'],
                'status' => $row['deleted_at'] === null ? 'active' : 'deleted',
                'paidInstallments' => (int) $row['paid_installments'],
                'amountPaid' => $this->number($row['amount_paid']),
                'sharesWon' => (int) $row['shares_won'],
                'prizeWon' => $this->number($row['prize_won']),
            ];
        }, $stmt->fetchAll());

        return [
            'rows' => $rows,
            'totals' => ['members' => $total],
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function paymentReport(
        int $committeeId,
        ?int $memberScope,
        int $page,
        int $perPage,
        string $search,
        int $month,
        string $status,
        string $sort,
        bool $collectionsOnly
    ): array {
        $where = ['p.committee_id = ?'];
        $params = [$committeeId];
        if ($memberScope !== null) {
            $where[] = 'p.member_id = ?';
            $params[] = $memberScope;
        }
        if ($search !== '') {
            $where[] = '(LOWER(m.name) LIKE ? OR CAST(m.id AS CHAR) LIKE ? OR LOWER(p.reference_no) LIKE ?)';
            $needle = '%' . strtolower($search) . '%';
            array_push($params, $needle, '%' . $search . '%', $needle);
        }
        if ($month > 0) {
            $where[] = 'p.month_num = ?';
            $params[] = $month;
        }
        if ($collectionsOnly) {
            $where[] = "p.status IN ('paid', 'late')";
        } elseif ($status !== '' && $status !== 'all') {
            $where[] = 'p.status = ?';
            $params[] = $this->validateStatus($status, self::installmentStatuses(), 'payment status');
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM payments p JOIN members m ON m.id = p.member_id WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sortSql = match ($sort) {
            'name', 'name-asc' => 'm.name ASC, p.month_num ASC',
            'name-desc' => 'm.name DESC, p.month_num DESC',
            'amount', 'amount-desc' => 'p.amount_paid DESC, p.month_num DESC',
            'amount-asc' => 'p.amount_paid ASC, p.month_num ASC',
            'month-desc' => 'p.month_num DESC, m.id ASC',
            default => 'p.month_num ASC, m.id ASC',
        };
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT p.member_id, m.name, p.month_num, p.amount_due, p.amount_paid,
                    p.status, p.due_at, p.paid_at, p.payment_method,
                    p.reference_no, p.notes, p.updated_at
             FROM payments p JOIN members m ON m.id = p.member_id
             WHERE {$whereSql}
             ORDER BY {$sortSql} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = array_map(function (array $row): array {
            return [
                'memberId' => (int) $row['member_id'],
                'memberName' => (string) $row['name'],
                'month' => (int) $row['month_num'],
                'amountDue' => $this->number($row['amount_due']),
                'amountPaid' => $this->number($row['amount_paid']),
                'outstanding' => $this->moneyValue(max(0, (float) $row['amount_due'] - (float) $row['amount_paid'])),
                'status' => (string) $row['status'],
                'dueAt' => $row['due_at'] !== null ? (int) $row['due_at'] : null,
                'paidAt' => $row['paid_at'] !== null ? (int) $row['paid_at'] : null,
                'paymentMethod' => (string) $row['payment_method'],
                'referenceNo' => (string) $row['reference_no'],
                'notes' => (string) ($row['notes'] ?? ''),
                'updatedAt' => (int) $row['updated_at'],
            ];
        }, $stmt->fetchAll());

        $totals = $this->pdo->prepare(
            "SELECT COALESCE(SUM(p.amount_due), 0) AS due,
                    COALESCE(SUM(p.amount_paid), 0) AS paid,
                    COALESCE(SUM(GREATEST(p.amount_due - p.amount_paid, 0)), 0) AS outstanding
             FROM payments p JOIN members m ON m.id = p.member_id WHERE {$whereSql}"
        );
        $totals->execute($params);
        $sum = $totals->fetch() ?: ['due' => 0, 'paid' => 0, 'outstanding' => 0];

        return [
            'rows' => $rows,
            'totals' => [
                'records' => $total,
                'amountDue' => $this->number($sum['due']),
                'amountPaid' => $this->number($sum['paid']),
                'outstanding' => $this->number($sum['outstanding']),
            ],
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function pendingReport(
        int $committeeId,
        ?int $memberScope,
        int $page,
        int $perPage,
        string $search,
        int $month,
        string $sort
    ): array {
        if ($month <= 0) {
            $stmt = $this->pdo->prepare(
                'SELECT current_month FROM committee_settings WHERE committee_id = :committee_id'
            );
            $stmt->execute(['committee_id' => $committeeId]);
            $month = max(1, (int) $stmt->fetchColumn());
        }
        $where = [
            'm.committee_id = ?',
            'm.deleted_at IS NULL',
            "(p.member_id IS NULL OR p.status NOT IN ('paid', 'late') OR p.amount_paid < p.amount_due)",
        ];
        $params = [$committeeId];
        if ($memberScope !== null) {
            $where[] = 'm.id = ?';
            $params[] = $memberScope;
        }
        if ($search !== '') {
            $where[] = '(LOWER(m.name) LIKE ? OR CAST(m.id AS CHAR) LIKE ?)';
            $params[] = '%' . strtolower($search) . '%';
            $params[] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);
        $join = 'LEFT JOIN payments p
                   ON p.committee_id = m.committee_id AND p.member_id = m.id
                  AND p.month_num = ?';
        $queryParams = array_merge([$month], $params);
        $count = $this->pdo->prepare(
            "SELECT COUNT(*) FROM members m {$join} WHERE {$whereSql}"
        );
        $count->execute($queryParams);
        $total = (int) $count->fetchColumn();
        $sortSql = match ($sort) {
            'name', 'name-asc' => 'm.name ASC, m.id ASC',
            'name-desc' => 'm.name DESC, m.id DESC',
            'amount', 'amount-desc' => 'outstanding DESC, m.id ASC',
            'amount-asc' => 'outstanding ASC, m.id ASC',
            default => 'm.id ASC',
        };
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.name, m.shares, ? AS month_num,
                    COALESCE(NULLIF(p.amount_due, 0), m.shares * c.installment_amount) AS amount_due,
                    COALESCE(p.amount_paid, 0) AS amount_paid,
                    COALESCE(p.status, 'pending') AS status,
                    GREATEST(
                        COALESCE(NULLIF(p.amount_due, 0), m.shares * c.installment_amount)
                        - COALESCE(p.amount_paid, 0),
                        0
                    ) AS outstanding,
                    p.due_at
             FROM members m
             JOIN committees c ON c.id = m.committee_id
             {$join}
             WHERE {$whereSql}
             ORDER BY {$sortSql} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute(array_merge([$month], $queryParams));
        $rows = array_map(function (array $row): array {
            return [
                'memberId' => (int) $row['id'],
                'memberName' => (string) $row['name'],
                'shares' => (int) $row['shares'],
                'month' => (int) $row['month_num'],
                'amountDue' => $this->number($row['amount_due']),
                'amountPaid' => $this->number($row['amount_paid']),
                'outstanding' => $this->number($row['outstanding']),
                'status' => (string) $row['status'],
                'dueAt' => $row['due_at'] !== null ? (int) $row['due_at'] : null,
            ];
        }, $stmt->fetchAll());
        $totalStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(GREATEST(
                        COALESCE(NULLIF(p.amount_due, 0), m.shares * c.installment_amount)
                        - COALESCE(p.amount_paid, 0), 0)), 0)
             FROM members m
             JOIN committees c ON c.id = m.committee_id
             {$join}
             WHERE {$whereSql}"
        );
        $totalStmt->execute($queryParams);
        $outstanding = $this->number($totalStmt->fetchColumn());

        return [
            'rows' => $rows,
            'totals' => ['records' => $total, 'outstanding' => $outstanding],
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function winnerReport(
        int $committeeId,
        ?int $memberScope,
        int $page,
        int $perPage,
        string $search,
        int $month,
        string $status,
        string $sort
    ): array {
        $where = ['w.committee_id = ?', 'w.deleted_at IS NULL'];
        $params = [$committeeId];
        if ($memberScope !== null) {
            $where[] = 'w.member_id = ?';
            $params[] = $memberScope;
        }
        if ($search !== '') {
            $where[] = '(LOWER(w.name) LIKE ? OR CAST(w.id AS CHAR) LIKE ? OR w.certificate_number LIKE ?)';
            $needle = '%' . strtolower($search) . '%';
            array_push($params, $needle, '%' . $search . '%', '%' . $search . '%');
        }
        if ($month > 0) {
            $where[] = 'w.month_num = ?';
            $params[] = $month;
        }
        if ($status !== '' && $status !== 'all') {
            if (str_starts_with($status, 'payment:')) {
                $where[] = "w.status <> 'voided'";
                $where[] = 'w.payment_status = ?';
                $params[] = $this->validateStatus(
                    substr($status, 8),
                    self::paymentStatuses(),
                    'winner payment status'
                );
            } else {
                $where[] = 'w.status = ?';
                $params[] = $this->validateStatus($status, self::winnerStatuses(), 'winner status');
            }
        } else {
            $where[] = "w.status <> 'voided'";
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM winners w WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sortSql = match ($sort) {
            'name', 'name-asc' => 'w.name ASC, w.id ASC',
            'name-desc' => 'w.name DESC, w.id DESC',
            'amount', 'amount-desc' => 'w.amount DESC, w.id DESC',
            'amount-asc' => 'w.amount ASC, w.id ASC',
            'date', 'date-desc', 'month-desc' => 'w.month_num DESC, w.draw_number DESC, w.id DESC',
            default => 'w.month_num ASC, w.draw_number ASC, w.id ASC',
        };
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            "SELECT w.id, w.member_id, w.name, w.month_num, w.draw_number,
                    w.shares_won, w.amount, w.winner_date, w.status,
                    w.payment_status, w.certificate_number, w.notes,
                    w.declared_at, w.updated_at, w.deleted_at
             FROM winners w WHERE {$whereSql}
             ORDER BY {$sortSql} LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = array_map(function (array $row): array {
            return [
                'winnerId' => (int) $row['id'],
                'memberId' => $row['member_id'] !== null ? (int) $row['member_id'] : null,
                'name' => (string) $row['name'],
                'month' => (int) $row['month_num'],
                'drawNumber' => (int) $row['draw_number'],
                'sharesWon' => (int) $row['shares_won'],
                'amount' => $this->number($row['amount']),
                'date' => $row['winner_date'],
                'status' => (string) $row['status'],
                'paymentStatus' => (string) $row['payment_status'],
                'certificateNumber' => (string) $row['certificate_number'],
                'notes' => (string) ($row['notes'] ?? ''),
                'declaredAt' => (int) $row['declared_at'],
                'updatedAt' => (int) $row['updated_at'],
                'deletedAt' => $row['deleted_at'] !== null ? (int) $row['deleted_at'] : null,
            ];
        }, $stmt->fetchAll());
        $totals = $this->pdo->prepare(
            "SELECT COALESCE(SUM(w.amount), 0) AS amount,
                    COALESCE(SUM(w.shares_won), 0) AS shares
             FROM winners w WHERE {$whereSql}"
        );
        $totals->execute($params);
        $sum = $totals->fetch() ?: ['amount' => 0, 'shares' => 0];

        return [
            'rows' => $rows,
            'totals' => [
                'records' => $total,
                'prizeAmount' => $this->number($sum['amount']),
                'sharesWon' => (int) $sum['shares'],
            ],
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function requireAdmin(array $user): void
    {
        if (!in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
            throw new RuntimeException('Admin access required.', 403);
        }
    }

    private function assertVersion(array $committee, ?int $expectedVersion): void
    {
        if ($expectedVersion !== null && $expectedVersion > 0
            && $expectedVersion !== (int) $committee['version']) {
            throw new RuntimeException(
                'Version conflict. Reload this committee before saving again.',
                409
            );
        }
    }

    private function actorName(array $user): string
    {
        if (($user['role'] ?? '') === 'admin') {
            return (string) ($this->loadGlobalSettings()['admin_user'] ?? 'admin');
        }
        if (!empty($user['memberId'])) {
            $stmt = $this->pdo->prepare('SELECT name FROM members WHERE id = :id');
            $stmt->execute(['id' => (int) $user['memberId']]);
            return (string) ($stmt->fetchColumn() ?: ('Member #' . (int) $user['memberId']));
        }

        return 'system';
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    private function winnerSignature(array $winner): string
    {
        return implode('|', [
            (string) ($winner['memberId'] ?? ''),
            (string) ($winner['month'] ?? ''),
            (string) ($winner['drawNumber'] ?? $winner['month'] ?? ''),
            (string) ($winner['sharesWon'] ?? 1),
            number_format((float) ($winner['amount'] ?? 0), 2, '.', ''),
        ]);
    }

    private function reportType(string $type): string
    {
        $type = strtolower(trim($type));
        $aliases = [
            'summary' => 'committee-summary',
            'committee' => 'committee-summary',
            'members' => 'member',
            'payments' => 'payment',
            'winners' => 'winner',
            'collections' => 'collection',
        ];
        $type = $aliases[$type] ?? $type;
        if (!in_array($type, ['committee-summary', 'payment', 'member', 'winner', 'collection', 'pending'], true)) {
            throw new InvalidArgumentException('Unsupported report type.', 422);
        }

        return $type;
    }

    private function validateStatus(string $value, array $allowed, string $label): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }

        return $value;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid date. Use YYYY-MM-DD.', 422);
        }

        return $value;
    }

    private function nonNegativeMoney(mixed $value, string $label): int|float
    {
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 9999999999999.99) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }

        return $this->moneyValue((float) $value);
    }

    private function moneyValue(float $value): int|float
    {
        $rounded = round($value, 2);
        return floor($rounded) === $rounded ? (int) $rounded : $rounded;
    }

    private function positiveInt(mixed $value, string $label, int $min, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }
        $value = (int) $value;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }

        return $value;
    }

    private function nonNegativeInt(mixed $value, string $label, int $max): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }
        $value = (int) $value;
        if ($value < 0 || $value > $max) {
            throw new InvalidArgumentException("Invalid {$label}.", 422);
        }

        return $value;
    }

    private function currency(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            throw new InvalidArgumentException('Currency must be a 3-letter code.', 422);
        }

        return $value;
    }

    private function color(mixed $value): string
    {
        $value = trim((string) $value);
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            throw new InvalidArgumentException('Theme color must be a 6-digit hex color.', 422);
        }

        return strtoupper($value);
    }

    private function frequency(mixed $value): string
    {
        return $this->validateStatus(
            (string) $value,
            ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly', 'custom'],
            'frequency'
        );
    }

    private function drawMethod(mixed $value): string
    {
        return $this->validateStatus(
            (string) $value,
            ['manual', 'random', 'lucky_draw', 'ballot', 'scheduled'],
            'draw method'
        );
    }

    private function arrayValue(mixed $value): array
    {
        if (is_object($value)) {
            return (array) $value;
        }
        return is_array($value) ? $value : [];
    }

    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeArray(mixed $value): array
    {
        return array_values($this->decodeObject($value));
    }

    private function encodeObject(mixed $value): string
    {
        return (string) json_encode($this->arrayValue($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function encodeArray(mixed $value): string
    {
        $array = is_array($value) ? array_values($value) : [];
        return (string) json_encode($array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function number(mixed $value): int|float
    {
        return $this->moneyValue((float) $value);
    }

    private function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private static function committeeStatuses(): array
    {
        return ['draft', 'running', 'paused', 'completed', 'archived'];
    }

    private static function installmentStatuses(): array
    {
        return ['pending', 'paid', 'partial', 'late', 'waived'];
    }

    private static function winnerStatuses(): array
    {
        return ['declared', 'confirmed', 'paid', 'voided'];
    }

    private static function paymentStatuses(): array
    {
        return ['pending', 'paid', 'partial', 'late', 'waived'];
    }

    private static function eventTypes(): array
    {
        return ['draw', 'payment_due', 'meeting', 'announcement', 'winner_ceremony'];
    }

    // ── Owner / SaaS user management ─────────────────────────────────────

    public function signupUser(array $input): array
    {
        $name     = trim((string) ($input['name'] ?? ''));
        $email    = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $mobile   = trim((string) ($input['mobile'] ?? ''));

        if ($name === '' || $email === '' || $password === '') {
            throw new \InvalidArgumentException('Name, email, and password are required.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.', 422);
        }
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters.', 422);
        }

        $check = $this->pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new \RuntimeException('An account with this email already exists.', 409);
        }

        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO users (name, email, mobile, password_hash, role, subscription_plan, status, created_at, updated_at)
             VALUES (:name, :email, :mobile, :hash, \'app_user\', \'free\', \'active\', :ca, :ua)'
        )->execute([
            'name'   => $name,
            'email'  => $email,
            'mobile' => $mobile,
            'hash'   => password_hash($password, PASSWORD_DEFAULT),
            'ca'     => $now,
            'ua'     => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        return ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'app_user'];
    }

    public function findOrCreateOAuthUser(string $provider, string $providerId, string $email, string $name): array
    {
        $col = $provider === 'google' ? 'google_id' : 'facebook_id';

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE {$col} = ? LIMIT 1");
        $stmt->execute([$providerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $email = strtolower(trim($email));
        if ($email !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $this->pdo->prepare("UPDATE users SET {$col} = ?, updated_at = ? WHERE id = ?")
                     ->execute([$providerId, $this->now(), $row['id']]);
                $row[$col] = $providerId;
                return $row;
            }
        }

        $now = $this->now();
        $this->pdo->prepare(
            "INSERT INTO users (name, email, {$col}, password_hash, role, subscription_plan, status, created_at, updated_at)
             VALUES (:name, :email, :pid, '', 'app_user', 'free', 'active', :ca, :ua)"
        )->execute([
            'name'  => $name,
            'email' => $email,
            'pid'   => $providerId,
            'ca'    => $now,
            'ua'    => $now,
        ]);
        $newId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$newId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function listAllUsers(array $callingUser): array
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Superadmin access required.', 403);
        }
        $stmt = $this->pdo->query(
            'SELECT u.id, u.name, u.email, u.mobile, u.role, u.subscription_plan, u.status, u.created_at,
                    GROUP_CONCAT(cu.committee_id ORDER BY cu.committee_id) AS committee_ids
             FROM users u
             LEFT JOIN committee_users cu ON cu.user_id = u.id
             GROUP BY u.id ORDER BY u.created_at DESC'
        );
        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['committeeIds'] = $row['committee_ids']
                ? array_map('intval', explode(',', $row['committee_ids']))
                : [];
            unset($row['committee_ids']);
            return $row;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function updateUser(int $id, array $input, array $callingUser): void
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Superadmin access required.', 403);
        }
        $fields = [];
        $params = [':id' => $id];

        if (isset($input['name'])) {
            $fields[] = 'name = :name';
            $params[':name'] = trim((string) $input['name']);
        }
        if (isset($input['email'])) {
            $email = strtolower(trim((string) $input['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email.', 422);
            }
            $fields[] = 'email = :email';
            $params[':email'] = $email;
        }
        if (isset($input['mobile'])) {
            $fields[] = 'mobile = :mobile';
            $params[':mobile'] = trim((string) $input['mobile']);
        }
        if (isset($input['role']) && in_array($input['role'], ['owner', 'app_user'], true)) {
            $fields[] = 'role = :role';
            $params[':role'] = $input['role'];
        }
        if (isset($input['subscription_plan'])) {
            $fields[] = 'subscription_plan = :plan';
            $params[':plan'] = trim((string) $input['subscription_plan']);
        }
        if (isset($input['status']) && in_array($input['status'], ['active', 'suspended', 'pending'], true)) {
            $fields[] = 'status = :status';
            $params[':status'] = $input['status'];
        }
        if (isset($input['password']) && strlen((string) $input['password']) >= 6) {
            $fields[] = 'password_hash = :hash';
            $params[':hash'] = password_hash((string) $input['password'], PASSWORD_DEFAULT);
        }

        if (!$fields) {
            return;
        }
        $fields[] = 'updated_at = :ua';
        $params[':ua'] = $this->now();

        $this->pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')
             ->execute($params);
    }

    public function deleteUser(int $id, array $callingUser): void
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Superadmin access required.', 403);
        }
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    public function createOwner(string $name, string $email, string $password, array $callingUser): array
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Only the superadmin can create owner accounts.', 403);
        }
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Valid email is required.', 422);
        }
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters.', 422);
        }
        $now = $this->now();
        $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, created_at, updated_at)
             VALUES (:name, :email, :hash, \'owner\', :created_at, :updated_at)'
        )->execute([
            'name'       => trim($name),
            'email'      => $email,
            'hash'       => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        return $this->ownerRow($id);
    }

    public function assignOwnerToCommittee(int $userId, int $committeeId, string $role, array $callingUser): void
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Only the superadmin can assign committee access.', 403);
        }
        $allowedRoles = ['owner', 'manager', 'viewer'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'owner';
        }
        $this->pdo->prepare(
            'INSERT INTO committee_users (user_id, committee_id, role, created_at)
             VALUES (:uid, :cid, :role, :now)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute(['uid' => $userId, 'cid' => $committeeId, 'role' => $role, 'now' => $this->now()]);
    }

    public function listOwners(array $callingUser): array
    {
        if (($callingUser['role'] ?? '') !== 'admin') {
            throw new RuntimeException('Only the superadmin can list owners.', 403);
        }
        $stmt = $this->pdo->query(
            'SELECT u.id, u.name, u.email, u.role, u.created_at,
                    GROUP_CONCAT(cu.committee_id ORDER BY cu.committee_id) AS committee_ids
             FROM users u
             LEFT JOIN committee_users cu ON cu.user_id = u.id
             GROUP BY u.id ORDER BY u.created_at DESC'
        );

        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['committeeIds'] = $row['committee_ids']
                ? array_map('intval', explode(',', $row['committee_ids']))
                : [];
            unset($row['committee_ids']);
            return $row;
        }, $stmt->fetchAll());
    }

    private function ownerRow(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'email' => (string) $row['email'], 'role' => (string) $row['role']] : ['id' => $id];
    }
}
