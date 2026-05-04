<?php

function formatVoucherNumber(float $value, int $decimals = 2): string
{
    $formatted = number_format($value, $decimals, '.', ' ');
    if ($decimals > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted;
}

function formatVoucherDataLimitLabel(?int $dataQuotaMb): string
{
    $value = (int)($dataQuotaMb ?? 0);
    if ($value <= 0) {
        return '';
    }

    $kilobytes = $value * 1024;
    if ($kilobytes < 1000) {
        return formatVoucherNumber($kilobytes, 2) . ' KB';
    }

    if ($value < 1000) {
        return formatVoucherNumber($value, 2) . ' MB';
    }

    return formatVoucherNumber($value / 1024, 2) . ' GB';
}

function formatVoucherTimeLimitLabel(?int $seconds): string
{
    $value = (int)($seconds ?? 0);
    if ($value <= 0) {
        return '';
    }

    $days = intdiv($value, 86400);
    $hours = intdiv($value % 86400, 3600);
    $minutes = intdiv($value % 3600, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'j';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . 'm';
    }

    return implode(' ', $parts);
}

function formatVoucherPriceLabel($value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (is_numeric($value)) {
        return formatVoucherNumber((float)$value, 0) . ' Ar';
    }

    return trim((string)$value);
}

function getDefaultVoucherTicketOptions(): array
{
    return [
        'format' => 'small',
        'ssid' => '',
        'dns' => '',
        'show_profile_name' => true,
        'show_rate_limit' => true,
        'show_price' => true,
        'show_data_limit' => true,
        'show_time_limit' => true,
        'show_qr' => false,
        'show_logo' => false,
        'logo_text' => '',
        'logo_url' => '',
    ];
}

function normalizeVoucherTicketOptions(?array $options): array
{
    $normalized = array_merge(getDefaultVoucherTicketOptions(), is_array($options) ? $options : []);
    $normalized['format'] = in_array((string)$normalized['format'], ['small', 'wide'], true) ? (string)$normalized['format'] : 'small';
    $normalized['ssid'] = trim((string)($normalized['ssid'] ?? ''));
    $normalized['dns'] = trim((string)($normalized['dns'] ?? ''));
    $normalized['logo_text'] = trim((string)($normalized['logo_text'] ?? ''));
    $normalized['logo_url'] = trim((string)($normalized['logo_url'] ?? ''));

    foreach ([
        'show_profile_name',
        'show_rate_limit',
        'show_price',
        'show_data_limit',
        'show_time_limit',
        'show_qr',
        'show_logo',
    ] as $booleanKey) {
        $normalized[$booleanKey] = !empty($normalized[$booleanKey]);
    }

    return $normalized;
}

function buildVoucherQrPayload(array $item, array $ticketOptions = [], string $fallbackHost = ''): string
{
    $ticketOptions = normalizeVoucherTicketOptions($ticketOptions);
    $username = trim((string)($item['username'] ?? $item['code'] ?? ''));
    $password = trim((string)($item['password'] ?? ''));
    $host = trim((string)($ticketOptions['dns'] ?? ''));
    if ($host === '') {
        $host = trim((string)($ticketOptions['ssid'] ?? ''));
    }
    if ($host === '') {
        $host = trim($fallbackHost);
    }
    if ($host === '') {
        return '';
    }
    $host = preg_replace('#^https?://#i', '', $host) ?? $host;

    return 'http://' . $host . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);
}

function buildVoucherTicketMetaParts(array $item, array $ticketOptions): array
{
    $ticketOptions = normalizeVoucherTicketOptions($ticketOptions);

    $sessionTimeout = isset($item['session_timeout']) ? (int)$item['session_timeout'] : 0;
    $validityTime = isset($item['validity_time']) ? (int)$item['validity_time'] : 0;
    $effectiveTime = $validityTime > 0 ? $validityTime : $sessionTimeout;

    $parts = [];

    if ($ticketOptions['show_profile_name']) {
        $profileName = trim((string)($item['profile_name'] ?? ''));
        if ($profileName !== '') {
            $parts[] = $profileName;
        }
    }

    if ($ticketOptions['show_rate_limit']) {
        $rateLimit = trim((string)($item['rate_limit'] ?? ''));
        if ($rateLimit !== '') {
            $parts[] = 'Débit ' . $rateLimit;
        }
    }

    if ($ticketOptions['show_price']) {
        $price = formatVoucherPriceLabel($item['selling_price'] ?? $item['price'] ?? '');
        if ($price !== '') {
            $parts[] = 'Prix ' . $price;
        }
    }

    if ($ticketOptions['show_data_limit']) {
        $dataLabel = formatVoucherDataLimitLabel(isset($item['data_quota_mb']) ? (int)$item['data_quota_mb'] : null);
        if ($dataLabel !== '') {
            $parts[] = 'Data ' . $dataLabel;
        }
    }

    if ($ticketOptions['show_time_limit']) {
        $timeLabel = formatVoucherTimeLimitLabel($effectiveTime);
        if ($timeLabel !== '') {
            $parts[] = 'Temps ' . $timeLabel;
        }
    }

    return $parts;
}

function renderVoucherTicketCard(array $item, int $index, string $hotspotName, array $ticketOptions = []): string
{
    $ticketOptions = normalizeVoucherTicketOptions($ticketOptions);
    $username = (string)($item['username'] ?? $item['code'] ?? '-');
    $password = (string)($item['password'] ?? '-');
    $isWide = $ticketOptions['format'] === 'wide';
    $cardClass = $ticketOptions['format'] === 'wide' ? 'voucher-card voucher-card-wide' : 'voucher-card';
    $qrPayload = buildVoucherQrPayload($item, $ticketOptions, $hotspotName);
    $ssid = trim((string)($ticketOptions['ssid'] ?? ''));
    $dns = trim((string)($ticketOptions['dns'] ?? ''));
    $showLogo = (
        !empty($ticketOptions['show_logo'])
        || trim((string)($ticketOptions['logo_url'] ?? '')) !== ''
        || trim((string)($ticketOptions['logo_text'] ?? '')) !== ''
    );
    // QR limité au format wide pour préserver la lisibilité du petit ticket.
    $showQr = $isWide && !empty($ticketOptions['show_qr']);
    // Header = titre court uniquement, pour éviter le doublon DNS.
    $titleLabel = trim($hotspotName !== '' ? $hotspotName : 'Hotspot');
    // DNS/SSID uniquement pour la ligne Login.
    $loginHost = trim($dns !== '' ? $dns : ($ssid !== '' ? $ssid : $hotspotName));
    $loginHost = preg_replace('#^https?://#i', '', $loginHost) ?? $loginHost;
    $sellingPriceLabel = formatVoucherPriceLabel($item['selling_price'] ?? '');
    $priceLabel = $sellingPriceLabel !== '' ? $sellingPriceLabel : formatVoucherPriceLabel($item['price'] ?? '');
    $dataQuota = isset($item['data_quota_mb']) ? (int)$item['data_quota_mb'] : 0;
    $dataLabel = $dataQuota > 0 ? formatVoucherDataLimitLabel($dataQuota) : '';
    $validityLabel = formatVoucherTimeLimitLabel(isset($item['validity_time']) ? (int)$item['validity_time'] : 0);
    $sessionLimitLabel = formatVoucherTimeLimitLabel(isset($item['session_timeout']) ? (int)$item['session_timeout'] : 0);
    $metaPartsWide = [];
    $metaPartsSmall = [];

    $metaPartsWide[] = 'V:' . ($validityLabel !== '' ? $validityLabel : '-');
    $metaPartsSmall[] = 'V:' . ($validityLabel !== '' ? $validityLabel : '-');

    if ($dataLabel !== '') {
        $metaPartsWide[] = 'D:' . $dataLabel;
        $metaPartsSmall[] = 'D:' . $dataLabel;
    }
    if ($sessionLimitLabel !== '') {
        $metaPartsWide[] = 'T:' . $sessionLimitLabel;
        $metaPartsSmall[] = 'T:' . $sessionLimitLabel;
    }

    $metaPartsWide[] = 'P:' . ($priceLabel !== '' ? $priceLabel : '-');
    $metaPartsSmall[] = 'P:' . ($priceLabel !== '' ? $priceLabel : '-');

    $wideMetaLabel = implode(' • ', $metaPartsWide);
    $smallMetaLabel = implode(' - ', $metaPartsSmall);
    $voucherSizeClass = $isWide ? 'mkh-voucher-wide' : 'mkh-voucher-small';
    $innerSizeClass = $isWide ? 'mkh-inner-wide' : 'mkh-inner-small';
    $logoUrl = trim((string)($ticketOptions['logo_url'] ?? ''));
    $logoText = trim((string)($ticketOptions['logo_text'] ?? ''));
    $brandFallback = $logoText !== '' ? $logoText : strtoupper(substr($titleLabel !== '' ? $titleLabel : 'H', 0, 1));
    $wideColspan = 1 + ($showQr ? 1 : 0);
    $safeTitleLabel = $titleLabel !== '' ? $titleLabel : 'Hotspot';

    ob_start();
    ?>
    <div class="<?= htmlspecialchars($cardClass) ?> mikhmon-ticket-shell">
        <table class="mkh-voucher <?= htmlspecialchars($voucherSizeClass) ?>">
            <tbody>
                <tr>
                    <td class="mkh-header">
                        <div class="mkh-header-bar">
                            <?php if ($showLogo): ?>
                                <span class="mkh-header-logo">
                                    <?php if ($logoUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="logo" class="mkh-logo-image">
                                    <?php else: ?>
                                        <span class="mkh-logo-text"><?= htmlspecialchars($brandFallback) ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span class="mkh-title"><?= htmlspecialchars($safeTitleLabel) ?></span>
                            <span class="mkh-num">[<?= (int)($index + 1) ?>]</span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="mkh-body">
                        <table class="mkh-inner <?= htmlspecialchars($innerSizeClass) ?>">
                            <tbody>
                                <?php if ($isWide): ?>
                                    <tr class="mkh-wide-main">
                                        <td class="mkh-cred-cell">
                                            <table class="mkh-credentials">
                                                <?php if ($showQr): ?>
                                                    <tr class="mkh-credentials-label">
                                                        <td>Username</td>
                                                    </tr>
                                                    <tr class="mkh-credentials-value">
                                                        <td><?= htmlspecialchars($username) ?></td>
                                                    </tr>
                                                    <tr class="mkh-credentials-label">
                                                        <td>Password</td>
                                                    </tr>
                                                    <tr class="mkh-credentials-value">
                                                        <td><?= htmlspecialchars($password) ?></td>
                                                    </tr>
                                                <?php else: ?>
                                                    <tr class="mkh-credentials-label">
                                                        <td>Username</td>
                                                        <td>Password</td>
                                                    </tr>
                                                    <tr class="mkh-credentials-value">
                                                        <td><?= htmlspecialchars($username) ?></td>
                                                        <td><?= htmlspecialchars($password) ?></td>
                                                    </tr>
                                                <?php endif; ?>
                                            </table>
                                        </td>
                                        <?php if ($showQr): ?>
                                            <td class="mkh-qr-cell">
                                                <div class="voucher-qr" data-qr-text="<?= htmlspecialchars($qrPayload) ?>"></div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                    <tr>
                                        <td colspan="<?= (int)$wideColspan ?>" class="mkh-meta-line">
                                            <?= htmlspecialchars($wideMetaLabel) ?>
                                        </td>
                                    </tr>
                                    <?php if ($loginHost !== ''): ?>
                                        <tr>
                                            <td colspan="<?= (int)$wideColspan ?>" class="mkh-login-line">
                                                Login: http://<?= htmlspecialchars($loginHost) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($loginHost !== ''): ?>
                                        <tr>
                                            <td colspan="2" class="mkh-login-line mkh-small-login-line">
                                                Login: http://<?= htmlspecialchars($loginHost) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr class="mkh-small-labels">
                                        <td>Username</td>
                                        <td>Password</td>
                                    </tr>
                                    <tr class="mkh-small-credentials">
                                        <td><?= htmlspecialchars($username) ?></td>
                                        <td><?= htmlspecialchars($password) ?></td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="mkh-meta-line mkh-small-meta-line">
                                            <?= htmlspecialchars($smallMetaLabel) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php

    return (string)ob_get_clean();
}
