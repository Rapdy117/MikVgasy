<?php

if (!function_exists('formatNumberWithThousands')) {
    function formatNumberWithThousands(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals, '.', ' ');
        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted;
    }
}

if (!function_exists('formatDataUnitNumber')) {
    function formatDataUnitNumber(float $value): string
    {
        return formatNumberWithThousands($value, 2);
    }
}

if (!function_exists('formatDataKilobytesLabel')) {
    function formatDataKilobytesLabel(float $kilobytes): string
    {
        $value = max(0, $kilobytes);
        if ($value <= 0) {
            return '-';
        }

        if ($value < 1000) {
            return formatDataUnitNumber($value) . ' KB';
        }

        $megabytes = $value / 1024;
        if ($megabytes < 1000) {
            return formatDataUnitNumber($megabytes) . ' MB';
        }

        return formatDataUnitNumber($megabytes / 1024) . ' GB';
    }
}

if (!function_exists('formatDataBytesLabel')) {
    function formatDataBytesLabel($bytes): string
    {
        return formatDataKilobytesLabel(max(0, (float)$bytes) / 1024);
    }
}

if (!function_exists('formatDataMegabytesLabel')) {
    function formatDataMegabytesLabel($megabytes): string
    {
        return formatDataKilobytesLabel(max(0, (float)($megabytes ?? 0)) * 1024);
    }
}

if (!function_exists('splitMegabytesToDataUnitParts')) {
    function splitMegabytesToDataUnitParts($megabytes): array
    {
        $value = max(0, (float)($megabytes ?? 0));
        if ($value <= 0) {
            return [
                'value' => '',
                'unit' => 'GB',
            ];
        }

        $kilobytes = $value * 1024;
        if ($kilobytes < 1000) {
            return [
                'value' => formatDataUnitNumber($kilobytes),
                'unit' => 'KB',
            ];
        }

        if ($value < 1000) {
            return [
                'value' => formatDataUnitNumber($value),
                'unit' => 'MB',
            ];
        }

        return [
            'value' => formatDataUnitNumber($value / 1024),
            'unit' => 'GB',
        ];
    }
}

if (!function_exists('formatMikrotikBytesLimit')) {
    function formatMikrotikBytesLimit($bytes): string
    {
        return formatDataBytesLabel($bytes);
    }
}

if (!function_exists('formatMoneyLabel')) {
    function formatMoneyLabel($value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '-';
        }

        if (!is_numeric($value)) {
            return trim((string)$value);
        }

        return formatNumberWithThousands((float)$value, 2);
    }
}

if (!function_exists('formatMikrotikExpiration')) {
    function formatMikrotikExpiration(?string $comment): string
    {
        $value = trim((string)$comment);
        if ($value === '') {
            return '-';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches)) {
            return $matches[0];
        }

        return '-';
    }
}

if (!function_exists('formatDurationCompactLabel')) {
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
}

if (!function_exists('formatQuotaMbLabel')) {
    function formatQuotaMbLabel($value): string
    {
        return formatDataMegabytesLabel($value);
    }
}

if (!function_exists('formatProfileDurationLabel')) {
    function formatProfileDurationLabel($seconds): string
    {
        $value = (int)($seconds ?? 0);
        if ($value <= 0) {
            return '-';
        }

        return formatDurationCompactLabel($value);
    }
}

if (!function_exists('formatConsumedDurationLabel')) {
    function formatConsumedDurationLabel($seconds): string
    {
        $value = (int)($seconds ?? 0);
        if ($value <= 0) {
            return '-';
        }

        return formatDurationCompactLabel($value);
    }
}

if (!function_exists('normalizeStoredCreditDataToMegabytes')) {
    function normalizeStoredCreditDataToMegabytes(int $rawValue): int
    {
        $value = max(0, $rawValue);
        if ($value <= 0) {
            return 0;
        }

        if ($value > 1024 * 1024) {
            return (int)round($value / 1024 / 1024);
        }

        return $value;
    }
}

if (!function_exists('formatDurationOrUnlimited')) {
    function formatDurationOrUnlimited(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return 'Illimite';
        }

        return formatDurationCompactLabel($seconds);
    }
}

if (!function_exists('parseMikrotikDurationSeconds')) {
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
}

if (!function_exists('formatMikrotikValidity')) {
    function formatMikrotikValidity(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '-';
        }

        $seconds = parseMikrotikDurationSeconds($value);
        if ($seconds > 0) {
            return formatDurationCompactLabel($seconds);
        }

        return trim($value);
    }
}

if (!function_exists('formatProfileMoneyLabel')) {
    function formatProfileMoneyLabel($value): string
    {
        return formatMoneyLabel($value);
    }
}

if (!function_exists('formatProfileDataQuotaLabel')) {
    function formatProfileDataQuotaLabel($value): string
    {
        return formatDataMegabytesLabel($value);
    }
}

if (!function_exists('splitRateLimit')) {
    function splitRateLimit(?string $rateLimit): array
    {
        $result = [
            'upload_value' => '',
            'upload_unit' => 'M',
            'download_value' => '',
            'download_unit' => 'M',
        ];

        $value = trim((string)$rateLimit);
        if ($value === '' || strpos($value, '/') === false) {
            return $result;
        }

        [$upload, $download] = explode('/', $value, 2);

        if (preg_match('/^\s*([\d.]+)\s*([KM])\s*$/i', trim($upload), $matches)) {
            $result['upload_value'] = $matches[1];
            $result['upload_unit'] = strtoupper($matches[2]);
        }

        if (preg_match('/^\s*([\d.]+)\s*([KM])\s*$/i', trim($download), $matches)) {
            $result['download_value'] = $matches[1];
            $result['download_unit'] = strtoupper($matches[2]);
        }

        return $result;
    }
}

if (!function_exists('splitSecondsToDurationParts')) {
    function splitSecondsToDurationParts(?int $seconds): array
    {
        $result = [
            'value' => '',
            'unit' => 'hours',
        ];

        if ($seconds === null || $seconds <= 0) {
            return $result;
        }

        if ($seconds % 2592000 === 0) {
            $result['value'] = (string)($seconds / 2592000);
            $result['unit'] = 'months';
            return $result;
        }

        if ($seconds % 86400 === 0) {
            $result['value'] = (string)($seconds / 86400);
            $result['unit'] = 'days';
            return $result;
        }

        if ($seconds % 3600 === 0) {
            $result['value'] = (string)($seconds / 3600);
            $result['unit'] = 'hours';
            return $result;
        }

        if ($seconds % 60 === 0) {
            $result['value'] = (string)($seconds / 60);
            $result['unit'] = 'minutes';
            return $result;
        }

        $result['value'] = (string)max(1, (int)round($seconds / 3600));
        return $result;
    }
}
