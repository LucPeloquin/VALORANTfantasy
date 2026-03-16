<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$slug = (string)($_GET['slug'] ?? '');
$team = $slug !== '' ? getPublicTeamBySlug($slug) : null;
if (!$team || (int)$team['is_public'] !== 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Team not found';
    exit;
}

$score = computeFantasyTeamScore((int)$team['id']);
$lineup = $score['lineup'];

$width = 1200;
$height = 630;
$im = imagecreatetruecolor($width, $height);
if (!$im) {
    http_response_code(500);
    exit('Image generation failed');
}

$bg = imagecolorallocate($im, 10, 22, 18);
$panel = imagecolorallocate($im, 20, 40, 32);
$panel2 = imagecolorallocate($im, 28, 54, 44);
$accent = imagecolorallocate($im, 63, 224, 150);
$text = imagecolorallocate($im, 227, 242, 235);
$muted = imagecolorallocate($im, 160, 193, 178);
$line = imagecolorallocate($im, 41, 88, 69);

imagefilledrectangle($im, 0, 0, $width, $height, $bg);
imagefilledrectangle($im, 28, 28, $width - 28, $height - 28, $panel);
imagefilledrectangle($im, 40, 40, $width - 40, 130, $panel2);

// Accent stripe
imagefilledrectangle($im, 40, 130, $width - 40, 136, $accent);

$title = 'VCT Fantasy Team';
$teamName = trim((string)$team['team_name']);
$owner = (string)($team['display_name'] ?: $team['username']);
$subtitle = $teamName . '  -  ' . $owner . '  -  ' . formatPoints((float)$score['total']) . ' pts';
$eventLine = (string)$team['event_name'] . '  -  ' . (string)$team['league_name'];

drawText($im, 58, 72, $title, $accent, 5);
drawText($im, 58, 102, truncateText($subtitle, 120), $text, 5);
drawText($im, 58, 122, truncateText($eventLine, 120), $muted, 3);

$startY = 170;
$rowH = 78;
$maxRows = min(5, count($lineup));
for ($i = 0; $i < $maxRows; $i++) {
    $rowY = $startY + ($i * $rowH);
    $row = $lineup[$i];

    imagefilledrectangle($im, 58, $rowY, $width - 58, $rowY + 62, $panel2);
    imagerectangle($im, 58, $rowY, $width - 58, $rowY + 62, $line);

    $powerLabel = powerCatalog()[$row['power_key']]['label'] ?? (string)$row['power_key'];
    $roleLabel = roleCatalog()[$row['role_key']]['label'] ?? (string)$row['role_key'];
    $totalPts = formatPoints((float)$row['score']['total']) . ' pts';

    drawText($im, 72, $rowY + 20, truncateText((string)$row['alias'], 26), $text, 5);
    drawText($im, 72, $rowY + 42, truncateText($row['short_name'] . ' | ' . $roleLabel . ' | ' . $powerLabel, 90), $muted, 3);
    drawText($im, 980, $rowY + 34, $totalPts, $accent, 5);
}

$footer = 'Share this team at ' . appBaseUrl() . '/team.php?slug=' . (string)$team['share_slug'];
drawText($im, 58, $height - 38, truncateText($footer, 140), $muted, 2);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=300');
imagepng($im);
imagedestroy($im);

function drawText(GdImage $im, int $x, int $y, string $text, int $color, int $font): void
{
    $drawY = (int)round($y - (imagefontheight($font) / 2));
    imagestring($im, $font, $x, $drawY, $text, $color);
}

function truncateText(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, max(0, $max - 3)) . '...';
}
