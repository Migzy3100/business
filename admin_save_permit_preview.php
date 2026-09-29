<?php

require_once __DIR__ . '/auth.php';
header('Content-Type: application/json');
require_auth(['admin', 'staff']);

function permit_field_text(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') return '';
    return $trimmed;
}

function draw_centered_line($img, string $text, float $xPct, float $yPct, float $wPct, int $color): void
{
    if ($text === '') return;
    $imgW = imagesx($img);
    $imgH = imagesy($img);
    $x = (int)round($imgW * $xPct);
    $y = (int)round($imgH * $yPct);
    $w = (int)round($imgW * $wPct);

    $ttfCandidates = [
        'C:\\Windows\\Fonts\\arialbd.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
        'C:\\Windows\\Fonts\\calibrib.ttf',
        'C:\\Windows\\Fonts\\calibri.ttf',
        'C:\\Windows\\Fonts\\timesbd.ttf',
        'C:\\Windows\\Fonts\\times.ttf',
    ];
    $ttf = null;
    foreach ($ttfCandidates as $candidate) {
        if (is_file($candidate)) {
            $ttf = $candidate;
            break;
        }
    }

    if ($ttf && function_exists('imagettfbbox') && function_exists('imagettftext')) {
        $size = max(18, (int)round($imgW * 0.015));
        $line = $text;
        for ($i = 0; $i < 30; $i++) {
            $box = imagettfbbox($size, 0, $ttf, $line);
            if (!$box) break;
            $lineW = (int)abs($box[2] - $box[0]);
            if ($lineW <= $w || mb_strlen($line) <= 1) break;
            $line = mb_substr($line, 0, max(1, mb_strlen($line) - 1));
        }
        $box = imagettfbbox($size, 0, $ttf, $line);
        if ($box) {
            $lineW = (int)abs($box[2] - $box[0]);
            $drawX = $x + max(0, (int)floor(($w - $lineW) / 2));
            $baselineY = $y + $size;
            imagettftext($img, $size, 0, $drawX, $baselineY, $color, $ttf, $line);
            return;
        }
    }

    $font = 5;
    $charW = imagefontwidth($font);
    $maxChars = max(1, (int)floor($w / $charW));
    $line = mb_substr($text, 0, $maxChars);
    $lineW = imagefontwidth($font) * strlen($line);
    $drawX = $x + max(0, (int)floor(($w - $lineW) / 2));
    imagestring($img, $font, $drawX, $y, $line, $color);
}

function render_business_permit_image(string $sourceAbsPath, string $targetAbsPath, array $row): bool
{
    if (!is_file($sourceAbsPath)) return false;
    $img = @imagecreatefromjpeg($sourceAbsPath);
    if (!$img) return false;

    $black = imagecolorallocate($img, 20, 20, 20);

    $issueDate = !empty($row['application_date']) ? strtotime((string)$row['application_date']) : time();
    if ($issueDate === false) $issueDate = time();
    $validUntil = strtotime('+' . PERMIT_VALID_DAYS . ' days', $issueDate);
    $issueText = date('F j, Y', $issueDate);
    $validityText = date('F j, Y', $validUntil ?: $issueDate);

    draw_centered_line($img, permit_field_text((string)($row['owner_name'] ?? '')), 0.06, 0.22, 0.43, $black);
    draw_centered_line($img, permit_field_text((string)($row['representative'] ?? '')), 0.53, 0.22, 0.41, $black);
    draw_centered_line($img, permit_field_text((string)($row['business_name'] ?? '')), 0.27, 0.26, 0.64, $black);
    draw_centered_line($img, permit_field_text((string)($row['business_address'] ?? '')), 0.16, 0.309, 0.54, $black);
    draw_centered_line($img, permit_field_text((string)($row['business_barangay'] ?? '')), 0.74, 0.309, 0.22, $black);
    draw_centered_line($img, permit_field_text((string)($row['home_address'] ?? '')), 0.18, 0.568, 0.58, $black);
    draw_centered_line($img, permit_field_text($validityText), 0.28, 0.609, 0.36, $black);
    draw_centered_line($img, permit_field_text($issueText), 0.12, 0.668, 0.31, $black);

    $ok = imagejpeg($img, $targetAbsPath, 92);
    imagedestroy($img);
    return (bool)$ok;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF']);
    exit;
}

$applicationId = (int)($_POST['application_id'] ?? 0);
if ($applicationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid application id.']);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM applications WHERE id=? LIMIT 1');
$stmt->execute([$applicationId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Application not found.']);
    exit;
}

$approvedDir = __DIR__ . '/../uploads/approved_permits';
if (!is_dir($approvedDir)) {
    mkdir($approvedDir, 0775, true);
}
$srcPreview = __DIR__ . '/../assets/img/BP_2024.jpg';
if (!is_file($srcPreview)) {
    echo json_encode(['success' => false, 'message' => 'Permit template image not found.']);
    exit;
}

$savedName = 'permit_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)$row['reference_no']) . '_' . date('Ymd_His') . '.jpg';
$savedAbsPath = $approvedDir . '/' . $savedName;
if (!render_business_permit_image($srcPreview, $savedAbsPath, $row)) {
    echo json_encode(['success' => false, 'message' => 'Failed to render permit image.']);
    exit;
}

$savedRelPath = 'uploads/approved_permits/' . $savedName;
try {
    $pdo->prepare('DELETE FROM approved_application_files WHERE application_id=?')->execute([$applicationId]);
    $pdo->prepare('INSERT INTO approved_application_files(application_id, file_path, original_name, created_at) VALUES(?,?,?,NOW())')
        ->execute([$applicationId, $savedRelPath, 'Business_Permit_' . (string)$row['reference_no'] . '.jpg']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to save approved permit record.']);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Permit preview image saved.',
    'file_path' => $savedRelPath,
]);
