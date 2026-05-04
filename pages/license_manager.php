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

    <!-- Section avancée : gestion des clés -->
    <div class="mt-2">
      <button class="btn btn-link btn-sm text-muted px-0 text-decoration-none"
              type="button" data-bs-toggle="collapse" data-bs-target="#keypairSection">
        <i class="fas fa-chevron-right fa-xs me-1"></i>Gestion des clés Ed25519
      </button>
      <div class="collapse" id="keypairSection">
        <div class="card mt-1">
          <div class="card-body p-3">

            <p class="small text-muted mb-2">
              Génère une nouvelle paire uniquement à la première installation.
              Copier la <strong>clé publique</strong> dans
              <code>config/license/public_key.txt</code>.
              La <strong>clé privée</strong> reste sur ce poste.
            </p>

            <div id="keypairResult" style="display:none" class="mb-2">
              <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="small text-warning fw-semibold">Clé privée — garder secret</span>
                  <button class="btn btn-outline-warning btn-sm py-0 px-1" id="copyPrivBtn">
                    <i class="fas fa-copy fa-xs"></i>
                  </button>
                </div>
                <pre id="kpPrivatePre" class="bg-dark text-warning rounded p-2 small mb-0"
                     style="word-break:break-all;white-space:pre-wrap;max-height:58px;overflow:auto"></pre>
              </div>
              <div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="small text-info fw-semibold">Clé publique → config/license/public_key.txt</span>
                  <button class="btn btn-outline-info btn-sm py-0 px-1" id="copyPubBtn">
                    <i class="fas fa-copy fa-xs"></i>
                  </button>
                </div>
                <pre id="kpPublicPre" class="bg-dark text-info rounded p-2 small mb-0"
                     style="word-break:break-all;white-space:pre-wrap;max-height:58px;overflow:auto"></pre>
              </div>
            </div>

            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnKeypair">
              <i class="fas fa-sync-alt me-1"></i>Générer une nouvelle paire
            </button>

          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php
$extraScript = <<<'JS'
(function () {
  const form     = document.getElementById('licenseForm');
  const btnGen   = document.getElementById('btnGenerate');
  const btnKp    = document.getElementById('btnKeypair');
  const resArea  = document.getElementById('resultArea');
  const kpDiv    = document.getElementById('keypairResult');
  const errDiv   = document.getElementById('licenseError');
  const errMsg   = document.getElementById('licenseErrorMsg');
  const keyPre   = document.getElementById('licenseKeyPre');
  const jsonPre  = document.getElementById('licenseJsonPre');
  const kpPriv   = document.getElementById('kpPrivatePre');
  const kpPub    = document.getElementById('kpPublicPre');
  const copyBtn  = document.getElementById('copyKeyBtn');
  const copyPriv = document.getElementById('copyPrivBtn');
  const copyPub  = document.getElementById('copyPubBtn');
  const sel      = document.getElementById('deviceIdSelect');
  const man      = document.getElementById('deviceIdManual');

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

  copyBtn  && copyBtn.addEventListener('click',  () => copyText(keyPre.textContent, copyBtn));
  copyPriv && copyPriv.addEventListener('click', () => copyText(kpPriv.textContent, copyPriv));
  copyPub  && copyPub.addEventListener('click',  () => copyText(kpPub.textContent,  copyPub));

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

  async function post(action) {
    btnGen.disabled = true;
    if (btnKp) btnKp.disabled = true;
    hide(errDiv); hide(resArea); hide(kpDiv);

    try {
      const resp = await fetch('../api/admin/generate_license.php', {
        method: 'POST', body: buildFormData(action)
      });
      const data = await resp.json();

      if (!data.success) {
        errMsg.textContent = data.message ?? 'Erreur inconnue';
        show(errDiv);
        return;
      }

      if (action === 'keypair') {
        kpPriv.textContent = data.private_key ?? '';
        kpPub.textContent  = data.public_key  ?? '';
        show(kpDiv);
      } else {
        keyPre.textContent  = data.license_key  ?? '';
        jsonPre.textContent = data.license_json ?? '';
        show(resArea);
      }
    } catch (e) {
      errMsg.textContent = 'Erreur réseau : ' + e.message;
      show(errDiv);
    } finally {
      btnGen.disabled = false;
      if (btnKp) btnKp.disabled = false;
    }
  }

  form.addEventListener('submit', e => { e.preventDefault(); post('generate'); });
  btnKp && btnKp.addEventListener('click', () => post('keypair'));
})();
JS;

require_once __DIR__ . '/../includes/layout_footer.php';
