<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Auth;
use CommitteeManager\DataRepository;
use CommitteeManager\Http;
use CommitteeManager\MultiCommitteeRepository;

header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (Http::method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $user = Auth::requireLogin();
    $repo = new DataRepository();
    $enterprise = null;
    try {
        $enterprise = new MultiCommitteeRepository(null, $repo);
        $enterprise->defaultCommitteeId();
    } catch (\Throwable $ignored) {
        $enterprise = null;
    }

    if (Http::method() === 'GET') {
        // Server-side reporting endpoint. Reports are authorized and scoped by
        // committee/member before any query is executed.
        if ($enterprise && Http::action() === 'report') {
            $requestedCommittee = isset($_GET['committeeId']) ? (int) $_GET['committeeId'] : null;
            $committeeId = $enterprise->resolveCommitteeId($requestedCommittee, $user);
            $report = $enterprise->getReport($committeeId, (string) ($_GET['type'] ?? 'committee-summary'), $_GET, $user);
            $type = $report['type'];
            $rows = $report['rows'];
            $columns = [];
            $presentedRows = [];
            if ($type === 'committee-summary') {
                $columns = [
                    ['key' => 'committeeName', 'label' => 'Committee'],
                    ['key' => 'status', 'label' => 'Status'],
                    ['key' => 'totalMembers', 'label' => 'Members'],
                    ['key' => 'totalCollection', 'label' => 'Collection'],
                    ['key' => 'pendingAmount', 'label' => 'Outstanding'],
                    ['key' => 'lateAmount', 'label' => 'Late'],
                    ['key' => 'totalWinners', 'label' => 'Winners'],
                ];
                foreach ($rows as $row) {
                    $presentedRows[] = [
                        'committeeName' => (string) ($row['committee']['name'] ?? ''),
                        'status' => (string) ($row['committee']['status'] ?? ''),
                        'totalMembers' => (int) ($row['totalMembers'] ?? 0),
                        'totalCollection' => $row['totalCollection'] ?? 0,
                        'pendingAmount' => $row['pendingAmount'] ?? 0,
                        'lateAmount' => $row['lateAmount'] ?? 0,
                        'totalWinners' => (int) ($row['totalWinners'] ?? 0),
                    ];
                }
                $totals = $report['totals'];
                $summary = ['Members' => $totals['totalMembers'] ?? 0, 'Collected' => $totals['totalCollection'] ?? 0, 'Outstanding' => $totals['pendingAmount'] ?? 0, 'Late' => $totals['lateAmount'] ?? 0, 'Winners' => $totals['totalWinners'] ?? 0];
            } elseif ($type === 'member') {
                $columns = [['key'=>'memberId','label'=>'Member ID'],['key'=>'name','label'=>'Name'],['key'=>'shares','label'=>'Shares'],['key'=>'phone','label'=>'Phone'],['key'=>'preferredMonth','label'=>'Preferred Month'],['key'=>'paidInstallments','label'=>'Paid Installments'],['key'=>'amountPaid','label'=>'Amount Paid'],['key'=>'sharesWon','label'=>'Shares Won'],['key'=>'prizeWon','label'=>'Prize Won'],['key'=>'status','label'=>'Status']];
                $presentedRows = $rows;
                $summary = ['Members' => $report['totals']['members'] ?? 0];
            } elseif ($type === 'payment' || $type === 'collection') {
                $columns = [['key'=>'memberId','label'=>'Member ID'],['key'=>'memberName','label'=>'Member'],['key'=>'month','label'=>'Month'],['key'=>'amountDue','label'=>'Amount Due'],['key'=>'amountPaid','label'=>'Amount Paid'],['key'=>'outstanding','label'=>'Outstanding'],['key'=>'status','label'=>'Status'],['key'=>'paymentMethod','label'=>'Payment Method'],['key'=>'referenceNo','label'=>'Reference'],['key'=>'paidAt','label'=>'Paid At']];
                $presentedRows = $rows;
                $summary = ['Records' => $report['totals']['records'] ?? 0, 'Amount Due' => $report['totals']['amountDue'] ?? 0, 'Amount Paid' => $report['totals']['amountPaid'] ?? 0, 'Outstanding' => $report['totals']['outstanding'] ?? 0];
            } elseif ($type === 'pending') {
                $columns = [['key'=>'memberId','label'=>'Member ID'],['key'=>'memberName','label'=>'Member'],['key'=>'shares','label'=>'Shares'],['key'=>'month','label'=>'Month'],['key'=>'amountDue','label'=>'Amount Due'],['key'=>'amountPaid','label'=>'Amount Paid'],['key'=>'outstanding','label'=>'Outstanding'],['key'=>'status','label'=>'Status'],['key'=>'dueAt','label'=>'Due At']];
                $presentedRows = $rows;
                $summary = ['Records' => $report['totals']['records'] ?? 0, 'Outstanding' => $report['totals']['outstanding'] ?? ($report['totals']['pageOutstanding'] ?? 0)];
            } else { // winner
                $columns = [['key'=>'winnerId','label'=>'Winner ID'],['key'=>'memberId','label'=>'Member ID'],['key'=>'name','label'=>'Winner'],['key'=>'month','label'=>'Month'],['key'=>'drawNumber','label'=>'Draw'],['key'=>'date','label'=>'Date'],['key'=>'sharesWon','label'=>'Shares Won'],['key'=>'amount','label'=>'Prize'],['key'=>'paymentStatus','label'=>'Payment Status'],['key'=>'status','label'=>'Status']];
                $presentedRows = $rows;
                $summary = ['Records' => $report['totals']['records'] ?? 0, 'Prize Amount' => $report['totals']['prizeAmount'] ?? 0, 'Shares Won' => $report['totals']['sharesWon'] ?? 0];
            }
            $report['rows'] = $presentedRows;
            $report['columns'] = $columns;
            $report['summary'] = $summary;
            $report['page'] = $report['pagination']['page'] ?? 1;
            $report['perPage'] = $report['pagination']['perPage'] ?? 25;
            $report['total'] = $report['pagination']['total'] ?? count($presentedRows);
            $report['totalPages'] = $report['pagination']['totalPages'] ?? 1;
            Http::json(['ok' => true, 'data' => $report]);
        }
        if ($user['role'] === 'user' && !empty($user['memberId'])) {
            if ($enterprise) {
                $committeeId = $enterprise->resolveCommitteeId(isset($_GET['committeeId']) ? (int) $_GET['committeeId'] : null, $user);
                Http::json(['ok' => true, 'data' => $enterprise->loadData($committeeId, $user)]);
            }
            Http::json($repo->loadForMember((int) $user['memberId']));
        }
        Auth::requireAdmin();
        if ($enterprise) {
            $committeeId = $enterprise->resolveCommitteeId(isset($_GET['committeeId']) ? (int) $_GET['committeeId'] : null, $user);
            Http::json(['ok' => true, 'data' => $enterprise->loadData($committeeId, $user)]);
        }
        Http::json($repo->loadAll());
    }

    if (Http::method() !== 'POST') {
        Http::json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    $body = Http::readJsonBody();

    if ($user['role'] === 'admin') {
        if ($enterprise) {
            $committeeId = $enterprise->resolveCommitteeId(isset($body['committeeId']) ? (int) $body['committeeId'] : null, $user, true);
            $action = (string) ($body['action'] ?? '');
            if ($action === 'createCommittee') Http::json(['ok' => true, 'data' => $enterprise->createCommittee($body, $user)]);
            if ($action === 'archiveCommittee') Http::json(['ok' => true, 'data' => $enterprise->archiveCommittee($committeeId, $user, isset($body['version']) ? (int) $body['version'] : null)]);
            if ($action === 'resetCommittee' || !empty($body['resetAll'])) Http::json(['ok' => true, 'data' => $enterprise->resetCommittee($committeeId, $user, isset($body['version']) ? (int) $body['version'] : null)]);
            if ($action === 'permanentDelete') Http::json(['ok' => true, 'data' => $enterprise->permanentDeleteMembers($committeeId, $body['memberIds'] ?? [], $user)]);
            if ($action === 'markNotificationRead') Http::json(['ok' => true, 'data' => $enterprise->markNotificationRead($committeeId, (int) ($body['notificationId'] ?? 0), $user)]);
            if ($action === 'logReportExport') Http::json(['ok' => true, 'data' => $enterprise->logReportExport($committeeId, $body, $user)]);
            if ($action === 'closeMonth') Http::json(['ok' => true, 'data' => $enterprise->setMonthClosure($committeeId, (int) ($body['month'] ?? 0), true, (string) ($body['notes'] ?? ''), $user)]);
            if ($action === 'reopenMonth') Http::json(['ok' => true, 'data' => $enterprise->setMonthClosure($committeeId, (int) ($body['month'] ?? 0), false, '', $user)]);
            Http::json(['ok' => true, 'data' => $enterprise->saveCommitteeData($committeeId, $body, $user)]);
        }
        if (!empty($body['resetAll'])) {
            $data = $repo->resetToDefaults();
            Http::json(['ok' => true, 'data' => $data]);
        }

        if (($body['action'] ?? '') === 'permanentDelete') {
            $saved = $repo->permanentDeleteMembers($body['memberIds'] ?? []);
            Http::json(['ok' => true, 'data' => $saved]);
        }

        $saved = $repo->saveAll($body);
        Http::json(['ok' => true, 'data' => $saved]);
    }

    if ($user['role'] === 'user' && !empty($user['memberId'])) {
        if ($enterprise) {
            $committeeId = $enterprise->resolveCommitteeId(isset($body['committeeId']) ? (int) $body['committeeId'] : null, $user, true);
            Http::json(['ok' => true, 'data' => $enterprise->saveMemberSelf($committeeId, (int) $user['memberId'], $body, $user)]);
        }
        $current = $repo->loadAll();
        if (!$repo->payloadMatchesMemberSelfEdit($body, $current, (int) $user['memberId'])) {
            Http::json(['ok' => false, 'error' => 'Members may only update their own PIN and preferred month'], 403);
        }

        $saved = $repo->saveMemberSelf((int) $user['memberId'], $body, $current);
        Http::json(['ok' => true, 'data' => $saved]);
    }

    Http::json(['ok' => false, 'error' => 'Forbidden'], 403);
} catch (\Throwable $e) {
    $message = $e->getMessage();
    if (stripos($message, 'Database connection failed') !== false || stripos($message, 'SQLSTATE') !== false) {
        $message = 'Database connection failed. Check .env DB credentials. Open /api/health.php for details.';
    }
    Http::json(['ok' => false, 'error' => $message], 500);
}
