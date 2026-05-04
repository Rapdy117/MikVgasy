<?php
session_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/device_manager.php';
require_once __DIR__ . '/../includes/license.php';

requireAdministratorAccess('Seul l\'administrateur peut accéder au générateur de licence.');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$defaultPrivateKey = '';
$pkFile = __DIR__ . '/../tools/editor-license/private_key.txt';
if (is_file($pkFile)) {
    $defaultPrivateKey = trim((string)file_get_contents($pkFile));
}
if ($defaultPrivateKey === '') {
    $defaultPrivateKey = trim((string)(getenv('RM_LICENSE_PRIVATE_KEY') ?: ''));
}
$keyLoaded = $defaultPrivateKey !== '';

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

$pageTitle = 'Générateur de licence';
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="row justify-content-center">
  <div class="col-xl-6 col-lg-8 col-md-10">

    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="fas fa-key text-warning"></i>
      <h1 class="h5 mb-0 fw-semibold">Générateur de licence</h1>
      <span class="badge bg-secondary ms-auto small">Éditeur uniquement</span>
    </div>

    <div class="card">
      <div class="card-body p-3">

        <!-- Résultat licence -->
        <div id="resultArea" style="display:none" class="mb-3">
          <div class="d-flex align-items-center justify-content-between rounded p-2 mb-1"
               style="background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3)">
            <span class="text-success small fw-semibold">
              <i class="fas fa-check-circle me-1"></i>Licence générée
            </span>
            <button class="btn btn-sm btn-outline-success py-0 px-2" id="copyKeyBtn">
              <i class="fas fa-copy me-1"></i>Copier
            </button>
          </div>
          <pre id="licenseKeyPre"
               class="bg-dark text-success rounded p-2 small mb-1"
               style="word-break:break-all;white-space:pre-wrap;max-height:72px;overflow:auto"></pre>
          <details>
            <summary class="text-muted" style="cursor:pointer;font-size:.78rem">JSON complet</summary>
            <pre id="licenseJsonPre"
                 class="bg-dark text-light rounded p-2 small mt-1"
                 style="word-break:break-all;white-space:pre-wrap;max-height:140px;overflow:auto"></pre>
          </details>
        </div>

        <!-- Erreur -->
        <div id="licenseError" class="alert alert-danger py-2 mb-3 small" style="display:none">
          <i class="fas fa-times-circle me-1"></i><span id="licenseErrorMsg"></span>
        </div>

        <!-- Formulaire -->
        <form id="licenseForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">

          <div class="row g-2">

            <div class="col-5">
              <label class="form-label form-label-sm mb-1">Client <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" name="customer_id"
                     placeholder="CLIENT-001" required>
            </div>

            <div class="col-7">
              <label class="form-label form-label-sm mb-1">Device ID <span class="text-danger">*</span></label>
              <?php if (!empty($devices)): ?>
              <select class="form-select form-select-sm" name="device_id" id="deviceIdSelect">
                <option value="">— Manuel —</option>
                <?php foreach ($devices as $dev): ?>
                <option value="<?= htmlspecialchars($dev['device_id'], ENT_QUOTES) ?>">
                  <?= htmlspecialchars($dev['label'], ENT_QUOTES) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <input type="text" class="form-control form-control-sm mt-1" id="deviceIdManual"
                     placeholder="MK-XXXX-XXXX-XXXX" style="display:none">
              <?php else: ?>
              <input type="text" class="form-control form-control-sm" name="device_id"
                     placeholder="MK-XXXX-XXXX-XXXX" required>
              <?php endif; ?>
            </div>

            <div class="col-4">
              <label class="form-label form-label-sm mb-1">Type</label>
              <select class="form-select form-select-sm" name="nas_type">
                <option value="mikrotik">MikroTik</option>
                <option value="opnsense">OPNsense</option>
                <option value="radius">RADIUS</option>
              </select>
            </div>

            <div class="col-4">
              <label class="form-label form-label-sm mb-1">Édition</label>
              <input type="text" class="form-control form-control-sm" name="edition" value="standard">
            </div>

            <div class="col-4">
              <label class="form-label form-label-sm mb-1">Expiration</label>
              <input type="text" class="form-control form-control-sm" name="expires_at" value="never">
            </div>

            <div class="col-12">
              <label class="form-label form-label-sm mb-1">
                Features <span class="text-muted fw-normal">(optionnel)</span>
              </label>
              <input type="text" class="form-control form-control-sm" name="features"
                     placeholder="vouchers,recharge,reports">
            </div>

            <!-- Clé privée compacte -->
            <div class="col-12">
              <div class="d-flex align-items-center gap-2 rounded px-2 py-1"
                   style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1)">
                <i class="fas fa-lock fa-sm text-<?= $keyLoaded ? 'success' : 'warning' ?>"></i>
                <span class="small text-<?= $keyLoaded ? 'success' : 'warning' ?>">
                  <?= $keyLoaded ? 'Clé privée chargée' : 'Clé privée manquante' ?>
                </span>
                <button type="button"
                        class="btn btn-link btn-sm py-0 ms-auto text-muted text-decoration-none"
                        data-bs-toggle="collapse" data-bs-target="#privateKeyCollapse">
                  <i class="fas fa-pencil-alt fa-xs"></i> modifier
                </button>
              </div>
              <div class="collapse<?= $keyLoaded ? '' : ' show' ?>" id="privateKeyCollapse">
                <textarea class="form-control form-control-sm font-monospace mt-1"
                          name="private_key" rows="2"
                          placeholder="Clé privée Ed25519 base64..."
                          style="font-size:.73rem"><?= htmlspecialchars($defaultPrivateKey, ENT_QUOTES) ?></textarea>
              </div>
            </div>

          </div>

          <div class="mt-3">
            <button type="submit" class="btn btn-primary btn-sm px-3" id="btnGenerate">
              <i class="fas fa-key me-1"></i>Générer la licence
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
  const resArea = document.getElementById('resultArea');
  const errDiv  = document.getElementById('licenseError');
  const errMsg  = document.getElementById('licenseErrorMsg');
  const keyPre  = document.getElementById('licenseKeyPre');
  const jsonPre = document.getElementById('licenseJsonPre');
  const copyBtn = document.getElementById('copyKeyBtn');
  const sel     = document.getElementById('deviceIdSelect');
  const man     = document.getElementById('deviceIdManual');

  if (sel) {
    sel.addEventListener('change', function () {
      man.style.display = this.value === '' ? '' : 'none';
    });
  }

  function copyText(text, btn) {
    const orig = btn.innerHTML;
    const ok   = () => {
      btn.innerHTML = '<i class="fas fa-check fa-xs"></i>';
      setTimeout(() => { btn.innerHTML = orig; }, 1800);
    };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).then(ok);
    } else {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta); ok();
    }
  }

  copyBtn && copyBtn.addEventListener('click', () => copyText(keyPre.textContent, copyBtn));

  function hide(el) { el.style.display = 'none'; }
  function show(el) { el.style.display = '';     }

  function buildFormData(action) {
    const fd = new FormData(form);
    fd.set('action', action);
    if (sel) {
      fd.set('device_id', sel.value !== '' ? sel.value : (man ? man.value.trim() : ''));
    }
    return fd;
  }

  async function post() {
    btnGen.disabled = true;
    hide(errDiv); hide(resArea);

    try {
      const resp = await fetch('../api/admin/generate_license.php', {
        method: 'POST', body: buildFormData('generate')
      });
      const data = await resp.json();

      if (!data.success) {
        errMsg.textContent = data.message ?? 'Erreur inconnue';
        show(errDiv);
        return;
      }

      keyPre.textContent  = data.license_key  ?? '';
      jsonPre.textContent = data.license_json ?? '';
      show(resArea);
    } catch (e) {
      errMsg.textContent = 'Erreur réseau : ' + e.message;
      show(errDiv);
    } finally {
      btnGen.disabled = false;
    }
  }

  form.addEventListener('submit', e => { e.preventDefault(); post(); });
})();
JS;

require_once __DIR__ . '/../includes/layout_footer.php';
