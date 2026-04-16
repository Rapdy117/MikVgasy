<?php

function parseMikrotikDurationSeconds(?string $value): int
{
    $duration = strtolower(trim((string)$value));
    if ($duration === '') {
        return 0;
    }

    preg_match_all('/(\d+)([wdhms])/', $duration, $matches, PREG_SET_ORDER);
    if (!$matches) {
        return 0;
    }

    $unitMap = [
        'w' => 604800,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];

    $total = 0;
    foreach ($matches as $match) {
        $total += ((int)$match[1]) * ($unitMap[$match[2]] ?? 0);
    }

    return $total;
}

function formatDurationCompactLabel(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }

    $totalMinutes = (int)ceil($seconds / 60);
    $days = intdiv($totalMinutes, 1440);
    $remainderMinutes = $totalMinutes % 1440;
    $hours = intdiv($remainderMinutes, 60);
    $minutes = $remainderMinutes % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'j';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }

    $showMinutes = $minutes > 0 || ($days === 0 && $hours === 0);
    if ($showMinutes) {
        $parts[] = str_pad((string)$minutes, 2, '0', STR_PAD_LEFT) . 'm';
    }

    return implode(' ', $parts);
}

function formatSessionDuration(?int $seconds): string
{
    if ($seconds === null || $seconds <= 0) {
        return '-';
    }

    return formatDurationCompactLabel($seconds);
}

function formatMikrotikDuration(string $value): string
{
    $seconds = parseMikrotikDurationSeconds($value);
    if ($seconds <= 0) {
        return '-';
    }

    return formatDurationCompactLabel($seconds);
}

function formatSpeed(?float $bitsPerSecond): string
{
    if ($bitsPerSecond === null || $bitsPerSecond <= 0) {
        return '0 Mbps';
    }

    return number_format($bitsPerSecond / 1000000, 2, '.', ' ') . ' Mbps';
}

function formatDataVolume(?float $bytes): string
{
    $bytes = (float)($bytes ?? 0);
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return number_format($bytes, $index === 0 ? 0 : 2, '.', ' ') . ' ' . $units[$index];
}
