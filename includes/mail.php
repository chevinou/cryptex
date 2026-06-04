<?php
// ============================================================
//  CRYPTEX — Envoi d'emails
// ============================================================

function sendMail(string $to, string $subject, string $html, string $text = ''): bool
{
    $host     = MAIL_HOST;
    $port     = (int)MAIL_PORT;
    $user     = MAIL_USERNAME;
    $pass     = MAIL_PASSWORD;
    $from     = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;

    if ($port === 465) {
        $encryption = 'ssl';
    } elseif ($port === 587) {
        $encryption = 'tls';
    } else {
        $encryption = 'none';
    }

    // ── Fallback mail() si aucun host défini ──────────────────
    if (!$host) {
        return _mail_fallback($to, $subject, $html, $from, $fromName);
    }

    return _smtp_socket($to, $subject, $html, $host, $port, $encryption, $user, $pass, $from, $fromName);
}

// ── Fallback PHP mail() ───────────────────────────────────────
function _mail_fallback(string $to, string $subject, string $html, string $from, string $fromName): bool
{
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "X-Mailer: Cryptex/" . APP_VERSION . "\r\n";

    $result = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);

    if (!$result) {
        error_log("[Cryptex] mail() échoué vers {$to}");
    }
    return (bool)$result;
}

// ── SMTP via stream_socket ────────────────────────────────────
function _smtp_socket(
    string $to, string $subject, string $html,
    string $host, int $port, string $encryption,
    string $user, string $pass,
    string $from, string $fromName
): bool {
    $timeout = 10;

    if ($encryption === 'ssl') {
        $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout);
    } else {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    }

    if (!$socket) {
        error_log("[Cryptex] SMTP connexion impossible {$host}:{$port} — [{$errno}] {$errstr}");
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $read  = fn() => fgets($socket, 515);
    $write = function(string $cmd) use ($socket): string {
        fwrite($socket, $cmd . "\r\n");
        return fgets($socket, 515);
    };

    $banner = $read();
    if (!str_starts_with(trim($banner), '220')) {
        error_log("[Cryptex] SMTP banner inattendu : {$banner}");
        fclose($socket);
        return false;
    }

    $resp = $write("EHLO " . (gethostname() ?: 'localhost'));
    while ($resp && substr($resp, 3, 1) === '-') { $resp = $read(); }

    if ($encryption === 'tls') {
        $resp = $write("STARTTLS");
        if (!str_starts_with(trim($resp), '220')) {
            error_log("[Cryptex] STARTTLS refusé : {$resp}");
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("[Cryptex] Négociation TLS échouée");
            fclose($socket);
            return false;
        }
        $resp = $write("EHLO " . (gethostname() ?: 'localhost'));
        while ($resp && substr($resp, 3, 1) === '-') { $resp = $read(); }
    }

    if (MAIL_SMTP_AUTH && $user) {
        $write("AUTH LOGIN");
        $write(base64_encode($user));
        $resp = $write(base64_encode($pass));
        if (!str_starts_with(trim($resp), '235')) {
            error_log("[Cryptex] SMTP AUTH échoué : {$resp}");
            fclose($socket);
            return false;
        }
    }

    $resp = $write("MAIL FROM:<{$from}>");
    if (!str_starts_with(trim($resp), '250')) {
        error_log("[Cryptex] MAIL FROM refusé : {$resp}");
        fclose($socket);
        return false;
    }

    $resp = $write("RCPT TO:<{$to}>");
    if (!str_starts_with(trim($resp), '250')) {
        error_log("[Cryptex] RCPT TO refusé pour {$to} : {$resp}");
        fclose($socket);
        return false;
    }

    $write("DATA");

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $msg  = "From: {$fromName} <{$from}>\r\n";
    $msg .= "To: {$to}\r\n";
    $msg .= "Subject: {$encodedSubject}\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n";
    $msg .= "X-Mailer: Cryptex/" . APP_VERSION . "\r\n";
    $msg .= "\r\n";
    $msg .= $html;
    $msg .= "\r\n.\r\n";

    $resp = $write($msg);
    if (!str_starts_with(trim($resp), '250')) {
        error_log("[Cryptex] DATA refusé : {$resp}");
        fclose($socket);
        return false;
    }

    $write("QUIT");
    fclose($socket);
    return true;
}

// ── Notification : nouveau secret ────────────────────────────
function sendSecretNotification(array $secret): bool
{
    $to        = $secret['recipient_email'];
    $recipName = $secret['recipient_name'] ?: 'Utilisateur';
    $link      = APP_URL . '/view.php?t=' . urlencode($secret['token']);
    $expires   = formatDate($secret['expires_at']);
    $title     = htmlspecialchars($secret['title'] ?: 'Information confidentielle');
    $subject   = '[Cryptex] Transmission sécurisée de ' . $secret['sender_name'];

    if ($secret['destroy_on_read']) {
        $badgeHtml = <<<HTML
        <tr>
          <td style="padding:0 0 20px;">
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff3cd;border-radius:20px;padding:6px 14px;">
                  <span style="font-size:13px;color:#856404;">
                    ⚠️ &nbsp;<strong>Lecture unique</strong> — le message sera détruit après consultation
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        HTML;
    } else {
        $badgeHtml = <<<HTML
        <tr>
          <td style="padding:0 0 20px;">
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#d4edda;border-radius:20px;padding:6px 14px;">
                  <span style="font-size:13px;color:#155724;">
                    🕒 &nbsp;Expire le <strong>{$expires}</strong>
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        HTML;
    }

    $html = _buildMailHtml(
        recipName : $recipName,
        senderName: $secret['sender_name'],
        title     : $title,
        link      : $link,
        badgeHtml : $badgeHtml,
        isReminder: false
    );

    return sendMail($to, $subject, $html);
}

// ── Renvoi du lien ────────────────────────────────────────────
function resendSecretLink(array $data): bool
{
    $link    = APP_URL . '/view.php?t=' . urlencode($data['token']);
    $expires = formatDate($data['expires_at']);
    $subject = '[Cryptex] Rappel — Transmission sécurisée de ' . $data['sender_name'];

    if ($data['destroy_on_read']) {
        $badgeHtml = <<<HTML
        <tr>
          <td style="padding:0 0 20px;">
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#fff3cd;border-radius:20px;padding:6px 14px;">
                  <span style="font-size:13px;color:#856404;">
                    ⚠️ &nbsp;<strong>Lecture unique</strong>
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        HTML;
    } else {
        $badgeHtml = <<<HTML
        <tr>
          <td style="padding:0 0 20px;">
            <table cellpadding="0" cellspacing="0">
              <tr>
                <td style="background:#d4edda;border-radius:20px;padding:6px 14px;">
                  <span style="font-size:13px;color:#155724;">
                    🕒 &nbsp;Expire le <strong>{$expires}</strong>
                  </span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        HTML;
    }

    $html = _buildMailHtml(
        recipName : $data['recipient_name'] ?: 'Utilisateur',
        senderName: $data['sender_name'],
        title     : htmlspecialchars($data['title'] ?: 'Information confidentielle'),
        link      : $data['link'] ?? $link,
        badgeHtml : $badgeHtml,
        isReminder: true
    );

    return sendMail($data['recipient_email'], $subject, $html);
}

// ── Template HTML moderne ─────────────────────────────────────
function _buildMailHtml(
    string $recipName, string $senderName, string $title,
    string $link, string $badgeHtml, bool $isReminder
): string {

    // Logo : défini dans config.php via MAIL_LOGO_URL
    // Placez votre logo sur le serveur et adaptez la constante.
    // Format recommandé : PNG transparent, largeur ~160px, fond clair ou transparent.
    $logoUrl   = defined('MAIL_LOGO_URL') && MAIL_LOGO_URL ? MAIL_LOGO_URL : '';
    $logoBlock = $logoUrl
        ? "<img src=\"{$logoUrl}\" alt=\"Logo\" style=\"max-height:48px;max-width:180px;display:block;margin:0 auto 12px;\">"
        : '';

    $subtitle = $isReminder
        ? 'Rappel — information confidentielle disponible'
        : 'Transmission sécurisée d\'information confidentielle';

    $intro = $isReminder
        ? "Ceci est un rappel : <strong>{$senderName}</strong> vous a transmis une information confidentielle qui est toujours disponible."
        : "<strong>{$senderName}</strong> vous a transmis une information confidentielle via Cryptex.";

    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cryptex — Message sécurisé</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:'Segoe UI',Arial,sans-serif;">

<!-- Wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:48px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

  <!-- ── HEADER ── -->
  <tr><td style="background:linear-gradient(135deg,#0a2744 0%,#005a9c 60%,#0077c8 100%);border-radius:12px 12px 0 0;padding:36px 40px 28px;text-align:center;">
    {$logoBlock}
    <h1 style="margin:0;color:#ffffff;font-size:28px;font-weight:700;letter-spacing:3px;text-transform:uppercase;">CRYPTEX</h1>
    <p style="margin:6px 0 0;color:#a8cff0;font-size:12px;letter-spacing:1px;text-transform:uppercase;">{$subtitle}</p>
    <!-- Ligne décorative -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">
      <tr>
        <td style="border-top:1px solid rgba(255,255,255,.15);"></td>
        <td width="40" style="text-align:center;padding:0 10px;">
          <span style="color:rgba(255,255,255,.4);font-size:16px;">🔒</span>
        </td>
        <td style="border-top:1px solid rgba(255,255,255,.15);"></td>
      </tr>
    </table>
  </td></tr>

  <!-- ── BODY ── -->
  <tr><td style="background:#ffffff;padding:40px 40px 32px;">

    <!-- Salutation -->
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:0 0 16px;">
          <p style="margin:0;color:#1a2e47;font-size:17px;font-weight:600;">Bonjour {$recipName},</p>
        </td>
      </tr>
      <tr>
        <td style="padding:0 0 28px;">
          <p style="margin:0;color:#4a5568;font-size:14px;line-height:1.7;">{$intro}</p>
        </td>
      </tr>

      <!-- Carte du secret -->
      <tr>
        <td style="padding:0 0 28px;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:linear-gradient(135deg,#f0f7ff,#e8f1fb);border:1px solid #c8ddf5;border-radius:10px;overflow:hidden;">
            <tr>
              <td width="5" style="background:linear-gradient(180deg,#005a9c,#0077c8);border-radius:10px 0 0 10px;"></td>
              <td style="padding:18px 20px;">
                <p style="margin:0 0 4px;font-size:11px;color:#6b7c93;text-transform:uppercase;letter-spacing:1px;">Message confidentiel</p>
                <p style="margin:0;color:#1a2e47;font-weight:700;font-size:15px;">{$title}</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Badge expiration / lecture unique -->
      {$badgeHtml}

      <!-- Bouton CTA -->
      <tr>
        <td style="padding:0 0 28px;text-align:center;">
          <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
            <tr>
              <td align="center" bgcolor="#005a9c" style="border-radius:8px;box-shadow:0 4px 14px rgba(0,90,156,.35);">
                <a href="{$link}"
                   style="display:inline-block;background-color:#005a9c;color:#ffffff !important;text-decoration:none;padding:14px 36px;font-size:15px;font-weight:700;letter-spacing:.5px;border-radius:8px;mso-padding-alt:14px 36px;">
                  Accéder au message sécurisé &nbsp;&#8594;
                </a>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Lien texte de secours -->
      <tr>
        <td style="padding:0 0 8px;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:11px;">Bouton inactif ? Copiez ce lien dans votre navigateur :</p>
        </td>
      </tr>
      <tr>
        <td style="padding:0;text-align:center;">
          <a href="{$link}" style="color:#005a9c;font-size:11px;word-break:break-all;">{$link}</a>
        </td>
      </tr>

    </table>
  </td></tr>

  <!-- ── SÉPARATEUR DÉCORATIF ── -->
  <tr>
    <td style="background:#ffffff;padding:0 40px;">
      <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
          <td style="border-top:1px solid #e8edf5;padding:0;"></td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ── AVERTISSEMENT SÉCURITÉ ── -->
  <tr>
    <td style="background:#ffffff;padding:20px 40px 32px;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:8px;padding:14px 18px;">
        <tr>
          <td width="28" valign="top" style="padding-top:1px;">
            <span style="font-size:16px;">🛡️</span>
          </td>
          <td style="padding-left:8px;">
            <p style="margin:0;color:#4a5568;font-size:12px;line-height:1.6;">
              Ce lien est <strong>strictement personnel</strong>. Ne le partagez pas et n'y accédez que depuis un réseau de confiance.
              Si vous n'attendiez pas ce message, ignorez cet email.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ── FOOTER ── -->
  <tr>
    <td style="background:#1a2e47;border-radius:0 0 12px 12px;padding:22px 40px;text-align:center;">
      <p style="margin:0 0 4px;color:#a8b8cc;font-size:12px;font-weight:600;letter-spacing:1px;">CRYPTEX — Auvergne Habitat</p>
      <p style="margin:0;color:#566a80;font-size:11px;">© {$year} — Ne pas répondre à cet email · Envoi automatique sécurisé</p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

// ── Utilitaire date ───────────────────────────────────────────
function formatDate(string $sqlDate): string {
    return (new DateTime($sqlDate, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Paris'))
        ->format('d/m/Y à H:i');
}