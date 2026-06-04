<?php
// ============================================================
//  CRYPTEX — Dépôt de secret (multi-destinataires)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/ldap.php';
require_once __DIR__ . '/includes/mail.php';

requireSSO();

$user    = currentUser();
$errors  = [];
$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de sécurité invalide. Rechargez la page.';
    } else {
        $title          = trim($_POST['title']          ?? '');
        $content        = trim($_POST['secret_content'] ?? '');
        $recipientsJson = $_POST['recipients_json']     ?? '[]';
        $durationHours  = (int)($_POST['duration']      ?? 24);
        $destroyOnRead  = isset($_POST['destroy_on_read']) ? 1 : 0;

        // Décodage des destinataires
        $recipients = json_decode($recipientsJson, true) ?: [];
        $recipients = array_filter($recipients, fn($r) =>
            !empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL));
        $recipients = array_values($recipients);

        // Fichiers uploadés
        $uploadedFiles = [];
        $fileErrors    = [];
        if (!empty($_FILES['attachments']['name'][0])) {
            $files = $_FILES['attachments'];
            $count = count($files['name']);
            if ($count > MAX_FILES_COUNT) {
                $fileErrors[] = 'Maximum ' . MAX_FILES_COUNT . ' fichiers autorisés.';
            } else {
                for ($i = 0; $i < $count; $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                        $fileErrors[] = 'Erreur upload « ' . htmlspecialchars($files['name'][$i]) . ' » (code ' . $files['error'][$i] . ').';
                        continue;
                    }
                    if ($files['size'][$i] > MAX_FILE_SIZE) {
                        $fileErrors[] = '« ' . htmlspecialchars($files['name'][$i]) . ' » dépasse ' . (MAX_FILE_SIZE / 1024 / 1024) . ' Mo.';
                        continue;
                    }
                    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
                        $fileErrors[] = '« ' . htmlspecialchars($files['name'][$i]) . ' » : extension non autorisée.';
                        continue;
                    }
                    $uploadedFiles[] = [
                        'tmp_path'  => $files['tmp_name'][$i],
                        'orig_name' => $files['name'][$i],
                        'mime'      => mime_content_type($files['tmp_name'][$i]) ?: 'application/octet-stream',
                    ];
                }
            }
        }
        $errors = array_merge($errors, $fileErrors);

        // Validations
        if (empty($content) && empty($uploadedFiles)) $errors[] = 'Le message ou au moins un fichier est obligatoire.';
        if (!empty($content) && strlen($content) > MAX_SECRET_SIZE) $errors[] = 'Message trop long (max ' . MAX_SECRET_SIZE . ' caractères).';
        if (empty($recipients)) $errors[] = 'Sélectionnez au moins un destinataire.';
        if (!array_key_exists($durationHours, DURATIONS)) $durationHours = 24;

        if (empty($errors)) {
            try {
                // Si le contenu texte est vide (fichiers uniquement), on chiffre une chaîne vide
                $encrypted = encryptSecret($content !== '' ? $content : '');
                $expiresAt = (new DateTime())->modify("+{$durationHours} hours")->format('Y-m-d H:i:s');

                $result = createSecret([
                    'content_encrypted' => $encrypted['content_encrypted'],
                    'nonce'             => $encrypted['nonce'],
                    'title'             => $title ?: 'Information confidentielle',
                    'sender_name'       => fullName(),
                    'sender_email'      => userEmail(),
                    'recipients'        => $recipients,
                    'expires_at'        => $expiresAt,
                    'destroy_on_read'   => $destroyOnRead,
                    'ip_sender'         => getClientIp(),
                ]);

                // Chiffrement et stockage des fichiers joints
                $fileStoreErrors = [];
                foreach ($uploadedFiles as $f) {
                    try {
                        $enc = encryptAndStoreFile($f['tmp_path'], $f['orig_name']);
                        attachFileToSecret($result['secret_id'], [
                            'filename_original' => $f['orig_name'],
                            'filename_stored'   => $enc['stored_name'],
                            'mime_type'         => $f['mime'],
                            'file_size'         => $enc['file_size'],
                            'file_nonce'        => $enc['nonce'],
                        ]);
                    } catch (Throwable $fe) {
                        $fileStoreErrors[] = '« ' . htmlspecialchars($f['orig_name']) . ' » : ' . $fe->getMessage();
                        error_log('[Cryptex] File store error: ' . $fe->getMessage());
                    }
                }
                if (!empty($fileStoreErrors)) {
                    // Secret créé mais certains fichiers ont échoué — on avertit sans bloquer
                    $errors = array_merge($errors, array_map(
                        fn($e) => 'Fichier non joint — ' . $e,
                        $fileStoreErrors
                    ));
                }

                // Envoi email à chaque destinataire
                foreach ($result['recipients'] as $r) {
                    sendSecretNotification([
                        'token'           => $r['token'],
                        'recipient_email' => $r['email'],
                        'recipient_name'  => $r['name'] ?: 'Utilisateur',
                        'sender_name'     => fullName(),
                        'title'           => $title ?: 'Information confidentielle',
                        'expires_at'      => $expiresAt,
                        'destroy_on_read' => $destroyOnRead,
                    ]);
                }

                $results = $result['recipients'];

            } catch (Throwable $e) {
                $errors[] = 'Erreur critique : ' . $e->getMessage();
                error_log('[Cryptex] Secret creation error: ' . $e->getMessage());
            }
        }
    }
}

$csrf      = csrfToken();
$stats     = getStats();
$durations = DURATIONS;
$durationIcons = [1=>'⚡',4=>'🕓',8=>'☀',24=>'📅',72=>'📆',168=>'🗓',336=>'🗓'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <meta name="robots" content="noindex,nofollow">
  <title>Cryptex — Déposer un secret</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    /* ── Tags multi-destinataires ── */
    .tags-input-wrapper {
      border:2px solid var(--border); border-radius:var(--radius-sm);
      padding:6px 10px; background:#fff; min-height:48px;
      display:flex; flex-wrap:wrap; gap:6px; align-items:flex-start;
      cursor:text; transition:.2s;
    }
    .tags-input-wrapper:focus-within {
      border-color:var(--primary-light);
      box-shadow:0 0 0 3px rgba(0,119,200,.12);
    }
    .tag-chip {
      display:inline-flex; align-items:center; gap:5px;
      background:linear-gradient(135deg,var(--primary-mid),var(--primary-light));
      color:#fff; border-radius:20px; padding:4px 10px 4px 12px;
      font-size:13px; font-weight:600; white-space:nowrap;
    }
    .tag-chip .avatar-xs {
      width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,.3);
      display:inline-flex; align-items:center; justify-content:center;
      font-size:10px; font-weight:700;
    }
    .tag-chip button {
      background:rgba(255,255,255,.3); border:none; color:#fff;
      border-radius:50%; width:18px; height:18px; cursor:pointer;
      display:inline-flex; align-items:center; justify-content:center;
      font-size:12px; padding:0; line-height:1; transition:.15s;
    }
    .tag-chip button:hover { background:rgba(255,255,255,.5); }
    .tags-input {
      border:none; outline:none; background:transparent;
      font-size:14px; min-width:200px; flex:1; padding:4px 2px;
      font-family:inherit; color:var(--text);
    }
    .recipients-counter {
      font-size:12px; color:var(--text-muted); margin-top:6px;
    }
    .recipients-counter.has-items { color:var(--success); }

    /* ── Zone de dépôt de fichiers ── */
    .file-drop-zone {
      border: 2px dashed var(--border);
      border-radius: var(--radius-sm);
      padding: 20px;
      background: #fafbfc;
      transition: .2s;
      text-align: center;
    }
    .file-drop-zone.drag-over {
      border-color: var(--primary-light);
      background: rgba(0,119,200,.05);
    }
    .file-item {
      display: flex; align-items: center; gap: 10px;
      background: #fff; border: 1px solid var(--border);
      border-radius: 8px; padding: 8px 12px;
      margin-bottom: 6px; text-align: left;
    }
    .file-item .file-icon { font-size: 20px; flex-shrink: 0; }
    .file-item .file-info { flex: 1; min-width: 0; }
    .file-item .file-name { font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .file-item .file-size { font-size: 11px; color: var(--text-muted); }
    .file-item .file-remove {
      background: none; border: none; color: #ef476f; cursor: pointer;
      font-size: 16px; padding: 2px 6px; border-radius: 4px; transition: .15s;
    }
    .file-item .file-remove:hover { background: #fff0f3; }
  </style>
</head>
<body data-max-len="<?= MAX_SECRET_SIZE ?>">

<div class="topbar">
  <div>
    <strong><?= htmlspecialchars(fullName()) ?></strong>
    <?php if (!empty($user['service'] ?? '')): ?>&nbsp;·&nbsp;<?= htmlspecialchars($user['service']) ?><?php endif; ?>
    <?php if (isAdmin()): ?>&nbsp;·&nbsp;<span style="color:#f9d879">👑 Admin</span><?php endif; ?>
  </div>
  <a href="logout.php">⎋ Déconnexion</a>
</div>

<header class="site-header">
  <div class="logo-mark">🔐</div>
  <div>
    <h1>CRYPTEX</h1>
    <div class="tagline">Transmission sécurisée · Auvergne Habitat</div>
  </div>
</header>

<nav class="nav-tabs">
  <a href="index.php" class="active">✉ Déposer</a>
  <a href="history.php">📋 Mes envois</a>
  <a href="inbox.php">📥 Mes réceptions</a>
  <?php if (isAdmin()): ?><a href="admin.php">👥 Utilisateurs</a><?php endif; ?>
</nav>

<main>

  <?php if ($results !== null): ?>
  <!-- Succès multi-destinataires -->
  <div class="card">
    <div class="card-body success-box">
      <div class="big-icon">✅</div>
      <h2><?= count($results) > 1 ? 'Secret transmis à ' . count($results) . ' destinataires !' : 'Secret transmis !' ?></h2>
      <p style="color:var(--text-muted);font-size:13px;margin-top:8px;">Chaque destinataire a reçu un lien personnel par email.</p>
      <div style="margin:20px 0;text-align:left;">
        <?php foreach ($results as $r): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px;background:#f8fafc;border-radius:8px;margin-bottom:8px;">
          <span style="font-size:20px;">📧</span>
          <div>
            <div style="font-weight:600;font-size:14px;"><?= htmlspecialchars($r['name'] ?: $r['email']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($r['email']) ?></div>
          </div>
          <button class="btn btn-outline" style="margin-left:auto;font-size:11px;padding:4px 10px;"
                  onclick="copyToClipboard('<?= APP_URL ?>/view.php?t=<?= urlencode($r['token']) ?>', this)">
            📋 Copier le lien
          </button>
        </div>
        <?php endforeach; ?>
      </div>
      <a href="index.php" class="btn btn-primary">+ Nouveau secret</a>
    </div>
  </div>

  <?php else: ?>

  <div class="card">
    <div class="card-header">
      <span class="icon">🔏</span>
      <h2>Déposer une information confidentielle</h2>
    </div>
    <div class="card-body">

      <?php foreach ($errors as $err): ?>
      <div class="alert alert-danger">⚠ <?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>

      <div class="alert alert-info">
        💡 Chiffrement AES-256-GCM · Authentification SSO · Destruction automatique
      </div>

      <form method="POST" id="deposit-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="recipients_json" id="recipients_json" value="[]">

        <!-- Objet -->
        <div class="form-group">
          <label for="title">Objet <span style="color:#999;font-weight:400;">(optionnel)</span></label>
          <input type="text" id="title" name="title" maxlength="150"
                 placeholder="Ex : Mot de passe ERP, Accès VPN…"
                 value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
        </div>

        <!-- Destinataires (multi) -->
        <div class="form-group">
          <label>Destinataires <span class="required">*</span></label>
          <div class="autocomplete-wrapper">
            <div class="tags-input-wrapper" id="tags-wrapper" onclick="document.getElementById('recipient_search').focus()">
              <div id="tags-container"></div>
              <input type="text" id="recipient_search" class="tags-input"
                     placeholder="Rechercher un utilisateur dans l'annuaire…"
                     autocomplete="off">
            </div>
            <div class="autocomplete-list" id="autocomplete_list"></div>
          </div>
          <div class="recipients-counter" id="recipients-counter">0 destinataire sélectionné</div>
          <p class="hint">💡 Vous pouvez ajouter plusieurs destinataires — chacun recevra un lien personnel unique</p>
        </div>

        <!-- Contenu secret -->
        <div class="form-group">
          <label for="secret_content">Information confidentielle <span class="required">*</span></label>
          <textarea id="secret_content" name="secret_content" rows="6"
                    placeholder="Saisissez le mot de passe, token, clé ou information sensible…"
                    maxlength="<?= MAX_SECRET_SIZE ?>"><?= htmlspecialchars($_POST['secret_content'] ?? '') ?></textarea>
          <p class="hint" style="text-align:right;"><span id="char-count">0 / <?= MAX_SECRET_SIZE ?></span></p>
        </div>

        <!-- Durée -->
        <div class="form-group">
          <label>Durée de validité <span class="required">*</span></label>
          <div class="duration-grid">
            <?php foreach ($durations as $hours => $label):
              $checked = (($_POST['duration'] ?? 24) == $hours) ? 'checked' : ''; ?>
            <input type="radio" name="duration" id="dur_<?= $hours ?>" value="<?= $hours ?>" <?= $checked ?>>
            <label for="dur_<?= $hours ?>">
              <span class="d-icon"><?= $durationIcons[$hours] ?? '⏱' ?></span><?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Lecture unique -->
        <div class="form-group">
          <label>Option de destruction</label>
          <div class="toggle-row">
            <label class="toggle-switch">
              <input type="checkbox" name="destroy_on_read" id="destroy_on_read"
                     <?= isset($_POST['destroy_on_read']) ? 'checked' : '' ?>>
              <span class="toggle-slider"></span>
            </label>
            <div class="toggle-info">
              <strong>⚡ Lecture unique</strong>
              Détruire après la première consultation de chaque destinataire
            </div>
          </div>
        </div>

        <!-- Pièces jointes -->
        <div class="form-group">
          <label>Pièces jointes <span style="color:#999;font-weight:400;">(optionnel)</span></label>
          <div class="file-drop-zone" id="file-drop-zone">
            <input type="file" name="attachments[]" id="attachments" multiple
                   accept=".pdf,.txt,.csv,.log,.jpg,.jpeg,.png,.gif,.webp,.svg,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.zip,.7z,.tar,.gz,.xml,.json,.yaml,.yml,.p12,.pem,.crt,.key"
                   style="display:none;" onchange="handleFileSelect(this)">
            <div id="file-drop-label" onclick="document.getElementById('attachments').click()" style="cursor:pointer;">
              <div style="font-size:28px;margin-bottom:8px;">📎</div>
              <div style="font-weight:600;color:var(--primary-mid);">Cliquez ou glissez des fichiers ici</div>
              <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Max <?= MAX_FILES_COUNT ?> fichiers · <?= MAX_FILE_SIZE / 1024 / 1024 ?> Mo par fichier</div>
            </div>
            <ul id="file-list" style="list-style:none;padding:0;margin:10px 0 0;"></ul>
          </div>
          <p class="hint">🔒 Les fichiers seront chiffrés avec AES-256-GCM, comme le message</p>
        </div>

        <div style="text-align:right;margin-top:30px;">
          <button type="submit" id="submit-btn" class="btn btn-primary btn-lg">
            🔐 Chiffrer et envoyer
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>

</main>

<footer>
  <strong>Cryptex</strong> v<?= APP_VERSION ?> — Auvergne Habitat
  &nbsp;|&nbsp; Données chiffrées AES-256-GCM — Suppression automatique
</footer>

<script src="assets/app.js"></script>
<script>
// ── Gestion des fichiers joints ──────────────────────────────
const MAX_FILES = <?= MAX_FILES_COUNT ?>;
const MAX_SIZE  = <?= MAX_FILE_SIZE ?>;
let selectedFiles = []; // vrais objets File JS — jamais perdus

function fileIcon(name) {
  const ext = name.split('.').pop().toLowerCase();
  const icons = { pdf:'📄', jpg:'🖼', jpeg:'🖼', png:'🖼', gif:'🖼', webp:'🖼', svg:'🖼',
    doc:'📝', docx:'📝', xls:'📊', xlsx:'📊', ppt:'📊', pptx:'📊',
    zip:'🗜', gz:'🗜', '7z':'🗜', tar:'🗜', txt:'📃', csv:'📃', json:'📃',
    xml:'📃', pem:'🔑', crt:'🔑', p12:'🔑', key:'🔑' };
  return icons[ext] || '📎';
}

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' o';
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
  return (bytes / 1048576).toFixed(1) + ' Mo';
}

function renderFileList() {
  const ul = document.getElementById('file-list');
  const lbl = document.getElementById('file-drop-label');
  ul.innerHTML = '';
  selectedFiles.forEach((f, i) => {
    const li = document.createElement('li');
    li.className = 'file-item';
    li.innerHTML = `<span class="file-icon">${fileIcon(f.name)}</span>
      <div class="file-info">
        <div class="file-name" title="${f.name}">${f.name}</div>
        <div class="file-size">${formatBytes(f.size)}</div>
      </div>
      <button type="button" class="file-remove" onclick="removeFile(${i})" title="Retirer">✕</button>`;
    ul.appendChild(li);
  });
  lbl.style.display = selectedFiles.length >= MAX_FILES ? 'none' : '';
}

function removeFile(idx) {
  selectedFiles.splice(idx, 1);
  renderFileList();
}

function addFiles(fileList) {
  for (const f of fileList) {
    if (selectedFiles.length >= MAX_FILES) { alert('Maximum ' + MAX_FILES + ' fichiers autorisés.'); break; }
    if (f.size > MAX_SIZE) { alert(`« ${f.name} » dépasse ${MAX_SIZE/1024/1024} Mo.`); continue; }
    if (selectedFiles.find(x => x.name === f.name && x.size === f.size)) continue;
    selectedFiles.push(f);
  }
  renderFileList();
}

// Appel depuis onchange de l'input — NE PAS effacer la valeur
// Les File objects restent dans selectedFiles, envoyés via fetch au submit
function handleFileSelect(input) {
  addFiles(input.files);
}

// Drag & drop
const dropZone = document.getElementById('file-drop-zone');
dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  addFiles(e.dataTransfer.files);
});

// ── Soumission via fetch + FormData ─────────────────────────
// Seule méthode fiable pour soumettre des fichiers gérés en JS.
// fetch envoie les vrais objets File de selectedFiles — aucun DataTransfer
// trick sur l'input natif, aucun risque de perte des données.
document.getElementById('deposit-form').addEventListener('submit', async function(e) {
  e.preventDefault();

  const btn = document.getElementById('submit-btn');
  btn.disabled = true;
  btn.innerHTML = '⏳ Chiffrement en cours…';

  // Construire FormData depuis les champs du formulaire
  const fd = new FormData(this);

  // L'input natif #attachments peut être vide (si l'utilisateur a
  // utilisé le drag & drop ou sélectionné puis dé-sélectionné).
  // On supprime son contenu et on injecte notre tableau selectedFiles.
  fd.delete('attachments[]');
  selectedFiles.forEach(f => fd.append('attachments[]', f, f.name));

  try {
    const resp = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'  // transmet les cookies de session SSO
    });

    if (!resp.ok) throw new Error('Erreur serveur HTTP ' + resp.status);

    const html    = await resp.text();
    const parser  = new DOMParser();
    const doc     = parser.parseFromString(html, 'text/html');
    const newMain = doc.querySelector('main');

    if (newMain) {
      document.querySelector('main').innerHTML = newMain.innerHTML;
      document.querySelector('main').scrollIntoView({ behavior: 'smooth' });
    } else {
      // Fallback : réécriture complète de la page
      document.open(); document.write(html); document.close();
    }
  } catch (err) {
    alert('Erreur lors de l\'envoi : ' + err.message);
    btn.disabled = false;
    btn.innerHTML = '🔐 Chiffrer et envoyer';
  }
});
</script>
</body>
</html>
