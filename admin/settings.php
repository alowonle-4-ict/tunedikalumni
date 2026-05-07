<?php
require_once dirname(__DIR__) . '/config/app.php';

$pageTitle = 'System Settings';
$activeNav = 'settings';

// ── POST handling before any HTML output so redirect() exits cleanly ──
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $section = $_POST['section'] ?? '';

    // ── Branding ─────────────────────────────────────────
    if ($section === 'branding') {
        updateSetting('site_name',     trim($_POST['site_name']     ?? ''));
        updateSetting('site_email',    trim($_POST['site_email']    ?? ''));
        updateSetting('site_phone',    trim($_POST['site_phone']    ?? ''));
        updateSetting('site_address',  trim($_POST['site_address']  ?? ''));
        updateSetting('developer_url', trim($_POST['developer_url'] ?? ''));

        // Logo upload
        if (!empty($_FILES['logo']['name'])) {
            $up = validateAndSaveUpload($_FILES['logo'], ROOT_PATH . '/assets/uploads/logo/');
            if ($up['ok']) {
                // Delete old logo
                $old = getSetting('logo');
                if ($old) @unlink(ROOT_PATH . '/assets/uploads/logo/' . $old);
                updateSetting('logo', $up['filename']);
            } else {
                $errors[] = 'Logo: ' . $up['error'];
            }
        }

        // Favicon upload
        if (!empty($_FILES['favicon']['name'])) {
            $up = validateAndSaveUpload($_FILES['favicon'], ROOT_PATH . '/assets/uploads/favicon/');
            if ($up['ok']) {
                $old = getSetting('favicon');
                if ($old) @unlink(ROOT_PATH . '/assets/uploads/favicon/' . $old);
                updateSetting('favicon', $up['filename']);
            } else {
                $errors[] = 'Favicon: ' . $up['error'];
            }
        }

        if (empty($errors)) {
            setFlash('success', 'Branding settings saved.');
            redirect(BASE_URL . '/admin/settings.php#branding');
        }
    }

    // ── Social Links ──────────────────────────────────────
    if ($section === 'social') {
        updateSetting('facebook_url',  trim($_POST['facebook_url']  ?? ''));
        updateSetting('twitter_url',   trim($_POST['twitter_url']   ?? ''));
        updateSetting('instagram_url', trim($_POST['instagram_url'] ?? ''));
        updateSetting('linkedin_url',  trim($_POST['linkedin_url']  ?? ''));
        updateSetting('whatsapp_number', trim($_POST['whatsapp_number'] ?? ''));
        setFlash('success', 'Social & WhatsApp settings saved.');
        redirect(BASE_URL . '/admin/settings.php#social');
    }

    // ── Payment Config ────────────────────────────────────
    if ($section === 'payment') {
        updateSetting('paystack_public_key', trim($_POST['paystack_public_key'] ?? ''));
        updateSetting('paystack_secret_key', trim($_POST['paystack_secret_key'] ?? ''));
        updateSetting('bank_name',     trim($_POST['bank_name']     ?? ''));
        updateSetting('account_number', trim($_POST['account_number'] ?? ''));
        updateSetting('account_name',  trim($_POST['account_name']  ?? ''));
        updateSetting('membership_fee', trim($_POST['membership_fee'] ?? '5000'));
        setFlash('success', 'Payment settings saved.');
        redirect(BASE_URL . '/admin/settings.php#payment');
    }

    // ── ID Card ──────────────────────────────────────────
    if ($section === 'idcard') {
        updateSetting('id_card_back_content', trim($_POST['id_card_back_content'] ?? ''));
        // President signature upload
        if (!empty($_FILES['president_signature']['name'])) {
            $destDir = ROOT_PATH . '/assets/uploads/signatures/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $up = validateAndSaveUpload($_FILES['president_signature'], $destDir);
            if ($up['ok']) {
                $old = getSetting('president_signature');
                if ($old) @unlink($destDir . $old);
                updateSetting('president_signature', $up['filename']);
            } else {
                $errors[] = 'Signature: ' . $up['error'];
            }
        }
        if (empty($errors)) {
            setFlash('success', 'ID Card settings saved.');
            redirect(BASE_URL . '/admin/settings.php#idcard');
        }
    }

    // ── Documents ────────────────────────────────────────
    if ($section === 'documents') {
        if (!empty($_FILES['constitution_pdf']['name'])) {
            $destDir = ROOT_PATH . '/assets/uploads/constitution/';
            $up = validateAndSaveUpload($_FILES['constitution_pdf'], $destDir);
            if ($up['ok']) {
                // Delete old constitution file
                $old = getSetting('constitution_pdf');
                if ($old) @unlink(ROOT_PATH . '/assets/uploads/constitution/' . $old);
                updateSetting('constitution_pdf', $up['filename']);
                setFlash('success', 'Alumni Constitution uploaded successfully.');
            } else {
                $errors[] = 'Constitution: ' . $up['error'];
            }
        } else {
            setFlash('danger', 'Please select a PDF file to upload.');
        }
        if (empty($errors)) {
            redirect(BASE_URL . '/admin/settings.php#documents');
        }
    }

    // ── SMTP ─────────────────────────────────────────────
    if ($section === 'smtp') {
        updateSetting('smtp_host',       trim($_POST['smtp_host']       ?? ''));
        updateSetting('smtp_port',       trim($_POST['smtp_port']       ?? '587'));
        updateSetting('smtp_username',   trim($_POST['smtp_username']   ?? ''));
        updateSetting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
        updateSetting('smtp_from_name',  trim($_POST['smtp_from_name']  ?? ''));
        updateSetting('smtp_encryption', trim($_POST['smtp_encryption'] ?? 'tls'));
        // Only update password if provided (avoid clearing it)
        if (!empty($_POST['smtp_password'])) {
            updateSetting('smtp_password', $_POST['smtp_password']);
        }
        setFlash('success', 'SMTP email settings saved.');
        redirect(BASE_URL . '/admin/settings.php#smtp');
    }
}

$s = []; // pull all current settings into a helper array
foreach (['site_name','site_email','site_phone','site_address','logo','favicon','developer_url',
          'facebook_url','twitter_url','instagram_url','linkedin_url','whatsapp_number',
          'paystack_public_key','paystack_secret_key','bank_name','account_number','account_name','membership_fee',
          'smtp_host','smtp_port','smtp_username','smtp_password','smtp_from_email','smtp_from_name','smtp_encryption',
          'constitution_pdf','id_card_back_content','president_signature'] as $key) {
    $s[$key] = getSetting($key);
}

$logoSrc    = $s['logo']    ? BASE_URL . '/assets/uploads/logo/'    . htmlspecialchars($s['logo'],    ENT_QUOTES) : null;
$faviconSrc = $s['favicon'] ? BASE_URL . '/assets/uploads/favicon/' . htmlspecialchars($s['favicon'], ENT_QUOTES) : null;

require_once __DIR__ . '/includes/admin_header.php';
?>

<h4 class="fw-bold mb-4"><i class="bi bi-gear-fill me-2"></i>System Settings</h4>

<?= renderFlash() ?>
<?php if ($errors): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-bottom: 2rem; border-radius: 12px; border-left: 5px solid #dc3545; box-shadow: 0 4px 16px rgba(220, 53, 69, 0.2);">
    <div style="display: flex; align-items: flex-start; gap: 12px;">
      <i class="bi bi-exclamation-circle-fill" style="flex-shrink: 0; font-size: 1.2rem; margin-top: 2px;"></i>
      <div style="flex-grow: 1;">
        <strong style="display: block; margin-bottom: 8px;">Unable to save settings:</strong>
        <?php foreach ($errors as $e): ?><div style="margin: 4px 0; font-size: 0.95rem;">• <?= htmlspecialchars($e, ENT_QUOTES) ?></div><?php endforeach; ?>
      </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<!-- ── Tabs ───────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" id="settingsTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#branding">Branding</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#social">Social & WhatsApp</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#payment">Payments</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#smtp">Email (SMTP)</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#documents">
    <i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Documents
  </a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#idcard">
    <i class="bi bi-person-badge me-1 text-primary"></i>ID Card
  </a></li>
</ul>

<div class="tab-content">

  <!-- ── Branding Tab ──────────────────────────────────── -->
  <div class="tab-pane fade show active" id="branding">
    <div class="card">
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="branding">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Site Name</label>
              <input type="text" name="site_name" class="form-control"
                     value="<?= htmlspecialchars($s['site_name'], ENT_QUOTES) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contact Email</label>
              <input type="email" name="site_email" class="form-control"
                     value="<?= htmlspecialchars($s['site_email'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="text" name="site_phone" class="form-control"
                     value="<?= htmlspecialchars($s['site_phone'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="site_address" class="form-control"
                     value="<?= htmlspecialchars($s['site_address'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Developer Website URL</label>
              <input type="url" name="developer_url" class="form-control"
                     value="<?= htmlspecialchars($s['developer_url'], ENT_QUOTES) ?>"
                     placeholder="https://yourdomain.com">
              <div class="form-text">Shown as a link on "Developed by Adigun Nurudeen Adio" in the footer. Leave blank to show plain text.</div>
            </div>
            <div class="col-md-6"><!-- spacer --></div>

            <!-- Logo -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Site Logo</label>
              <input type="file" name="logo" id="logo-upload" class="form-control"
                     accept="image/jpeg,image/png,image/gif">
              <div class="form-text">JPG, PNG, GIF. Recommended: 200×60 px.</div>
              <?php if ($logoSrc): ?>
                <img id="logo-preview" src="<?= $logoSrc ?>" alt="Logo" height="40" class="mt-2 border rounded p-1 bg-light">
              <?php else: ?>
                <img id="logo-preview" src="" alt="" height="40" class="mt-2 border rounded p-1 bg-light" style="display:none">
              <?php endif; ?>
            </div>

            <!-- Favicon -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Favicon</label>
              <input type="file" name="favicon" id="favicon-upload" class="form-control"
                     accept="image/jpeg,image/png,image/gif,image/x-icon">
              <div class="form-text">PNG or ICO. Recommended: 32×32 px.</div>
              <?php if ($faviconSrc): ?>
                <img id="favicon-preview" src="<?= $faviconSrc ?>" alt="Favicon" height="32" class="mt-2">
              <?php else: ?>
                <img id="favicon-preview" src="" alt="" height="32" class="mt-2" style="display:none">
              <?php endif; ?>
            </div>

            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Branding
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Social Tab ────────────────────────────────────── -->
  <div class="tab-pane fade" id="social">
    <div class="card">
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="social">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-facebook me-1 text-primary"></i>Facebook URL</label>
              <input type="url" name="facebook_url" class="form-control"
                     value="<?= htmlspecialchars($s['facebook_url'], ENT_QUOTES) ?>"
                     placeholder="https://facebook.com/yourpage">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-twitter-x me-1"></i>Twitter / X URL</label>
              <input type="url" name="twitter_url" class="form-control"
                     value="<?= htmlspecialchars($s['twitter_url'], ENT_QUOTES) ?>"
                     placeholder="https://x.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-instagram me-1 text-danger"></i>Instagram URL</label>
              <input type="url" name="instagram_url" class="form-control"
                     value="<?= htmlspecialchars($s['instagram_url'], ENT_QUOTES) ?>"
                     placeholder="https://instagram.com/yourhandle">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-linkedin me-1 text-primary"></i>LinkedIn URL</label>
              <input type="url" name="linkedin_url" class="form-control"
                     value="<?= htmlspecialchars($s['linkedin_url'], ENT_QUOTES) ?>"
                     placeholder="https://linkedin.com/company/yourpage">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold"><i class="bi bi-whatsapp me-1 text-success"></i>WhatsApp Number</label>
              <input type="text" name="whatsapp_number" class="form-control"
                     value="<?= htmlspecialchars($s['whatsapp_number'], ENT_QUOTES) ?>"
                     placeholder="+234XXXXXXXXXX (international format)">
              <div class="form-text">Include country code, digits only after '+'. Leave blank to hide the widget.</div>
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Social Settings
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Payment Tab ───────────────────────────────────── -->
  <div class="tab-pane fade" id="payment">
    <div class="card">
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="payment">
          <div class="row g-3">
            <div class="col-12">
              <h6 class="settings-section-title">Membership Fee</h6>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Annual Fee (₦)</label>
              <input type="number" name="membership_fee" class="form-control"
                     value="<?= htmlspecialchars($s['membership_fee'], ENT_QUOTES) ?>"
                     min="0" step="100" required>
            </div>

            <div class="col-12 mt-3">
              <h6 class="settings-section-title">Paystack Configuration</h6>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Public Key</label>
              <input type="text" name="paystack_public_key" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s['paystack_public_key'], ENT_QUOTES) ?>"
                     placeholder="pk_live_xxxx or pk_test_xxxx">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Secret Key</label>
              <div class="input-group">
                <input type="password" name="paystack_secret_key" id="psk" class="form-control font-monospace"
                       value="<?= htmlspecialchars($s['paystack_secret_key'], ENT_QUOTES) ?>"
                       placeholder="sk_live_xxxx or sk_test_xxxx">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleField('psk','psk-eye')">
                  <i class="bi bi-eye" id="psk-eye"></i>
                </button>
              </div>
            </div>

            <div class="col-12 mt-3">
              <h6 class="settings-section-title">Bank Transfer Details (Offline Payments)</h6>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Bank Name</label>
              <input type="text" name="bank_name" class="form-control"
                     value="<?= htmlspecialchars($s['bank_name'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Account Number</label>
              <input type="text" name="account_number" class="form-control font-monospace"
                     value="<?= htmlspecialchars($s['account_number'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Account Name</label>
              <input type="text" name="account_name" class="form-control"
                     value="<?= htmlspecialchars($s['account_name'], ENT_QUOTES) ?>">
            </div>

            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save Payment Settings
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── SMTP Tab ──────────────────────────────────────── -->
  <div class="tab-pane fade" id="smtp">
    <div class="card">
      <div class="card-body">
        <div class="alert alert-info small">
          <i class="bi bi-info-circle me-1"></i>
          These settings configure outbound email via PHPMailer. For Gmail, use App Passwords.
          <a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener" class="alert-link">Learn more</a>
        </div>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="smtp">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">SMTP Host</label>
              <input type="text" name="smtp_host" class="form-control"
                     value="<?= htmlspecialchars($s['smtp_host'], ENT_QUOTES) ?>"
                     placeholder="smtp.gmail.com">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Port</label>
              <input type="number" name="smtp_port" class="form-control"
                     value="<?= htmlspecialchars($s['smtp_port'], ENT_QUOTES) ?>"
                     placeholder="587">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Encryption</label>
              <select name="smtp_encryption" class="form-select">
                <option value="tls" <?= $s['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                <option value="ssl" <?= $s['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">SMTP Username</label>
              <input type="text" name="smtp_username" class="form-control"
                     value="<?= htmlspecialchars($s['smtp_username'], ENT_QUOTES) ?>"
                     placeholder="your@gmail.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">SMTP Password</label>
              <div class="input-group">
                <input type="password" name="smtp_password" id="smtp-pwd" class="form-control"
                       placeholder="Leave blank to keep current">
                <button type="button" class="btn btn-outline-secondary" onclick="toggleField('smtp-pwd','smtp-eye')">
                  <i class="bi bi-eye" id="smtp-eye"></i>
                </button>
              </div>
              <div class="form-text">Leave blank to keep the existing password.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">From Email</label>
              <input type="email" name="smtp_from_email" class="form-control"
                     value="<?= htmlspecialchars($s['smtp_from_email'], ENT_QUOTES) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">From Name</label>
              <input type="text" name="smtp_from_name" class="form-control"
                     value="<?= htmlspecialchars($s['smtp_from_name'], ENT_QUOTES) ?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save SMTP Settings
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Documents Tab ───────────────────────────────── -->
  <div class="tab-pane fade" id="documents">
    <div class="card">
      <div class="card-body">
        <h6 class="fw-semibold mb-3"><i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i>Alumni Constitution</h6>
        <p class="text-muted small mb-3">
          Upload the Alumni Constitution PDF. Once uploaded, all members will see a
          <strong>View Constitution</strong> button on their dashboard.
        </p>

        <?php if ($s['constitution_pdf']): ?>
          <div class="alert alert-success d-flex align-items-center gap-3 mb-3">
            <i class="bi bi-file-earmark-check-fill fs-4"></i>
            <div class="flex-grow-1">
              <strong>Constitution is currently uploaded.</strong>
              <div class="small text-muted">File: <?= htmlspecialchars($s['constitution_pdf'], ENT_QUOTES) ?></div>
            </div>
            <a href="<?= BASE_URL ?>/assets/uploads/constitution/<?= urlencode($s['constitution_pdf']) ?>"
               target="_blank" class="btn btn-sm btn-outline-success" download>
              <i class="bi bi-download me-1"></i>Preview
            </a>
          </div>
        <?php else: ?>
          <div class="alert alert-warning small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            No constitution has been uploaded yet. Members will not see the download button until one is uploaded.
          </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="documents">
          <div class="mb-3">
            <label class="form-label fw-semibold">
              <?= $s['constitution_pdf'] ? 'Replace Constitution PDF' : 'Upload Constitution PDF' ?>
              <span class="text-danger">*</span>
            </label>
            <input type="file" name="constitution_pdf" class="form-control" required
                   accept="application/pdf,.pdf">
            <div class="form-text">PDF only. Max 10 MB. <?= $s['constitution_pdf'] ? 'Uploading a new file will replace the existing one.' : '' ?></div>
          </div>
          <button type="submit" class="btn btn-danger">
            <i class="bi bi-upload me-1"></i><?= $s['constitution_pdf'] ? 'Replace Constitution' : 'Upload Constitution' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── ID Card Tab ─────────────────────────────────── -->
  <div class="tab-pane fade" id="idcard">
    <div class="card">
      <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <input type="hidden" name="section" value="idcard">
          <div class="row g-3">

            <div class="col-12">
              <h6 class="fw-semibold"><i class="bi bi-card-text me-2"></i>Back of ID Card Content</h6>
              <p class="text-muted small mb-2">
                This text appears on the back of every member's ID card. Use it for association rules,
                emergency contact, disclaimer, or any other important information.
              </p>
              <textarea name="id_card_back_content" class="form-control" rows="8"
                        placeholder="e.g. This card is the property of TUNEDIK Alumni Association..."><?= htmlspecialchars($s['id_card_back_content'] ?? '', ENT_QUOTES) ?></textarea>
            </div>

            <div class="col-md-6">
              <h6 class="fw-semibold"><i class="bi bi-pen me-2"></i>President's Signature</h6>
              <p class="text-muted small mb-2">
                Upload a PNG/JPG image of the president's signature. It will appear at the bottom of the ID card back.
              </p>
              <input type="file" name="president_signature" class="form-control"
                     accept="image/png,image/jpeg,image/gif">
              <div class="form-text">PNG with transparent background recommended. Max 2 MB.</div>
              <?php if (!empty($s['president_signature'])): ?>
                <div class="mt-2 p-2 border rounded bg-light d-inline-block">
                  <img src="<?= BASE_URL ?>/assets/uploads/signatures/<?= htmlspecialchars($s['president_signature'], ENT_QUOTES) ?>"
                       alt="President Signature" style="max-height:60px">
                </div>
                <div class="form-text text-muted mt-1">Current signature (upload a new one to replace).</div>
              <?php endif; ?>
            </div>

            <div class="col-12">
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Save ID Card Settings
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

</div><!-- /tab-content -->

<script>
function toggleField(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  input.type  = input.type === 'password' ? 'text' : 'password';
  icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

// Add loading state to all submit buttons in settings forms
(function () {
  const forms = document.querySelectorAll('.tab-pane form');
  
  forms.forEach(form => {
    form.addEventListener('submit', function (e) {
      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) {
        submitBtn.disabled = true;
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Saving...';
      }
      
      // Restore button if form validation fails (optional timeout safety)
      setTimeout(() => {
        if (submitBtn && document.activeElement === document.body) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = originalHTML;
        }
      }, 5000);
    });
  });
})();

// Restore active tab after redirect (runs after Bootstrap JS is loaded)
document.addEventListener('DOMContentLoaded', function () {
  const hash = location.hash;
  if (hash) {
    const tab = document.querySelector('[href="' + hash + '"]');
    if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
  }
});

// Enhance flash notification visibility
(function () {
  const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      if (mutation.addedNodes.length) {
        mutation.addedNodes.forEach(function(node) {
          if (node.id && node.id.startsWith('flash-')) {
            // Make the flash notification more prominent by scrolling to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
            // Optional: add a subtle pulse effect
            node.style.animation = 'pulse 0.6s ease-in-out';
          }
        });
      }
    });
  });
  
  observer.observe(document.body, { childList: true });
})();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
