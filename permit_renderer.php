<?php

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
        __DIR__ . '/assets/fonts/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSerif-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
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

function permit_template_path(): ?string
{
    foreach ([__DIR__ . '/assets/BP_2024.jpg', __DIR__ . '/../assets/img/BP_2024.jpg'] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

// Renders the permit for an application row, records it in approved_application_files and returns its stored path.
function generate_permit_file(PDO $pdo, array $row, ?string &$errorOut = null): ?string
{
    $errorOut = null;
    $template = permit_template_path();
    if ($template === null) {
        $errorOut = 'Permit template image not found.';
        return null;
    }

    $approvedDir = UPLOAD_DIR . '/approved_permits';
    if (!is_dir($approvedDir) && !mkdir($approvedDir, 0775, true)) {
        $errorOut = 'Cannot create approved permits folder.';
        return null;
    }

    $savedName = 'permit_' . preg_replace('/[^A-Za-z0-9\-_]/', '_', (string)$row['reference_no']) . '_' . date('Ymd_His') . '.jpg';
    if (!render_business_permit_image($template, $approvedDir . '/' . $savedName, $row)) {
        $errorOut = 'Failed to render permit image.';
        return null;
    }

    $savedRelPath = 'uploads/approved_permits/' . $savedName;
    try {
        $pdo->prepare('DELETE FROM approved_application_files WHERE application_id=?')->execute([(int)$row['id']]);
        $pdo->prepare('INSERT INTO approved_application_files(application_id, file_path, original_name, created_at) VALUES(?,?,?,NOW())')
            ->execute([(int)$row['id'], $savedRelPath, 'Business_Permit_' . (string)$row['reference_no'] . '.jpg']);
    } catch (Throwable $e) {
        $errorOut = 'Failed to save approved permit record.';
        return null;
    }

    return $savedRelPath;
}
