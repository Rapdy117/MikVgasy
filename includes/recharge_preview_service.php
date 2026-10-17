<?php

require_once __DIR__ . '/device_manager.php';
require_once __DIR__ . '/mikrotik_backend.php';
require_once __DIR__ . '/nas_resolver.php';
require_once __DIR__ . '/radius_credit_runtime.php';
require_once __DIR__ . '/user_schema.php';

final class RechargePreviewConflictException extends RuntimeException {}
function rechargePreviewTokenSecret(): string { $secret = trim((string)($_ENV['RADIUS_MANAGER_PREVIEW_SECRET'] ?? $_SERVER['RADIUS_MANAGER_PREVIEW_SECRET'] ?? '')); return $secret !== '' ? $secret : hash('sha256', 'radius-manager-preview-token-v1', true); }
function rechargePreviewCanonicalize($value) { if (!is_array($value)) return $value; if (array_is_list($value)) return array_map('rechargePreviewCanonicalize', $value); ksort($value); foreach ($value as $key => $item) $value[$key] = rechargePreviewCanonicalize($item); return $value; }
function rechargePreviewContext(array $device, string $username, ?string $profileId, ?string $profileName, string $mode): array { return ['device_id'=>(string)($device['id']??''),'username'=>$username,'profile_id'=>$profileId,'profile_name'=>$profileName,'mode'=>$mode]; }

/** Resolve the canonical SQL profile id for RADIUS/OPNsense flows. */
function resolveRadiusProfileId(PDO $pdo, ?string $profileId, ?string $profileName): string
{
    $id = trim((string)$profileId);
    if ($id !== '' && ctype_digit($id) && (int)$id > 0) {
        $stmt = $pdo->prepare('SELECT id FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$id]);
        if ($stmt->fetchColumn() !== false) {
            return (string)(int)$id;
        }
        throw new RuntimeException('Profil SQL introuvable.');
    }

    $name = trim((string)$profileName);
    if ($name === '') {
        throw new RuntimeException('Profil SQL requis.');
    }
    $stmt = $pdo->prepare('SELECT id FROM profiles WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $resolved = $stmt->fetchColumn();
    if ($resolved === false) {
        throw new RuntimeException('Profil SQL introuvable.');
    }
    return (string)(int)$resolved;
}
function rechargePreviewSnapshot(array $preview): array { return ['current'=>$preview['current']??[],'projected'=>$preview['projected']??[],'can_apply_now'=>(bool)($preview['can_apply_now']??false)]; }
function issueRechargePreviewToken(array $context, array $preview, int $ttlSeconds=300): string { $payload=['exp'=>time()+max(30,$ttlSeconds),'context'=>rechargePreviewCanonicalize($context),'snapshot'=>hash('sha256',json_encode(rechargePreviewCanonicalize(rechargePreviewSnapshot($preview)),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))]; $encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'='); return $encoded.'.'.hash_hmac('sha256',$encoded,rechargePreviewTokenSecret()); }
function validateRechargePreviewToken(string $token, array $context, array $preview): void { $parts=explode('.',trim($token),2); if(count($parts)!==2||!hash_equals(hash_hmac('sha256',$parts[0],rechargePreviewTokenSecret()),$parts[1])) throw new RechargePreviewConflictException('Aperçu invalide ou expiré. Veuillez le recalculer.'); $json=base64_decode(strtr($parts[0],'-_','+/'),true); $payload=is_string($json)?json_decode($json,true):null; if(!is_array($payload)||(int)($payload['exp']??0)<time()) throw new RechargePreviewConflictException('Aperçu expiré. Veuillez le recalculer.'); if(($payload['context']??null)!==rechargePreviewCanonicalize($context)) throw new RechargePreviewConflictException('Le contexte de la recharge a changé. Veuillez recalculer l’aperçu.'); $snapshot=hash('sha256',json_encode(rechargePreviewCanonicalize(rechargePreviewSnapshot($preview)),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); if(!hash_equals((string)($payload['snapshot']??''),$snapshot)) throw new RechargePreviewConflictException('Les données ont changé depuis l’aperçu. Aucun changement appliqué.'); }

function rechargePreviewFormatSecondsLabel(int $seconds): string
{
    if ($seconds <= 0) {
        return '-';
    }
    if ($seconds % 2592000 === 0) {
        return ($seconds / 2592000) . ' mois';
    }
    if ($seconds % 86400 === 0) {
        return ($seconds / 86400) . ' jours';
    }
    if ($seconds % 3600 === 0) {
        return ($seconds / 3600) . ' heures';
    }
    if ($seconds % 60 === 0) {
        return ($seconds / 60) . ' minutes';
    }
    return $seconds . ' secondes';
}

function rechargePreviewFormatMegabytesLabel(?int $megabytes): string
{
    return formatDataMegabytesLabel($megabytes);
}

function rechargePreviewParseExpirationDate(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $matches)) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $matches[0], new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable ? $date : null;
    }
    return null;
}

function rechargePreviewParseDatetimeOrNull(?string $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}

function rechargePreviewFormatExpirationDate(?DateTimeImmutable $date): string
{
    return $date instanceof DateTimeImmutable ? $date->format('Y-m-d') : '-';
}

function rechargePreviewAddSeconds(DateTimeImmutable $date, int $seconds): DateTimeImmutable
{
    if ($seconds <= 0) {
        return $date;
    }
    return $date->modify('+' . $seconds . ' seconds');
}

function resolveRechargeBackendContext(PDO $pdo, array $device, ?array $nasContext = null): array
{
    if (is_array($nasContext)) {
        $businessSource = trim((string)($nasContext['business_source'] ?? ''));
        if ($businessSource === '') {
            $businessSource = nasContextRequireBusinessSource($nasContext);
        }

        return [
            'business_source' => $businessSource,
            'context_device' => is_array($nasContext['device'] ?? null) ? $nasContext['device'] : $device,
            'nas_context' => $nasContext,
        ];
    }

    $businessSource = trim((string)($device['business_source'] ?? ''));
    if ($businessSource === '') {
        $businessSource = resolveDeviceBusinessSource((string)($device['type'] ?? ''));
    }

    if ($businessSource === 'mikrotik_local') {
        return [
            'business_source' => $businessSource,
            'context_device' => $device,
            'nas_context' => null,
        ];
    }

    $resolvedNasContext = resolveNasContextFromInputs($pdo, null, (string)($device['id'] ?? ''));

    return [
        'business_source' => nasContextRequireBusinessSource($resolvedNasContext),
        'context_device' => is_array($resolvedNasContext['device'] ?? null) ? $resolvedNasContext['device'] : $device,
        'nas_context' => $resolvedNasContext,
    ];
}

function buildRechargePreview(PDO $pdo, array $device, string $username, string $profileValue, string $mode, ?string $profileId = null, ?string $profileName = null, ?array $nasContext = null): array
{
    $backendContext = resolveRechargeBackendContext($pdo, $device, $nasContext);
    $nasContext = $backendContext['nas_context'];
    $businessSource = $backendContext['business_source'];
    $isMikrotikBackend = ($businessSource === 'mikrotik_local');
    $contextDevice = $backendContext['context_device'];
    $accountingNasAddress = extractDeviceAddress((string)($contextDevice['host'] ?? ($contextDevice['ip'] ?? '')));

    if ($isMikrotikBackend) {
        $profileValue = $profileName ?: $profileValue;
    } else {
        $profileValue = $profileId ?: $profileValue;
    }

    $current = [
        'profile' => '-',
        'time_limit' => '-',
        'validity' => '-',
        'data_limit' => '-',
        'data_limit_mb' => 0,
        'rate_limit' => '-',
        'expiration' => '-',
    ];
    $profileOffer = [
        'profile' => '-',
        'time_limit' => '-',
        'data_limit' => '-',
        'data_limit_mb' => 0,
        'rate_limit' => '-',
        'shared_users' => '-',
        'validity' => '-',
    ];
    $notes = [];
    $canApplyNow = true;

    if ($isMikrotikBackend) {
        $api = connectToMikrotikApiByDevice($contextDevice);
        try {
            $rawUser = findMikrotikUserByName($api, $username);
            if (!$rawUser) {
                throw new RuntimeException('Utilisateur MikroTik introuvable');
            }

            $currentProfileName = trim((string)($rawUser['profile'] ?? ''));

            $currentProfile = null;
            if ($currentProfileName !== '') {
                $currentProfile = findMikrotikProfileByName($api, $currentProfileName);
            }

            $targetProfile = findMikrotikProfileByName($api, $profileValue);

            $current['profile'] = (string)($rawUser['profile'] ?? '-');

            $remUser = mikrotikUserRemainingFromHotspotUserRow($rawUser);
            $limitUptimeSec = $remUser['limit_uptime_sec'];
            $remainingTimeSec = $remUser['time_remaining_sec'];
            $current['time_limit'] = $limitUptimeSec > 0
                ? rechargePreviewFormatSecondsLabel($remainingTimeSec)
                : 'Illimite';

            $dataRemBytes = $remUser['data_remaining_bytes'];
            $currentMegabytes = 0;
            if ($dataRemBytes !== null) {
                $currentMegabytes = (int)max(0, round((float)$dataRemBytes / 1024 / 1024));
            }
            $current['data_limit'] = $dataRemBytes !== null
                ? rechargePreviewFormatMegabytesLabel($currentMegabytes)
                : 'Illimite';
            $current['data_limit_mb'] = max(0, $currentMegabytes);

            $currentExpiration = rechargePreviewParseExpirationDate((string)($rawUser['comment'] ?? ''));
            $current['expiration'] = rechargePreviewFormatExpirationDate($currentExpiration);

            if ($currentProfile) {
                $currentMeta = parseMikrotikOnLoginMetadata((string)($currentProfile['on-login'] ?? ''));
                $currentValidityRaw = trim((string)($currentMeta['validity'] ?? ''));
                $currentValiditySeconds = parseRouterosIntervalToSeconds($currentValidityRaw);

                if ($currentValiditySeconds <= 0 && preg_match('/^\s*(\d+)\s*s?\s*$/i', $currentValidityRaw, $matches)) {
                    $currentValiditySeconds = (int)$matches[1];
                }

                $current['validity'] = $currentValiditySeconds > 0
                    ? rechargePreviewFormatSecondsLabel($currentValiditySeconds)
                    : ($currentValidityRaw !== '' ? $currentValidityRaw : '-');

                $currentRate = trim((string)($currentProfile['rate-limit'] ?? ''));
                $current['rate_limit'] = $currentRate !== '' ? $currentRate : '-';
            }

            $offerMegabytes = 0;
            if ($targetProfile) {
                $limit = trim((string)($targetProfile['limit-bytes-total'] ?? ''));
                $bytes = $limit !== '' ? (float)$limit : 0;
                if ($bytes > 0) {
                    $offerMegabytes = (int)round($bytes / 1024 / 1024);
                } else {
                    $offerMeta = parseMikrotikOnLoginMetadata((string)($targetProfile['on-login'] ?? ''));
                    $offerMegabytes = max(0, (int)($offerMeta['data_quota_mb'] ?? 0));
                }
            }
        } finally {
            $api->disconnect();
        }

        if (!$targetProfile) {
            throw new RuntimeException('Profil MikroTik introuvable');
        }

        $metadata = parseMikrotikOnLoginMetadata((string)($targetProfile['on-login'] ?? ''));
        $offerTimeSeconds = mikrotikProfileOfferTimeSeconds($targetProfile);
        $offerMegabytes = $offerMegabytes ?? 0;

        $profileOffer['profile'] = $profileValue;
        $profileOffer['time_limit'] = rechargePreviewFormatSecondsLabel($offerTimeSeconds);
        $profileOffer['data_limit'] = rechargePreviewFormatMegabytesLabel($offerMegabytes);
        $profileOffer['data_limit_mb'] = max(0, $offerMegabytes);
        $profileOffer['rate_limit'] = trim((string)($targetProfile['rate-limit'] ?? '')) !== '' ? trim((string)$targetProfile['rate-limit']) : '-';
        $profileOffer['shared_users'] = (string)((int)($targetProfile['shared-users'] ?? 0));
        $offerValidityRaw = trim((string)($metadata['validity'] ?? ''));
        $offerValiditySeconds = parseRouterosIntervalToSeconds($offerValidityRaw);
        if ($offerValiditySeconds <= 0 && preg_match('/^\s*(\d+)\s*s?\s*$/i', $offerValidityRaw, $matches)) {
            $offerValiditySeconds = (int)$matches[1];
        }
        $profileOffer['validity'] = $offerValiditySeconds > 0
            ? rechargePreviewFormatSecondsLabel($offerValiditySeconds)
            : ($offerValidityRaw !== '' ? $offerValidityRaw : '-');

        if ($offerMegabytes <= 0) {
            $notes[] = 'Le profil choisi ne porte pas de quota data defini. Seul le temps sera applique pour la recharge.';
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $projectedSeconds = $remainingTimeSec;
        if ($mode === 'replace_offer') {
            $projectedSeconds = $offerTimeSeconds;
        } elseif (in_array($mode, ['extend_offer', 'accumulate_offer'], true)) {
            $projectedSeconds = $remainingTimeSec + $offerTimeSeconds;
        }

        $projectedProfile = $profileValue;
        $projectedExpiration = '-';
        $projectedMegabytes = $currentMegabytes;
        if ($mode === 'replace_offer') {
            $projectedExpiration = '-';
            $projectedMegabytes = $offerMegabytes;
        }

        if ($mode === 'extend_offer') {
            $projectedProfile = $current['profile'];
            $projectedMegabytes = $currentMegabytes + $offerMegabytes;
            $projectedExpiration = $current['expiration'];
            if ($offerTimeSeconds <= 0 && $offerMegabytes <= 0) {
                $projectedSeconds = $remainingTimeSec;
                $projectedMegabytes = $currentMegabytes;
                $canApplyNow = false;
                $notes[] = 'Le profil choisi ne porte ni quota temps ni quota data a ajouter.';
            }
            $notes[] = 'En mode Rechargement, le profil courant et l expiration sont conserves. Les compteurs routeur ne sont pas remis a zero ; seules les limites temps/data presentes dans l offre sont ajoutees.';
        }

        if ($mode === 'accumulate_offer') {
            $projectedProfile = $current['profile'];
            $projectedMegabytes = $currentMegabytes + $offerMegabytes;
            if ($current['profile'] !== $profileValue) {
                $notes[] = 'Le cumul n est possible que si le profil choisi est le meme que le profil courant.';
                $canApplyNow = false;
            }
            $projectedExpiration = ($currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today)
                ? rechargePreviewFormatExpirationDate(rechargePreviewAddSeconds($currentExpiration, $offerValiditySeconds))
                : '-';
            if (!$canApplyNow) {
                $projectedSeconds = $remainingTimeSec;
                $projectedMegabytes = $currentMegabytes;
                $projectedExpiration = $current['expiration'];
            }
            $notes[] = 'En mode Cumuler l offre, le profil courant est conserve. Temps restant et Data restante s ajoutent a l offre, et la validite se rajoute a la date d expiration existante.';
        }

        $projectedRate = $mode === 'replace_offer' ? $profileOffer['rate_limit'] : '-';
        if ($mode === 'accumulate_offer' && $canApplyNow) {
            $projectedRate = $profileOffer['rate_limit'];
        }

        $projected = [
            'profile' => $projectedProfile,
            'time_limit' => rechargePreviewFormatSecondsLabel($projectedSeconds),
            'validity' => $mode === 'replace_offer' ? $profileOffer['validity'] : $current['validity'],
            'data_limit' => rechargePreviewFormatMegabytesLabel($projectedMegabytes),
            'data_limit_mb' => max(0, $projectedMegabytes),
            'rate_limit' => $projectedRate,
            'expiration' => $projectedExpiration,
        ];
    } else {
        ensureUsersExtendedSchema($pdo);
        $userStmt = $pdo->prepare('
            SELECT
                u.username,
                u.profile_id,
                u.expiration_date,
                u.session_timeout AS user_session_timeout,
                u.data_limit AS user_data_limit,
                u.current_credit_time,
                u.current_credit_data,
                u.imported_session_total_seconds,
                u.imported_data_consumed_bytes,
                fl.first_login,
                p.name AS profile_name,
                p.session_timeout AS profile_session_timeout,
                p.validity_time AS profile_validity_time,
                p.data_quota_mb AS profile_data_quota_mb,
                p.rate_limit,
                p.simultaneous_use
            FROM users u
            LEFT JOIN (
                SELECT username, MIN(acctstarttime) AS first_login
                FROM radacct
                WHERE acctstarttime IS NOT NULL
                GROUP BY username
            ) fl ON fl.username = u.username
            LEFT JOIN profiles p ON u.profile_id = p.id
            WHERE u.username = ?
            LIMIT 1
        ');
        $userStmt->execute([$username]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            throw new RuntimeException('Utilisateur introuvable');
        }

        $current['profile'] = (string)($userRow['profile_name'] ?? '-');
        if (in_array($mode, ['extend_offer', 'accumulate_offer'], true) && (int)($userRow['profile_id'] ?? 0) <= 0) {
            throw new RuntimeException('Profil courant introuvable : le rechargement conserve le profil existant.');
        }
        $acctSql = 'SELECT SUM(acctsessiontime) AS total_time, SUM(acctinputoctets) AS in_bytes, SUM(acctoutputoctets) AS out_bytes FROM radacct WHERE username = ?';
        $acctParams = [$username];
        if ($accountingNasAddress !== '') {
            $acctSql .= ' AND nasipaddress = ?';
            $acctParams[] = $accountingNasAddress;
        }
        $acctStmt = $pdo->prepare($acctSql);
        $acctStmt->execute($acctParams);
        $acctRow = $acctStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $baselineScopeId = resolveUserCounterBaselineScopeId($businessSource, (string)($contextDevice['id'] ?? $device['id'] ?? ''));
        $counterBaseline = loadUserCounterBaseline($pdo, $baselineScopeId, $username);
        $runtimeState = buildRadiusRuntimeState($userRow, $acctRow, $counterBaseline);
        $hasCounterReset = $runtimeState['has_counter_reset'];
        $currentSeconds = $runtimeState['remaining_session_seconds'];
        $currentMegabytes = $runtimeState['remaining_data_megabytes'];
        $current['time_limit'] = rechargePreviewFormatSecondsLabel($currentSeconds);
        $current['validity'] = rechargePreviewFormatSecondsLabel((int)($userRow['profile_validity_time'] ?? 0));
        $current['data_limit'] = rechargePreviewFormatMegabytesLabel($currentMegabytes);
        $current['data_limit_mb'] = $currentMegabytes;
        $current['rate_limit'] = trim((string)($userRow['rate_limit'] ?? '')) !== '' ? trim((string)$userRow['rate_limit']) : '-';
        $explicitExpiration = trim((string)($userRow['expiration_date'] ?? ''));
        $computedExpiration = $explicitExpiration !== '' ? $explicitExpiration : null;
        if ($computedExpiration === null && !$hasCounterReset) {
            $firstLogin = rechargePreviewParseDatetimeOrNull((string)($userRow['first_login'] ?? ''));
            $validitySeconds = (int)($userRow['profile_validity_time'] ?? 0);
            if ($firstLogin instanceof DateTimeImmutable && $validitySeconds > 0) {
                $computedExpiration = $firstLogin->modify('+' . $validitySeconds . ' seconds')->format('Y-m-d');
            }
        }
        $current['expiration'] = $computedExpiration !== null ? $computedExpiration : '-';

        $profileId = (int)$profileValue;
        $profileStmt = $pdo->prepare('SELECT id, name, session_timeout, validity_time, data_quota_mb, rate_limit, simultaneous_use FROM profiles WHERE id = ? LIMIT 1');
        $profileStmt->execute([$profileId]);
        $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!$profileRow) {
            throw new RuntimeException('Profil introuvable');
        }

        $offerSessionSeconds = (int)($profileRow['session_timeout'] ?? 0);
        $offerValiditySeconds = (int)($profileRow['validity_time'] ?? 0);
        $offerMegabytes = (int)($profileRow['data_quota_mb'] ?? 0);
        $profileOffer['profile'] = (string)($profileRow['name'] ?? '-');
        $profileOffer['time_limit'] = rechargePreviewFormatSecondsLabel($offerSessionSeconds);
        $profileOffer['data_limit'] = rechargePreviewFormatMegabytesLabel($offerMegabytes);
        $profileOffer['data_limit_mb'] = max(0, $offerMegabytes);
        $profileOffer['rate_limit'] = trim((string)($profileRow['rate_limit'] ?? '')) !== '' ? trim((string)$profileRow['rate_limit']) : '-';
        $profileOffer['shared_users'] = (string)((int)($profileRow['simultaneous_use'] ?? 0));
        $profileOffer['validity'] = rechargePreviewFormatSecondsLabel($offerValiditySeconds);

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $currentExpiration = rechargePreviewParseExpirationDate((string)($computedExpiration ?? ''));
        $projectedSeconds = $mode === 'replace_offer' ? $offerSessionSeconds : ($currentSeconds + $offerSessionSeconds);
        $projectedMegabytes = $mode === 'replace_offer' ? $offerMegabytes : ($currentMegabytes + $offerMegabytes);
        $projectedProfile = (string)($profileRow['name'] ?? '-');
        $projectedExpirationDate = null;
        if ($mode !== 'replace_offer' && $currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today) {
            $projectedExpirationDate = rechargePreviewAddSeconds($currentExpiration, $offerValiditySeconds);
        }
        $projectedExpiration = rechargePreviewFormatExpirationDate($projectedExpirationDate);

        if ($mode === 'extend_offer') {
            $projectedProfile = $current['profile'];
            $projectedExpiration = $current['expiration'];
            if ($offerSessionSeconds <= 0 && $offerMegabytes <= 0) {
                $projectedSeconds = $currentSeconds;
                $projectedMegabytes = $currentMegabytes;
                $canApplyNow = false;
                $notes[] = 'Le profil choisi ne porte ni quota temps ni quota data a ajouter.';
            }
            $notes[] = 'En mode Rechargement, le profil courant et l expiration sont conserves. Les compteurs RADIUS ne sont pas reinitialises ; seules les limites temps/data presentes dans l offre sont ajoutees.';
        }

        if ($mode === 'accumulate_offer') {
            $projectedProfile = $current['profile'];
            if ($current['profile'] !== (string)($profileRow['name'] ?? '')) {
                $notes[] = 'Le cumul n est possible que si le profil choisi est le meme que le profil courant.';
                $canApplyNow = false;
            }
            $projectedExpiration = ($currentExpiration instanceof DateTimeImmutable && $currentExpiration >= $today)
                ? rechargePreviewFormatExpirationDate(rechargePreviewAddSeconds($currentExpiration, $offerValiditySeconds))
                : '-';
            if (!$canApplyNow) {
                $projectedSeconds = $currentSeconds;
                $projectedMegabytes = $currentMegabytes;
                $projectedExpiration = $current['expiration'];
            }
            $notes[] = 'En mode Cumuler l offre, Time Limit et Data Limit s ajoutent a l existant et la validite se rajoute a l expiration actuelle.';
        }

        $projectedRate = $mode === 'replace_offer' ? $profileOffer['rate_limit'] : '-';
        if ($mode === 'accumulate_offer' && $canApplyNow) {
            $projectedRate = $profileOffer['rate_limit'];
        }

        $projected = [
            'profile' => $projectedProfile,
            'time_limit' => rechargePreviewFormatSecondsLabel($projectedSeconds),
            'validity' => $mode === 'replace_offer' ? $profileOffer['validity'] : '-',
            'data_limit' => rechargePreviewFormatMegabytesLabel($projectedMegabytes),
            'data_limit_mb' => max(0, $projectedMegabytes),
            'rate_limit' => $projectedRate,
            'expiration' => $projectedExpiration,
        ];
    }

    $modeLabel = match ($mode) {
        'replace_offer' => 'Changement d\'offre',
        'extend_offer' => 'Rechargement',
        'accumulate_offer' => 'Reabonnement',
        default => $mode,
    };

    $applyLabel = $isMikrotikBackend
        ? match ($mode) {
            'replace_offer' => 'Appliquer le changement sur MikroTik',
            'extend_offer' => 'Appliquer le rechargement sur MikroTik',
            'accumulate_offer' => 'Appliquer le reabonnement sur MikroTik',
            default => 'Valider la recharge',
        }
        : match ($mode) {
            'replace_offer' => 'Appliquer le changement sur backend RADIUS/OPNsense',
            'extend_offer' => 'Appliquer le rechargement sur backend RADIUS/OPNsense',
            'accumulate_offer' => 'Appliquer le reabonnement sur backend RADIUS/OPNsense',
            default => 'Valider la recharge',
        };

    return [
        'device_type' => deviceTypeLabelForApiResponse(array_merge($device, ['type' => (string)($contextDevice['type'] ?? $device['type'] ?? '')])),
        'mode_label' => $modeLabel,
        'can_apply_now' => $canApplyNow,
        'apply_label' => $applyLabel,
        'current' => $current,
        'offer' => $profileOffer,
        'projected' => $projected,
        'notes' => $notes,
    ];
}
