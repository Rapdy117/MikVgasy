<?php

require_once __DIR__ . '/formatters.php';

function resolveUserCounterBaselineScopeId(string $businessSource, ?string $deviceId = null): string
{
    $source = trim($businessSource);
    if ($source === 'radius') {
        return 'radius';
    }

    return trim((string)$deviceId);
}

function normalizeRadiusAccountingTotals(array $rawTotals): array
{
    $sessionSeconds = 0;
    foreach (['session_seconds', 'session_total_seconds', 'total_time'] as $field) {
        if (array_key_exists($field, $rawTotals)) {
            $sessionSeconds = max(0, (int)$rawTotals[$field]);
            break;
        }
    }

    $dataBytes = null;
    foreach (['data_bytes', 'data_consumed_bytes', 'total_bytes'] as $field) {
        if (array_key_exists($field, $rawTotals)) {
            $dataBytes = max(0, (int)round((float)$rawTotals[$field]));
            break;
        }
    }

    if ($dataBytes === null) {
        $dataBytes = max(0, (int)round(
            max(0, (float)($rawTotals['in_bytes'] ?? 0))
            + max(0, (float)($rawTotals['out_bytes'] ?? 0))
        ));
    }

    return [
        'session_seconds' => $sessionSeconds,
        'data_bytes' => $dataBytes,
    ];
}

function resolveRadiusAllocatedSessionSeconds(array $userRow, ?array $profileRow = null): int
{
    $candidates = [
        max(0, (int)($userRow['current_credit_time'] ?? 0)),
        max(0, (int)($userRow['user_session_timeout'] ?? ($userRow['session_timeout'] ?? 0))),
        max(0, (int)($profileRow['session_timeout'] ?? ($userRow['profile_session_timeout'] ?? 0))),
    ];

    foreach ($candidates as $value) {
        if ($value > 0) {
            return $value;
        }
    }

    return 0;
}

function resolveRadiusAllocatedDataMegabytes(array $userRow, ?array $profileRow = null): int
{
    $currentCreditMegabytes = normalizeStoredCreditDataToMegabytes((int)($userRow['current_credit_data'] ?? 0));
    if ($currentCreditMegabytes > 0) {
        return $currentCreditMegabytes;
    }

    $userDataLimit = max(0, (int)($userRow['user_data_limit'] ?? ($userRow['data_limit'] ?? 0)));
    if ($userDataLimit > 0) {
        return $userDataLimit;
    }

    return max(0, (int)($profileRow['data_quota_mb'] ?? ($userRow['profile_data_quota_mb'] ?? 0)));
}

function buildRadiusRuntimeState(
    array $userRow,
    array $rawAccountingTotals,
    array $counterBaseline,
    ?array $profileRow = null
): array {
    $accountingTotals = normalizeRadiusAccountingTotals($rawAccountingTotals);
    $baselineSessionSeconds = max(0, (int)($counterBaseline['imported_session_total_seconds'] ?? 0));
    $baselineDataBytes = max(0, (int)($counterBaseline['imported_data_consumed_bytes'] ?? 0));
    $importedSessionSeconds = max(0, (int)($userRow['imported_session_total_seconds'] ?? 0));
    $importedDataBytes = max(0, (int)($userRow['imported_data_consumed_bytes'] ?? 0));

    $cycleSessionSeconds = max(0, $accountingTotals['session_seconds'] - $baselineSessionSeconds);
    $cycleDataBytes = max(0, $accountingTotals['data_bytes'] - $baselineDataBytes);

    $displaySessionSeconds = $importedSessionSeconds + $cycleSessionSeconds;
    $displayDataBytes = $importedDataBytes + $cycleDataBytes;
    $displayDataMegabytes = (int)round($displayDataBytes / 1024 / 1024);

    $allocatedSessionSeconds = resolveRadiusAllocatedSessionSeconds($userRow, $profileRow);
    $allocatedDataMegabytes = resolveRadiusAllocatedDataMegabytes($userRow, $profileRow);

    return [
        'accounting_session_seconds' => $accountingTotals['session_seconds'],
        'accounting_data_bytes' => $accountingTotals['data_bytes'],
        'baseline_session_seconds' => $baselineSessionSeconds,
        'baseline_data_bytes' => $baselineDataBytes,
        'has_counter_reset' => $baselineSessionSeconds > 0 || $baselineDataBytes > 0,
        'cycle_session_seconds' => $cycleSessionSeconds,
        'cycle_data_bytes' => $cycleDataBytes,
        'imported_session_seconds' => $importedSessionSeconds,
        'imported_data_bytes' => $importedDataBytes,
        'display_session_seconds' => $displaySessionSeconds,
        'display_data_bytes' => $displayDataBytes,
        'display_data_megabytes' => $displayDataMegabytes,
        'allocated_session_seconds' => $allocatedSessionSeconds,
        'allocated_data_megabytes' => $allocatedDataMegabytes,
        'remaining_session_seconds' => max(0, $allocatedSessionSeconds - $displaySessionSeconds),
        'remaining_data_megabytes' => max(0, $allocatedDataMegabytes - $displayDataMegabytes),
    ];
}
