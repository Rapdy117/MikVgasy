<?php
/**
 * Corps du tbody pour la liste des sessions (page complète et partial ?_partial=sessions).
 * Variables attendues : $sessions, $isMikrotikSessions, $isOpnsenseSessions,
 * $canDisconnectRemotely, $sessionProfileMap (comme sessions_list.php).
 */
?>
                                <?php if (!$sessions): ?>
                                <tr data-sort-disabled="1">
                                    <?php if ($isOpnsenseSessions): ?>
                                    <td colspan="11" class="text-center">Aucune session active</td>
                                    <?php else: ?>
                                    <td colspan="13" class="text-center">Aucune session active</td>
                                    <?php endif; ?>
                                </tr>
                                <?php else: ?>
                                <?php if ($isMikrotikSessions): ?>
                                <?php foreach ($sessions as $index => $session): ?>
                                <?php
                                $sessionId = (string)($session['id'] ?? '');
                                $downloadBytes = (float)($session['bytes_in'] ?? 0);
                                $uploadBytes = (float)($session['bytes_out'] ?? 0);
                                $durationSeconds = parseMikrotikDurationSeconds((string)($session['uptime'] ?? ''));
                                $txBitsPerSecond = $durationSeconds > 0 ? ($downloadBytes * 8) / $durationSeconds : 0.0;
                                $rxBitsPerSecond = $durationSeconds > 0 ? ($uploadBytes * 8) / $durationSeconds : 0.0;
                                ?>
                                <tr
                                    id="session-row-<?= htmlspecialchars($sessionId !== '' ? $sessionId : ('mik_' . $index)) ?>"
                                    data-id="<?= htmlspecialchars($sessionId !== '' ? $sessionId : ('mik_' . $index), ENT_QUOTES) ?>"
                                    data-session="<?= htmlspecialchars((string)($session['server'] ?: '-'), ENT_QUOTES) ?>"
                                    data-username="<?= htmlspecialchars((string)($session['user'] ?: '-'), ENT_QUOTES) ?>"
                                    data-profile="<?= htmlspecialchars((string)($session['profile'] ?: '-'), ENT_QUOTES) ?>"
                                    data-address="<?= htmlspecialchars((string)($session['address'] ?: '-'), ENT_QUOTES) ?>"
                                    data-mac="<?= htmlspecialchars((string)($session['mac'] ?: '-'), ENT_QUOTES) ?>"
                                    data-duration="<?= htmlspecialchars((string)$durationSeconds, ENT_QUOTES) ?>"
                                    data-rx_speed="<?= htmlspecialchars((string)$rxBitsPerSecond, ENT_QUOTES) ?>"
                                    data-tx_speed="<?= htmlspecialchars((string)$txBitsPerSecond, ENT_QUOTES) ?>"
                                    data-upload="<?= htmlspecialchars((string)$uploadBytes, ENT_QUOTES) ?>"
                                    data-download="<?= htmlspecialchars((string)$downloadBytes, ENT_QUOTES) ?>"
                                    data-login="<?= htmlspecialchars((string)($session['login_by'] ?: ($session['session_time_left'] ?: '-')), ENT_QUOTES) ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', [
                                        (string)($session['server'] ?: '-'),
                                        (string)($session['user'] ?: '-'),
                                        (string)($session['profile'] ?: '-'),
                                        (string)($session['address'] ?: '-'),
                                        (string)($session['mac'] ?: '-'),
                                        (string)($session['login_by'] ?: ($session['session_time_left'] ?: '-')),
                                    ])), ENT_QUOTES) ?>"
                                >
                                    <td><?= htmlspecialchars($sessionId !== '' ? $sessionId : ('mik_' . $index)) ?></td>
                                    <td><?= htmlspecialchars($session['server'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($session['user'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($session['profile'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($session['address'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($session['mac'] ?: '-') ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatMikrotikDuration((string)($session['uptime'] ?? ''))) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($txBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($rxBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($downloadBytes)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($uploadBytes)) ?></td>
                                    <td><?= htmlspecialchars($session['login_by'] ?: ($session['session_time_left'] ?: '-')) ?></td>
                                    <td class="action-cell">
                                        <button
                                            type="button"
                                            class="btn btn-delete btn-sm session-action-btn"
                                            <?= $sessionId !== '' ? '' : 'disabled title="ID de session MikroTik manquant"' ?>
                                            onclick="disconnectSession('<?= htmlspecialchars($sessionId, ENT_QUOTES) ?>', '<?= htmlspecialchars($sessionId !== '' ? $sessionId : ('mik_' . $index), ENT_QUOTES) ?>')">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php elseif ($isOpnsenseSessions): ?>
                                <?php foreach ($sessions as $index => $session): ?>
                                <?php
                                $sessionId = trim((string)($session['sessionId'] ?? ''));
                                $username = trim((string)($session['userName'] ?? ''));
                                $displayUsername = $username !== '' ? $username : 'IP autorisee';
                                $profileName = $username !== '' ? ($sessionProfileMap[$username] ?? '-') : '-';
                                $downloadBytes = (float)($session['bytes_in'] ?? 0);
                                $uploadBytes = (float)($session['bytes_out'] ?? 0);
                                $startTime = isset($session['startTime']) ? (float)$session['startTime'] : 0.0;
                                $durationSeconds = $startTime > 0 ? max(0, (int)(time() - $startTime)) : 0;
                                $txBitsPerSecond = $durationSeconds > 0 ? ($downloadBytes * 8) / $durationSeconds : 0.0;
                                $rxBitsPerSecond = $durationSeconds > 0 ? ($uploadBytes * 8) / $durationSeconds : 0.0;
                                $rowId = $sessionId !== '' ? $sessionId : ('opn_' . $index);
                                ?>
                                <tr
                                    id="session-row-<?= htmlspecialchars($rowId) ?>"
                                    data-id="<?= htmlspecialchars($rowId, ENT_QUOTES) ?>"
                                    data-session="<?= htmlspecialchars($sessionId !== '' ? $sessionId : '-', ENT_QUOTES) ?>"
                                    data-username="<?= htmlspecialchars($displayUsername, ENT_QUOTES) ?>"
                                    data-profile="<?= htmlspecialchars($profileName, ENT_QUOTES) ?>"
                                    data-address="<?= htmlspecialchars((string)($session['ipAddress'] ?? '-'), ENT_QUOTES) ?>"
                                    data-mac="<?= htmlspecialchars((string)($session['macAddress'] ?? '-'), ENT_QUOTES) ?>"
                                    data-duration="<?= htmlspecialchars((string)$durationSeconds, ENT_QUOTES) ?>"
                                    data-rx_speed="<?= htmlspecialchars((string)$rxBitsPerSecond, ENT_QUOTES) ?>"
                                    data-tx_speed="<?= htmlspecialchars((string)$txBitsPerSecond, ENT_QUOTES) ?>"
                                    data-upload="<?= htmlspecialchars((string)$uploadBytes, ENT_QUOTES) ?>"
                                    data-download="<?= htmlspecialchars((string)$downloadBytes, ENT_QUOTES) ?>"
                                    data-login="<?= htmlspecialchars((string)($session['authenticated_via'] ?? '-'), ENT_QUOTES) ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', [
                                        $displayUsername,
                                        $profileName,
                                        (string)($session['ipAddress'] ?? '-'),
                                        (string)($session['macAddress'] ?? '-'),
                                        (string)($session['authenticated_via'] ?? '-'),
                                    ])), ENT_QUOTES) ?>"
                                >
                                    <td><?= htmlspecialchars($displayUsername) ?></td>
                                    <td><?= htmlspecialchars($profileName) ?></td>
                                    <td><?= htmlspecialchars((string)($session['ipAddress'] ?? '-')) ?></td>
                                    <td><?= htmlspecialchars((string)($session['macAddress'] ?? '-')) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSessionDuration($durationSeconds)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($txBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($rxBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($downloadBytes)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($uploadBytes)) ?></td>
                                    <td><?= htmlspecialchars((string)($session['authenticated_via'] ?? '-')) ?></td>
                                    <td class="action-cell">
                                        <button
                                            type="button"
                                            class="btn btn-delete btn-sm session-action-btn"
                                            <?= $sessionId !== '' ? '' : 'disabled title="ID de session OPNsense manquant"' ?>
                                            onclick="disconnectSession('<?= htmlspecialchars($sessionId, ENT_QUOTES) ?>', '<?= htmlspecialchars($rowId, ENT_QUOTES) ?>')">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <?php foreach ($sessions as $session): ?>
                                <?php
                                $username = trim((string)($session['username'] ?? ''));
                                $sessionTime = (int)($session['acctsessiontime'] ?? 0);
                                $downloadBytes = (float)($session['acctinputoctets'] ?? 0);
                                $uploadBytes = (float)($session['acctoutputoctets'] ?? 0);
                                $txBitsPerSecond = $sessionTime > 0 ? ($downloadBytes * 8) / $sessionTime : 0.0;
                                $rxBitsPerSecond = $sessionTime > 0 ? ($uploadBytes * 8) / $sessionTime : 0.0;
                                $profileName = $sessionProfileMap[$username] ?? '-';
                                ?>
                                <tr
                                    id="session-row-<?= (int)$session['radacctid'] ?>"
                                    data-id="<?= (int)$session['radacctid'] ?>"
                                    data-session="<?= htmlspecialchars((string)($session['acctsessionid'] ?: '-'), ENT_QUOTES) ?>"
                                    data-username="<?= htmlspecialchars($username, ENT_QUOTES) ?>"
                                    data-profile="<?= htmlspecialchars($profileName, ENT_QUOTES) ?>"
                                    data-address="<?= htmlspecialchars((string)($session['framedipaddress'] ?: '-'), ENT_QUOTES) ?>"
                                    data-mac="<?= htmlspecialchars((string)($session['callingstationid'] ?: '-'), ENT_QUOTES) ?>"
                                    data-duration="<?= (int)$sessionTime ?>"
                                    data-rx_speed="<?= htmlspecialchars((string)$rxBitsPerSecond, ENT_QUOTES) ?>"
                                    data-tx_speed="<?= htmlspecialchars((string)$txBitsPerSecond, ENT_QUOTES) ?>"
                                    data-upload="<?= htmlspecialchars((string)$uploadBytes, ENT_QUOTES) ?>"
                                    data-download="<?= htmlspecialchars((string)$downloadBytes, ENT_QUOTES) ?>"
                                    data-login="<?= htmlspecialchars((string)($session['nasipaddress'] ?: '-'), ENT_QUOTES) ?>"
                                    data-search="<?= htmlspecialchars(mb_strtolower(implode(' ', [
                                        $username,
                                        $profileName,
                                        (string)($session['framedipaddress'] ?: '-'),
                                        (string)($session['callingstationid'] ?: '-'),
                                        (string)($session['nasipaddress'] ?: '-'),
                                    ])), ENT_QUOTES) ?>"
                                >
                                    <td><?= (int)$session['radacctid'] ?></td>
                                    <td><?= htmlspecialchars($session['acctsessionid'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($username) ?></td>
                                    <td><?= htmlspecialchars($profileName) ?></td>
                                    <td><?= htmlspecialchars($session['framedipaddress'] ?: '-') ?></td>
                                    <td><?= htmlspecialchars($session['callingstationid'] ?: '-') ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSessionDuration($sessionTime)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($txBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatSpeed($rxBitsPerSecond)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($downloadBytes)) ?></td>
                                    <td class="text-end"><?= htmlspecialchars(formatDataVolume($uploadBytes)) ?></td>
                                    <td><?= htmlspecialchars($session['nasipaddress'] ?: '-') ?></td>
                                    <td class="action-cell">
                                        <button
                                            type="button"
                                            class="btn btn-delete btn-sm session-action-btn"
                                            <?= $canDisconnectRemotely ? '' : 'disabled title="Action indisponible pour le device actif"' ?>
                                            onclick="disconnectSession('<?= htmlspecialchars($session['acctsessionid']) ?>', <?= (int)$session['radacctid'] ?>)">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                                <?php endif; ?>
