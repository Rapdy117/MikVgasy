<?php
/**
 * Service de notification — Licence par routeur
 *
 * Canal 1 : Email SMTP (notification automatique à l'admin)
 * Canal 2 : wa.me link (client envoie WhatsApp manuellement à l'admin)
 *
 * NOTE : Pas d'API WhatsApp côté serveur intentionnellement.
 * Le client envoie lui-même depuis son téléphone → zéro risque,
 * zéro coût, fonctionne quel que soit le volume d'installations.
 *
 * FUTUR : whatsapp-web.js pour les rappels de renouvellement clients.
 */

require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/lib/phpmailer/SMTP.php';
require_once __DIR__ . '/lib/phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

/* ─────────────────────────────────────────
   CONFIG
───────────────────────────────────────── */
function loadNotificationConfig(): array
{
    $file = __DIR__ . '/../config/notifications.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveNotificationConfig(array $config): void
{
    $file = __DIR__ . '/../config/notifications.json';
    file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/* ─────────────────────────────────────────
   LIENS DE CONTACT POUR LE CLIENT
───────────────────────────────────────── */

/**
 * Génère le lien wa.me pré-rempli que le client clique pour contacter l'admin.
 * Le client envoie lui-même depuis son téléphone — aucune API serveur.
 */
function buildWaLink(string $adminPhone, array $request): ?string
{
    $phone = preg_replace('/[^0-9]/', '', ltrim(trim($adminPhone), '+'));
    if ($phone === '') {
        return null;
    }

    $deviceId    = $request['device_id']    ?? '-';
    $deviceModel = $request['device_model'] ?? '-';
    $deviceType  = strtoupper($request['device_type'] ?? '-');
    $clientName  = $request['client_name']  ?? '';

    $lines = [
        'Bonjour,',
        '',
        'Je souhaite activer mon routeur.',
        '',
        "Device ID : {$deviceId}",
        "Type      : {$deviceType}",
        "Modèle    : {$deviceModel}",
    ];

    if ($clientName !== '') {
        $lines[] = "Nom       : {$clientName}";
    }

    $lines[] = '';
    $lines[] = 'Merci.';

    $message = implode("\n", $lines);
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);
}

/**
 * Génère le lien mailto pré-rempli.
 */
function buildMailtoLink(string $adminEmail, array $request): ?string
{
    if (trim($adminEmail) === '') {
        return null;
    }

    $deviceId = $request['device_id'] ?? '-';
    $subject  = rawurlencode("Demande de licence — {$deviceId}");
    $body     = rawurlencode(implode("\n", [
        'Bonjour,',
        '',
        'Je souhaite activer mon routeur.',
        '',
        "Device ID : {$deviceId}",
        "Type      : " . strtoupper($request['device_type'] ?? '-'),
        "Modèle    : " . ($request['device_model'] ?? '-'),
        "Nom       : " . ($request['client_name']  ?? '-'),
        '',
        'Merci.',
    ]));

    return "mailto:{$adminEmail}?subject={$subject}&body={$body}";
}

/* ─────────────────────────────────────────
   EMAIL — Notification automatique admin
───────────────────────────────────────── */
function sendEmailNotification(array $emailConfig, array $request, bool $isTest = false): array
{
    if (empty($emailConfig['enabled'])) {
        return ['ok' => false, 'error' => 'Email désactivé'];
    }
    if (empty($emailConfig['to_email'])) {
        return ['ok' => false, 'error' => 'Email destinataire non configuré'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)($emailConfig['smtp_host'] ?? '');
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)($emailConfig['smtp_user'] ?? '');
        $mail->Password   = (string)($emailConfig['smtp_pass'] ?? '');
        $mail->SMTPSecure = ((int)($emailConfig['smtp_port'] ?? 587)) === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($emailConfig['smtp_port'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPOptions = ['ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]];

        $fromEmail = (string)($emailConfig['from_email'] ?? $emailConfig['smtp_user'] ?? '');
        $fromName  = (string)($emailConfig['from_name']  ?? 'Radius Manager');
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress((string)$emailConfig['to_email']);

        if ($isTest) {
            $mail->Subject = '[Radius Manager] Test de notification email';
            $mail->Body    = '<p>✅ Configuration email OK — ' . date('d/m/Y H:i') . '</p>';
            $mail->isHTML(true);
        } else {
            $mail->Subject = '[Radius Manager] Nouvelle demande de licence — ' . ($request['device_id'] ?? '');
            $mail->isHTML(true);
            $mail->Body    = buildLicenseEmailHtml($request);
            $mail->AltBody = buildLicenseEmailPlain($request);
        }

        $mail->send();
        return ['ok' => true];

    } catch (MailerException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function buildLicenseEmailHtml(array $r): string
{
    $deviceId     = htmlspecialchars($r['device_id']       ?? '-');
    $serialNumber = htmlspecialchars($r['serial_number']   ?? '-');
    $deviceType   = htmlspecialchars(strtoupper($r['device_type']  ?? '-'));
    $deviceModel  = htmlspecialchars($r['device_model']    ?? '-');
    $clientName   = htmlspecialchars($r['client_name']     ?? '-');
    $clientEmail  = htmlspecialchars($r['client_email']    ?? '-');
    $clientWa     = htmlspecialchars($r['client_whatsapp'] ?? '-');
    $date         = date('d/m/Y H:i');

    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Inter,Arial,sans-serif;background:#0b0f1a;color:#dce4f0;padding:0;margin:0;">
  <div style="max-width:520px;margin:24px auto;background:#111827;border-radius:12px;overflow:hidden;border:1px solid #1e2a3a;">
    <div style="background:linear-gradient(135deg,#1e3a5f,#0f2a4a);padding:18px 22px;">
      <h2 style="margin:0;color:#38bdf8;font-size:1rem;">📦 Nouvelle demande de licence</h2>
      <p style="margin:4px 0 0;color:#64748b;font-size:0.8rem;">{$date}</p>
    </div>
    <div style="padding:18px 22px;">
      <table style="width:100%;border-collapse:collapse;font-size:0.86rem;">
        <tr><td style="padding:6px 0;color:#94a3b8;width:36%;">Device ID</td>
            <td style="padding:6px 0;font-weight:700;color:#fbbf24;letter-spacing:.06em;">{$deviceId}</td></tr>
        <tr style="background:#ffffff08;"><td style="padding:6px 6px;color:#94a3b8;">N° Série</td>
            <td style="padding:6px 6px;color:#f8fafc;">{$serialNumber}</td></tr>
        <tr><td style="padding:6px 0;color:#94a3b8;">Type</td>
            <td style="padding:6px 0;color:#38bdf8;font-weight:600;">{$deviceType}</td></tr>
        <tr style="background:#ffffff08;"><td style="padding:6px 6px;color:#94a3b8;">Modèle</td>
            <td style="padding:6px 6px;color:#f8fafc;">{$deviceModel}</td></tr>
        <tr style="background:#ffffff08;"><td style="padding:6px 6px;color:#94a3b8;">Client</td>
            <td style="padding:6px 6px;color:#f8fafc;">{$clientName}</td></tr>
        <tr><td style="padding:6px 0;color:#94a3b8;">📧 Email</td>
            <td style="padding:6px 0;color:#38bdf8;">{$clientEmail}</td></tr>
        <tr style="background:#ffffff08;"><td style="padding:6px 6px;color:#94a3b8;">📱 WhatsApp</td>
            <td style="padding:6px 6px;color:#4ade80;">{$clientWa}</td></tr>
      </table>
    </div>
    <div style="padding:10px 22px 18px;border-top:1px solid #1e2a3a;">
      <code style="font-size:0.78rem;color:#fbbf24;">bin\\agent\\license-generator.exe generate --device-id {$deviceId}</code>
    </div>
  </div>
</body></html>
HTML;
}

function buildLicenseEmailPlain(array $r): string
{
    return sprintf(
        "Nouvelle demande de licence\n\nDevice ID : %s\nType : %s\nModèle : %s\nClient : %s\nEmail : %s\nWhatsApp : %s\nDate : %s",
        $r['device_id'] ?? '-', strtoupper($r['device_type'] ?? '-'), $r['device_model'] ?? '-',
        $r['client_name'] ?? '-', $r['client_email'] ?? '-', $r['client_whatsapp'] ?? '-',
        date('d/m/Y H:i')
    );
}

/* ─────────────────────────────────────────
   DÉCLENCHEUR PRINCIPAL
   Envoie l'email admin + retourne les liens de contact client
───────────────────────────────────────── */
function triggerLicenseRequestNotification(array $request): array
{
    $config = loadNotificationConfig();

    $emailResult = sendEmailNotification($config['email'] ?? [], $request);

    $adminPhone = trim((string)($config['whatsapp']['admin_phone'] ?? ''));
    $adminEmail = trim((string)($config['email']['to_email']       ?? ''));

    return [
        'email'       => $emailResult,
        'wa_link'     => buildWaLink($adminPhone, $request),
        'mailto_link' => buildMailtoLink($adminEmail, $request),
        'admin_phone' => $adminPhone,
        'admin_email' => $adminEmail,
    ];
}

function triggerTestNotification(): array
{
    $config = loadNotificationConfig();
    return [
        'email' => sendEmailNotification($config['email'] ?? [], [], true),
    ];
}
