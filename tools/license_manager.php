<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/license.php';

requireAdministratorAccess('Seul l\'administrateur peut accéder au générateur de licence.');

if (PHP_OS_FAMILY !== 'Windows') {
    http_response_code(500);
    echo '<!doctype html><body>Le générateur de licence est disponible uniquement sous Windows.</body>';
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Clé privée par défaut : fichier à côté de l'exe, sinon env */
$defaultPrivateKey = '';
$pkFile = __DIR__ . '/editor-license/private_key.txt';
if (is_file($pkFile)) {
    $defaultPrivateKey = trim((string)file_get_contents($pkFile));
}
if ($defaultPrivateKey === '') {
    $defaultPrivateKey = trim((string)(getenv('RM_LICENSE_PRIVATE_KEY') ?: ''));
}

/* Devices avec fingerprint pour le dropdown */
$store   = loadDeviceStore();
$devices = [];
foreach ($store['devices'] as $d) {
    $fp = trim((string)($d['device_fingerprint'] ?? ''));
    if ($fp === '') {
        continue;
    }
    $type      = (string)($d['type'] ?? 'dev');
    $devices[] = [
        'device_id' => formatDeviceId($fp, $type),
        'label'     => sprintf('%s (%s)', $d['name'] ?? '—', formatDeviceId($fp, $type)),
    ];
}

$pageTitle  = 'Générateur de licence';
$csrfToken  = $_SESSION['csrf_token'];

require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-8 col-lg-10">

    <div class="d-flex align-items-center gap-3 mb-4">
      <i class="fas fa-key fa-2x text-warning"></i>
      <div>
        <h1 class="h3 mb-0">Générateur de licence</h1>
        <p class="text-muted mb-0 small">Réservé à l'éditeur — ne pas distribuer au client</p>
      </div>
    </div>

    <div class="alert alert-warning d-flex gap-2 mb-4" role="alert">
      <i class="fas fa-exclamation-triangle mt-1"></i>
      <span>La clé <strong>privée</strong> ne doit jamais quitter ce poste.
            Ne copiez pas <code>license-generator.exe</code> ni la clé privée dans une installation client.</span>
    </div>

    <!-- Résultat licence -->
    <div id="licenseResult" class="card border-success mb-4" style="display:none">
      <div class="card-header bg-success bg-opacity-10 border-success d-flex justify-content-between align-items-center">
        <span class="text-success fw-semibold"><i class="fas fa-check-circle me-2"></i>Licence générée</span>
        <button type="button" class="btn btn-sm btn-outline-success" id="copyKeyBtn">
          <i class="fas fa-copy me-1"></i>Copier la clé
        </button>
      </div>
      <div class="card-body">
        <label class="form-label fw-semibold">Clé d'activation</label>
        <pre id="licenseKeyPre" class="bg-dark text-success p-3 rounded small mb-3" style="word-break:break-all;white-space:pre-wrap"></pre>
        <details>
          <summary class="text-muted small mb-2" style="cursor:pointer">Voir le JSON complet</summary>
          <pre id="licenseJsonPre" class="bg-dark text-light p-3 rounded small" style="word-break:break-all;white-space:pre-wrap"></pre>
        </details>
      </div>
    </div>

    <!-- Résultat keypair -->
    <div id="keypairResult" class="card border-info mb-4" style="display:none">
      <div class="card-header bg-info bg-opacity-10 border-info">
        <span class="text-info fw-semibold"><i class="fas fa-key me-2"></i>Paire de clés Ed25519 générée</span>
      </div>
      <div class="card-body">
        <div class="alert alert-danger small mb-3">
          <i class="fas fa-lock me-1"></i>
          Sauvegardez la clé <strong>privée</strong> maintenant — elle ne sera plus affichée.
          Copiez la clé <strong>publique</strong> dans <code>config/license/public_key.txt</code>.
        </div>
        <label class="form-label fw-semibold">Clé privée (éditeur)</label>
        <pre id="kpPrivatePre" class="bg-dark text-warning p-3 rounded small mb-3" style="word-break:break-all;white-space:pre-wrap"></pre>
        <label class="form-label fw-semibold">Clé publique (client)</label>
        <pre id="kpPublicPre" class="bg-dark text-info p-3 rounded small" style="word-break:break-all;white-space:pre-wrap"></pre>
      </div>
    </div>

    <!-- Erreur -->
    <div id="licenseError" class="alert alert-danger" style="display:none" role="alert">
      <i class="fas fa-times-circle me-2"></i><span id="licenseErrorMsg"></span>
    </div>

    <!-- Formulaire -->
    <div class="card">
      <div class="card-body">
        <form id="licenseForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Customer ID <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="customer_id"
                     placeholder="CLIENT-001" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">Device ID <span class="text-danger">*</span></label>
              <?php if (!empty($devices)): ?>
              <select class="form-select" name="device_id" id="deviceIdSelect">
                <option value="">— Saisir manuellement —</option>
                <?php foreach ($devices as $dev): ?>
                <option value="<?= htmlspecialchars($dev['device_id'], ENT_QUOTES) ?>">
                  <?= htmlspecialchars($dev['label'], ENT_QUOTES) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <input type="text" class="form-control mt-2" name="device_id_manual"
                     id="deviceIdManual" placeholder="MK-XXXX-XXXX-XXXX"
                     style="display:none">
              <?php else: ?>
              <input type="text" class="form-control" name="device_id"
                     placeholder="MK-XXXX-XXXX-XXXX" required>
              <?php endif; ?>
            </div>

            <div class="col-md-4">
              <label class="form-label">Type NAS <span class="text-danger">*</span></label>
              <select class="form-select" name="nas_type">
                <option value="mikrotik">MikroTik</option>
                <option value="opnsense">OPNsense</option>
                <option value="radius">RADIUS</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Édition <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="edition"
                     value="standard" required>
            </div>

            <div class="col-md-4">
              <label class="form-label">Expiration</label>
              <input type="text" class="form-control" name="expires_at"
                     value="never" placeholder="never ou yyyy-mm-dd">
            </div>

            <div class="col-12">
              <label class="form-label">Features</label>
              <input type="text" class="form-control" name="features"
                     placeholder="vouchers,recharge,reports  (laisser vide = aucune)">
            </div>

            <div class="col-12">
              <label class="form-label">Clé privée éditeur Ed25519 (base64) <span class="text-danger">*</span></label>
              <textarea class="form-control font-monospace" name="private_key"
                        rows="3" placeholder="RM_LICENSE_PRIVATE_KEY"
                        style="font-size:.8rem"><?= htmlspecialchars($defaultPrivateKey, ENT_QUOTES) ?></textarea>
              <div class="form-text">Non sauvegardée. Chargée depuis
                <code>tools/editor-license/private_key.txt</code> ou
                <code>RM_LICENSE_PRIVATE_KEY</code>.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Fichier de sortie <span class="text-muted">(optionnel)</span></label>
              <input type="text" class="form-control font-monospace" name="output_path"
                     placeholder="C:\licences\client-license.json">
            </div>

          </div>

          <div class="d-flex gap-2 flex-wrap mt-4">
            <button type="submit" class="btn btn-primary" id="btnGenerate">
              <i class="fas fa-key me-2"></i>Générer la licence
            </button>
            <button type="button" class="btn btn-outline-info" id="btnKeypair">
              <i class="fas fa-sync-alt me-2"></i>Générer une paire de clés
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</div>

<?php
$extraScript = <<<'JS'
(function () {
  const form    = document.getElementById('licenseForm');
  const btnGen  = document.getElementById('btnGenerate');
  const btnKp   = document.getElementById('btnKeypair');
  const resDiv  = document.getElementById('licenseResult');
  const kpDiv   = document.getElementById('keypairResult');
  const errDiv  = document.getElementById('licenseError');
  const errMsg  = document.getElementById('licenseErrorMsg');
  const copyBtn = document.getElementById('copyKeyBtn');
  const keyPre  = document.getElementById('licenseKeyPre');
  const jsonPre = document.getElementById('licenseJsonPre');
  const kpPriv  = document.getElementById('kpPrivatePre');
  const kpPub   = document.getElementById('kpPublicPre');

  const sel = document.getElementById('deviceIdSelect');
  const man = document.getElementById('deviceIdManual');
  if (sel) {
    sel.addEventListener('change', function () {
      const manual = this.value === '';
      man.style.display = manual ? '' : 'none';
      man.required      = manual;
      this.required     = !manual;
    });
  }

  function hide(el) { el.style.display = 'none'; }
  function show(el) { el.style.display = '';     }

  function showError(msg) {
    hide(resDiv); hide(kpDiv);
    errMsg.textContent = msg;
    show(errDiv);
  }

  function buildFormData(action) {
    const fd = new FormData(form);
    fd.set('action', action);
    if (sel) {
      fd.set('device_id', sel.value !== '' ? sel.value : (man ? man.value.trim() : ''));
      fd.delete('device_id_manual');
    }
    return fd;
  }

  async function post(action) {
    btnGen.disabled = true;
    btnKp.disabled  = true;
    hide(errDiv); hide(resDiv); hide(kpDiv);

    try {
      const resp = await fetch('../api/admin/generate_license.php', {
        method: 'POST',
        body:   buildFormData(action),
      });
      const data = await resp.json();

      if (!data.success) {
        showError(data.message ?? 'Erreur inconnue');
        return;
      }

      if (action === 'keypair') {
        kpPriv.textContent = data.private_key ?? '';
        kpPub.textContent  = data.public_key  ?? '';
        show(kpDiv);
      } else {
        keyPre.textContent  = data.license_key  ?? '';
        jsonPre.textContent = data.license_json ?? '';
        show(resDiv);
      }
    } catch (e) {
      showError('Erreur réseau : ' + e.message);
    } finally {
      btnGen.disabled = false;
      btnKp.disabled  = false;
    }
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    post('generate');
  });

  btnKp.addEventListener('click', function () { post('keypair'); });

  copyBtn.addEventListener('click', function () {
    navigator.clipboard.writeText(keyPre.textContent).then(function () {
      copyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Copié !';
      setTimeout(function () {
        copyBtn.innerHTML = '<i class="fas fa-copy me-1"></i>Copier la clé';
      }, 2000);
    });
  });
})();
JS;

require_once __DIR__ . '/../includes/layout_footer.php';
