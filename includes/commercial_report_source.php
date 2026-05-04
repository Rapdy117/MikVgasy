<?php

function commercialReportEntriesUnionSql(): string
{
    return "
        SELECT
            'recharge' AS source_type,
            rh.id AS source_id,
            CASE
                WHEN rh.created_at IS NULL THEN NULL
                WHEN TRIM(CAST(rh.created_at AS CHAR)) = '' THEN NULL
                WHEN CAST(rh.created_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(rh.created_at AS CHAR)
            END AS created_at,
            rh.device_id,
            rh.username,
            rh.profile_name,
            rh.mode,
            rh.operator_username,
            rh.effect_summary,
            COALESCE(rh.amount_value, 0) AS amount_value,
            COALESCE(p.account_type, '') AS profile_type
        FROM recharge_history rh
        LEFT JOIN profiles p ON p.name = rh.profile_name
        UNION ALL
        SELECT
            'voucher_first_login' AS source_type,
            v.id AS source_id,
            CASE
                WHEN v.used_at IS NULL THEN NULL
                WHEN TRIM(CAST(v.used_at AS CHAR)) = '' THEN NULL
                WHEN CAST(v.used_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(v.used_at AS CHAR)
            END AS created_at,
            v.device_id AS device_id,
            COALESCE(NULLIF(v.username, ''), v.used_by) AS username,
            COALESCE(NULLIF(v.profile_name, ''), p.name, '') AS profile_name,
            'voucher_first_login' AS mode,
            v.printed_by AS operator_username,
            '1er login voucher' AS effect_summary,
            COALESCE(v.price, p.price, 0) AS amount_value,
            COALESCE(p.account_type, '') AS profile_type
        FROM vouchers v
        LEFT JOIN profiles p ON p.id = v.profile_id
        WHERE v.used_at IS NOT NULL
        UNION ALL
        SELECT
            'user_create_first_login' AS source_type,
            oh.id AS source_id,
            CASE
                WHEN fl.first_login IS NULL THEN NULL
                WHEN TRIM(CAST(fl.first_login AS CHAR)) = '' THEN NULL
                WHEN CAST(fl.first_login AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(fl.first_login AS CHAR)
            END AS created_at,
            oh.device_id AS device_id,
            oh.target_name AS username,
            oh.profile_name AS profile_name,
            'user_create_first_login' AS mode,
            oh.actor_username AS operator_username,
            '1er login compte' AS effect_summary,
            0 AS amount_value,
            COALESCE(p.account_type, '') AS profile_type
        FROM (
            SELECT MAX(id) AS id
            FROM operation_history
            WHERE operation_type = 'user_create'
            GROUP BY target_name
        ) latest_oh
        INNER JOIN operation_history oh ON oh.id = latest_oh.id
        INNER JOIN (
            SELECT username, MIN(acctstarttime) AS first_login
            FROM radacct
            WHERE acctstarttime IS NOT NULL
            GROUP BY username
        ) fl ON fl.username = oh.target_name
        LEFT JOIN profiles p ON p.name = oh.profile_name
        UNION ALL
        SELECT
            oh.operation_type AS source_type,
            oh.id AS source_id,
            CASE
                WHEN oh.created_at IS NULL THEN NULL
                WHEN TRIM(CAST(oh.created_at AS CHAR)) = '' THEN NULL
                WHEN CAST(oh.created_at AS CHAR) = '0000-00-00 00:00:00' THEN NULL
                ELSE CAST(oh.created_at AS CHAR)
            END AS created_at,
            oh.device_id,
            oh.target_name AS username,
            oh.profile_name,
            oh.operation_type AS mode,
            oh.actor_username AS operator_username,
            oh.summary AS effect_summary,
            0 AS amount_value,
            COALESCE(p.account_type, '') AS profile_type
        FROM (
            SELECT MAX(id) AS id
            FROM operation_history
            WHERE operation_scope = 'commercial'
              AND operation_type IN ('user_remove_record', 'user_notice_record')
            GROUP BY operation_type, COALESCE(NULLIF(target_ref, ''), target_name)
        ) latest_oh
        INNER JOIN operation_history oh ON oh.id = latest_oh.id
        LEFT JOIN profiles p ON p.name = oh.profile_name
    ";
}

function commercialReportCreatedAtDateSql(): string
{
    return 'LEFT(created_at, 10)';
}

function commercialReportCreatedAtMonthSql(): string
{
    return 'LEFT(created_at, 7)';
}

function commercialReportSummary(PDO $pdo): array
{
    $commercialEntriesSql = commercialReportEntriesUnionSql();

    $todayStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(amount_value), 0) AS total_amount
        FROM ($commercialEntriesSql) commercial_entries
        WHERE created_at IS NOT NULL
          AND created_at <> ''
          AND DATE(created_at) = CURDATE()
    ");
    $today = $todayStmt ? ($todayStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $monthStmt = $pdo->query("
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(amount_value), 0) AS total_amount
        FROM ($commercialEntriesSql) commercial_entries
        WHERE created_at IS NOT NULL
          AND created_at <> ''
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
    ");
    $month = $monthStmt ? ($monthStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    $trendStmt = $pdo->query("
        SELECT
            DAY(created_at) AS sale_day,
            COUNT(*) AS total
        FROM ($commercialEntriesSql) commercial_entries
        WHERE created_at IS NOT NULL
          AND created_at <> ''
          AND YEAR(created_at) = YEAR(CURDATE())
          AND MONTH(created_at) = MONTH(CURDATE())
        GROUP BY DAY(created_at)
        ORDER BY sale_day ASC
    ");
    $trendRaw = $trendStmt ? ($trendStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];

    $daysInMonth = (int)date('t');
    $trend = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $trend[] = [
            'day' => $day,
            'total' => (int)($trendRaw[$day] ?? 0),
        ];
    }

    return [
        'today_count' => (int)($today['total_count'] ?? 0),
        'today_amount' => (float)($today['total_amount'] ?? 0),
        'month_count' => (int)($month['total_count'] ?? 0),
        'month_amount' => (float)($month['total_amount'] ?? 0),
        'trend' => $trend,
    ];
}
