<?php
require_once __DIR__ . '/auth.php';
require_auth(['applicant', 'admin', 'staff']);
require_once __DIR__ . '/business_config.php';

header('Content-Type: application/json');

$params = json_decode(file_get_contents('php://input'), true);
$businessNumber = trim((string)($params['BUSINESSNUMBER'] ?? ''));

if ($businessNumber === '') {
    echo json_encode(['status' => 'error', 'message' => 'Enter a CN or control number to search.']);
    exit;
}

if ($businessNumber === '20315857') {
    $businessList = [
        [
            'billingId' => 'DUMMY-20315857-001',
            'typeId' => 30,
            'typeName' => 'BUSINESS TAX',
            'amountDue' => 1250.00,
            'amountDueDt' => date('Y-m-d'),
            'billingName' => 'BUSINESS TAX (1ST QUARTER) - SARI-SARI',
            'billingRemarks' => 'Dummy billing record for testing.',
            'referenceId3' => 1,
            'penaltyAmount' => 0.00,
        ],
        [
            'billingId' => 'DUMMY-20315857-002',
            'typeId' => 7,
            'typeName' => 'GARBAGE FEE',
            'amountDue' => 300.00,
            'amountDueDt' => date('Y-m-d'),
            'billingName' => 'GARBAGE FEE (GF)',
            'billingRemarks' => 'Dummy billing record for testing.',
            'referenceId3' => 2,
            'penaltyAmount' => 0.00,
        ],
        [
            'billingId' => 'DUMMY-20315857-003',
            'typeId' => 8,
            'typeName' => 'HEALTH FEE',
            'amountDue' => 550.00,
            'amountDueDt' => date('Y-m-d'),
            'billingName' => 'HC/SANITARY PERMIT FEE (HSPF)',
            'billingRemarks' => 'Dummy billing record for testing.',
            'referenceId3' => 2,
            'penaltyAmount' => 0.00,
        ],
        [
            'billingId' => 'DUMMY-20315857-004',
            'typeId' => 14,
            'typeName' => 'BUSINESS PERMIT FEE',
            'amountDue' => 100.00,
            'amountDueDt' => date('Y-m-d'),
            'billingName' => 'BUSINESS PERMIT FEE (PF)',
            'billingRemarks' => 'Dummy billing record for testing.',
            'referenceId3' => 2,
            'penaltyAmount' => 0.00,
        ],
    ];
    echo json_encode([
        'status' => 'success',
        'businessList' => $businessList,
        'typeNames' => array_values(array_unique(array_column($businessList, 'typeName'))),
    ]);
    exit;
}

try {
    $cn = bill_inquiry_connection();

    $permitQuery = strpos($businessNumber, '-') !== false
        ? 'SELECT permitId FROM tblbusinesspermitissuance WHERE issueNo = ?'
        : 'SELECT bp.permitId FROM tblbusinesspermit bp WHERE bp.permitNo = ?';

    $permitStmt = mysqli_prepare($cn, $permitQuery);
    if (!$permitStmt) {
        throw new RuntimeException('Failed to prepare permit query.');
    }

    mysqli_stmt_bind_param($permitStmt, 's', $businessNumber);
    mysqli_stmt_execute($permitStmt);
    mysqli_stmt_bind_result($permitStmt, $permitId);

    $permitIds = [];
    while (mysqli_stmt_fetch($permitStmt)) {
        $permitIds[] = $permitId;
    }
    mysqli_stmt_close($permitStmt);

    if (!$permitIds && strpos($businessNumber, '-') === false) {
        $issuanceStmt = mysqli_prepare($cn, 'SELECT permitId FROM tblbusinesspermitissuance WHERE issueNo = ?');
        if (!$issuanceStmt) {
            throw new RuntimeException('Failed to prepare control number fallback query.');
        }

        mysqli_stmt_bind_param($issuanceStmt, 's', $businessNumber);
        mysqli_stmt_execute($issuanceStmt);
        mysqli_stmt_bind_result($issuanceStmt, $permitId);

        while (mysqli_stmt_fetch($issuanceStmt)) {
            $permitIds[] = $permitId;
        }
        mysqli_stmt_close($issuanceStmt);
    }

    if (!$permitIds) {
        mysqli_close($cn);
        echo json_encode(['status' => 'error', 'message' => 'No records found for the given CN or control number.']);
        exit;
    }

    $billingStmt = mysqli_prepare($cn, 'CALL spBusinessBillingRecordsFind(?, ?)');
    if (!$billingStmt) {
        throw new RuntimeException('Failed to prepare billing query.');
    }

    $currentDate = date('Y-m-d');
    mysqli_stmt_bind_param($billingStmt, 'ss', $permitIds[0], $currentDate);
    mysqli_stmt_execute($billingStmt);
    mysqli_stmt_bind_result($billingStmt, $billingId, $typeId, $typeName, $amountDue, $amountDueDt, $billingName, $billingRemarks, $referenceId3, $penaltyAmount);

    $businessList = [];
    $typeNames = [];
    while (mysqli_stmt_fetch($billingStmt)) {
        $cleanTypeName = trim((string)$typeName);
        if ($cleanTypeName !== '' && !in_array($cleanTypeName, $typeNames, true)) {
            $typeNames[] = $cleanTypeName;
        }

        $businessList[] = [
            'billingId' => $billingId,
            'typeId' => $typeId,
            'typeName' => $typeName,
            'amountDue' => $amountDue,
            'amountDueDt' => $amountDueDt,
            'billingName' => $billingName,
            'billingRemarks' => $billingRemarks,
            'referenceId3' => $referenceId3,
            'penaltyAmount' => $penaltyAmount,
        ];
    }

    mysqli_stmt_close($billingStmt);
    mysqli_close($cn);

    echo json_encode(['status' => 'success', 'businessList' => $businessList, 'typeNames' => $typeNames]);
} catch (Throwable $e) {
    if (isset($cn) && $cn instanceof mysqli) {
        mysqli_close($cn);
    }

    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
