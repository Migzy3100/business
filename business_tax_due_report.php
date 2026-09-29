<?php
require_once __DIR__ . '/auth.php';
require_auth(['applicant']);
require_once __DIR__ . '/business_config.php';

header('Content-Type: application/json');

$params = json_decode(file_get_contents('php://input'), true);
$businessNumber = trim((string)($params['BUSINESSNUMBER'] ?? $params['permitNo'] ?? ''));

if ($businessNumber === '') {
    echo json_encode(['status' => 'error', 'message' => 'Enter a CN or control number to search.']);
    exit;
}

function fetch_all_assoc(mysqli_result $result): array
{
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);
    return $rows;
}

function drain_mysqli_results(mysqli $cn): void
{
    while (mysqli_more_results($cn)) {
        mysqli_next_result($cn);
        $result = mysqli_store_result($cn);
        if ($result instanceof mysqli_result) {
            mysqli_free_result($result);
        }
    }
}

function call_permit_report(mysqli $cn, string $procedureName, int $permitId): array
{
    $safeProcedureNames = [
        'spBusinessTaxDueReport',
        'spBusinessPermitFeeReport',
        'spBusinessPermitDueQuarterlyReport',
        'spBusinessPermitDueReport',
    ];
    if (!in_array($procedureName, $safeProcedureNames, true)) {
        throw new RuntimeException('Invalid report procedure.');
    }

    $sql = 'CALL ' . $procedureName . '(' . $permitId . ')';
    $result = mysqli_query($cn, $sql);
    if ($result === false) {
        throw new RuntimeException('Failed to fetch ' . $procedureName . ': ' . mysqli_error($cn));
    }

    $rows = $result instanceof mysqli_result ? fetch_all_assoc($result) : [];
    drain_mysqli_results($cn);
    return $rows;
}

function resolve_personnel_name(mysqli $cn, $personnelId): string
{
    $id = (int)$personnelId;
    if ($id <= 0) {
        return '';
    }

    $knownUsers = [
        58 => 'sdarangina',
    ];
    if (isset($knownUsers[$id])) {
        return $knownUsers[$id];
    }

    $stmt = mysqli_prepare($cn, '
        SELECT CALL_SIGN, FIRST_NAME, MIDDLE_NAME, LAST_NAME, NAME_EXT
        FROM tbl_ref_tmcpersonnel
        WHERE ID = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return '';
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return '';
    }

    mysqli_stmt_bind_result($stmt, $callSign, $firstName, $middleName, $lastName, $nameExt);
    $name = '';
    if (mysqli_stmt_fetch($stmt)) {
        $callSign = trim((string)$callSign);
        if ($callSign !== '') {
            $name = $callSign;
        } else {
            $parts = array_filter([
                trim((string)$firstName),
                trim((string)$middleName),
                trim((string)$lastName),
                trim((string)$nameExt),
            ], static fn ($part) => $part !== '');
            $name = trim(implode(' ', $parts));
        }
    }
    mysqli_stmt_close($stmt);

    return $name;
}

if ($businessNumber === '20315857') {
    echo json_encode([
        'status' => 'success',
        'permitDetails' => [
            'permitId' => 20315857,
            'permitNo' => '20315857',
            'permitDt' => date('Y-m-d'),
            'businessAddress' => 'Dummy business address',
            'permitType' => 'NEW',
            'paymentMode' => 'Quarterly',
            'capitalAmount' => '0.00',
            'basicTax' => '1250.00',
            'regulatoryFee' => '950.00',
        ],
        'reports' => [
            'businessTaxDueReport' => [
                ['billingName' => 'BUSINESS TAX (1ST QUARTER) - SARI-SARI', 'dueDate' => date('Y-m-d'), 'amount' => '1250.00', 'penalty' => '0.00', 'total' => '1250.00'],
            ],
            'businessRegulatoryFeeReport' => [
                ['billingName' => 'GARBAGE FEE (GF)', 'dueDate' => date('Y-m-d'), 'amount' => '300.00', 'penalty' => '0.00', 'total' => '300.00'],
                ['billingName' => 'HC/SANITARY PERMIT FEE (HSPF)', 'dueDate' => date('Y-m-d'), 'amount' => '550.00', 'penalty' => '0.00', 'total' => '550.00'],
                ['billingName' => 'BUSINESS PERMIT FEE (PF)', 'dueDate' => date('Y-m-d'), 'amount' => '100.00', 'penalty' => '0.00', 'total' => '100.00'],
            ],
            'businessQuarterlyDueReport' => [],
            'businessPermitDuePerQuarterReport' => [],
        ],
    ]);
    exit;
}

try {
    $cn = bill_inquiry_connection();

    $permitIdFromIssuance = 0;
    $issuanceStmt = mysqli_prepare($cn, 'SELECT `permitId` FROM `tblbusinesspermitissuance` WHERE `issueNo` = ? LIMIT 1');
    if (!$issuanceStmt) {
        throw new RuntimeException('Failed to prepare control number query.');
    }
    mysqli_stmt_bind_param($issuanceStmt, 's', $businessNumber);
    mysqli_stmt_execute($issuanceStmt);
    mysqli_stmt_bind_result($issuanceStmt, $foundPermitId);
    if (mysqli_stmt_fetch($issuanceStmt)) {
        $permitIdFromIssuance = (int)$foundPermitId;
    }
    mysqli_stmt_close($issuanceStmt);

    $permitSql = '
        SELECT
            bp.`permitId`, bp.`tdId`, bp.`taxCodeId`, bp.`permitNo`, bp.`permitDt`, bp.`PBRFl`, bp.`PBRapplicationDt`, bp.`PBRcompletionDt`,
            bp.`businessId`, bp.`businessAddress`, bp.`categoryId`, bp.`permitType`, bp.`refPermitId`, bp.`applicantId`, bp.`applicantAddress`,
            bp.`ctcFl`, bp.`ctcDt`, bp.`ctcNo`, bp.`leasedFl`, bp.`leasorId`, bp.`rentalAmount`, bp.`noEmployee`, bp.`noEmployeeLGU`,
            bp.`businessArea`, bp.`paymentMode`, bp.`permitStatus`, bp.`permitRemarks`, bp.`representativeId`, bp.`representativePosition`,
            bp.`interviewedBy`, bp.`reviewedBy`, bp.`capitalAmount`, bp.`grossEssential`, bp.`grossNonEssential`, bp.`basicTax`,
            bp.`regulatoryFee`, bp.`interestAmount`, bp.`deficiencyAmount`, bp.`advancePayment`, bp.`delinquencyAmount`, bp.`taxCredit`,
            bp.`surchargeAmount`, bp.`promisoryAmount`, bp.`cancelFl`, bp.`cancelDt`, bp.`closedFl`, bp.`closedDt`, bp.`retiredFl`, bp.`retiredDt`,
            bp.`updatedBy`, bp.`updatedDt`, bp.`createdBy`, bp.`createdDt`, bp.`releasedFl`, bp.`duration`, bp.`withCCTV`,
            bp.`male`, bp.`female`, bp.`maleLGU`, bp.`femaleLGU`,
            b.`businessName`, b.`business` AS `tradeName`, b.`ownerId`, b.`streetAddress`, b.`subdivisionAddress`,
            b.`houseNo`, b.`rurCode`, b.`businessAddress` AS `registeredBusinessAddress`
        FROM `tblbusinesspermit` bp
        LEFT JOIN `tblbusiness` b ON b.`businessId` = bp.`businessId`
        WHERE bp.`permitNo` = ? ' . ($permitIdFromIssuance > 0 ? 'OR bp.`permitId` = ?' : '') . '
        ORDER BY CASE WHEN bp.`permitNo` = ? THEN 0 ELSE 1 END
        LIMIT 1
    ';

    $permitStmt = mysqli_prepare($cn, $permitSql);
    if (!$permitStmt) {
        throw new RuntimeException('Failed to prepare permit details query.');
    }

    if ($permitIdFromIssuance > 0) {
        mysqli_stmt_bind_param($permitStmt, 'sis', $businessNumber, $permitIdFromIssuance, $businessNumber);
    } else {
        mysqli_stmt_bind_param($permitStmt, 'ss', $businessNumber, $businessNumber);
    }
    mysqli_stmt_execute($permitStmt);
    $permitResult = mysqli_stmt_get_result($permitStmt);
    $permitDetails = $permitResult ? mysqli_fetch_assoc($permitResult) : null;
    if ($permitResult instanceof mysqli_result) {
        mysqli_free_result($permitResult);
    }
    mysqli_stmt_close($permitStmt);

    if (!$permitDetails) {
        mysqli_close($cn);
        echo json_encode(['status' => 'error', 'message' => 'No business permit details found for the given permit number.']);
        exit;
    }

    $permitId = (int)($permitDetails['permitId'] ?? 0);
    if ($permitId <= 0) {
        throw new RuntimeException('Business permit record has an invalid permit id.');
    }

    $permitDetails['createdByName'] = resolve_personnel_name($cn, $permitDetails['createdBy'] ?? 0);
    $permitDetails['updatedByName'] = resolve_personnel_name($cn, $permitDetails['updatedBy'] ?? 0);

    $reports = [];
    $reportErrors = [];
    $reportProcedures = [
        'businessTaxDueReport' => 'spBusinessTaxDueReport',
        'businessRegulatoryFeeReport' => 'spBusinessPermitFeeReport',
        'businessQuarterlyDueReport' => 'spBusinessPermitDueQuarterlyReport',
        'businessPermitDuePerQuarterReport' => 'spBusinessPermitDueReport',
    ];
    foreach ($reportProcedures as $key => $procedureName) {
        try {
            $reports[$key] = call_permit_report($cn, $procedureName, $permitId);
        } catch (Throwable $reportError) {
            $reports[$key] = [];
            $reportErrors[$key] = $reportError->getMessage();
            drain_mysqli_results($cn);
        }
    }

    mysqli_close($cn);

    echo json_encode([
        'status' => 'success',
        'permitDetails' => $permitDetails,
        'reports' => $reports,
        'reportErrors' => $reportErrors,
    ]);
} catch (Throwable $e) {
    if (isset($cn) && $cn instanceof mysqli) {
        mysqli_close($cn);
    }

    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
