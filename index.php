<?php
require_once __DIR__ . '/config/app.php';

$pageTitle  = 'Home';
$siteName   = getSetting('site_name', 'TUNEDIK Alumni');
$memberFee  = formatCurrency((float)getSetting('membership_fee', '5000'));

$db = getDB();

// Auto-add date_of_birth column if not yet migrated
try { $db->query('SELECT date_of_birth FROM users LIMIT 0'); }
catch (\PDOException $e) { $db->exec('ALTER TABLE users ADD COLUMN date_of_birth DATE DEFAULT NULL'); }

// Today's birthday celebrants (active members only)
$birthdayMembers = $db->prepare(
    "SELECT u.first_name, u.last_name, u.profile_picture, u.current_job_role, u.department,
            m.membership_id
     FROM users u
     JOIN memberships m ON m.user_id = u.id
     WHERE m.status = 'active'
       AND u.date_of_birth IS NOT NULL
       AND MONTH(u.date_of_birth) = MONTH(CURDATE())
       AND DAY(u.date_of_birth)   = DAY(CURDATE())
     ORDER BY u.first_name"
);
$birthdayMembers->execute();
$celebrants = $birthdayMembers->fetchAll();

// Stats for counter section
$statTotalUsers    = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$statActiveMembers = (int)$db->query('SELECT COUNT(*) FROM memberships WHERE status = "active"')->fetchColumn();
$statStates        = (int)$db->query('SELECT COUNT(DISTINCT state) FROM users WHERE state IS NOT NULL AND state != ""')->fetchColumn();
$statYears         = max(1, (int)date('Y') - 2008);

function statBadge(int $n): string {
    if ($n < 100) return $n . '+';
    return (floor($n / 100) * 100) . '+';
}

// Active donation campaigns for homepage
$activeCampaigns = $db->query(
    'SELECT dc.*,
            bu.first_name AS benef_first, bu.last_name AS benef_last,
            COALESCE(ds.raised, 0) AS raised,
            COALESCE(ds.donors, 0) AS donors
     FROM donation_campaigns dc
     LEFT JOIN users bu ON bu.id = dc.beneficiary_user_id
     LEFT JOIN (
         SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donors
         FROM donation_payments WHERE status = "success"
         GROUP BY campaign_id
     ) ds ON ds.campaign_id = dc.id
     WHERE dc.status = "active"
       AND (dc.deadline IS NULL OR dc.deadline >= CURDATE())
     ORDER BY dc.created_at DESC
     LIMIT 6'
)->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- ── Hero ──────────────────────────────────────────────────── -->
<section class="hero-section">
  <div class="container text-center">
    <div class="mb-4">
      <i class="bi bi-mortarboard-fill" style="font-size:4rem;color:var(--accent)"></i>
    </div>
    <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></h1>
    <p class="lead mb-4 opacity-75">
      Connecting alumni across Nigeria and beyond. Join your community, renew your membership,
      and stay engaged with fellow graduates.
    </p>
    <?php if (!isLoggedIn()): ?>
      <div class="d-flex flex-wrap gap-3 justify-content-center mt-2">
        <a href="<?= BASE_URL ?>/pages/register.php" class="btn btn-warning btn-lg fw-bold px-5">
          <i class="bi bi-person-plus me-2"></i>Join Now
        </a>
        <a href="<?= BASE_URL ?>/pages/login.php" class="btn btn-outline-light btn-lg px-5">
          <i class="bi bi-box-arrow-in-right me-2"></i>Member Login
        </a>
      </div>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/pages/dashboard.php" class="btn btn-warning btn-lg fw-bold px-5">
        <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
      </a>
    <?php endif; ?>
  </div>
</section>

<!-- ── Birthday Celebrations ──────────────────────────────────── -->
<?php if (!empty($celebrants)): ?>
<section class="py-4" style="background:linear-gradient(135deg,#fff8e1,#fff3cd);">
  <div class="container">

    <!-- Header -->
    <div class="text-center mb-4">
      <div class="d-inline-flex align-items-center gap-2 px-4 py-2 rounded-pill mb-2"
           style="background:#fff9c4;border:2px solid #f9a825;">
        <span style="font-size:1.6rem;">🎂</span>
        <span class="fw-bold" style="color:#e65100;font-size:1.05rem;letter-spacing:.01em;">
          Today's Birthday Celebrant<?= count($celebrants) > 1 ? 's' : '' ?>
        </span>
        <span style="font-size:1.6rem;">🎉</span>
      </div>
      <p class="text-muted small mb-0">
        Join us in wishing our fellow alumna/alumnus a wonderful birthday!
      </p>
    </div>

    <!-- Cards -->
    <div class="row g-3 justify-content-center">
      <?php foreach ($celebrants as $c):
        $bAvatar = !empty($c['profile_picture'])
            ? BASE_URL . '/assets/uploads/avatars/' . htmlspecialchars($c['profile_picture'], ENT_QUOTES)
            : 'https://ui-avatars.com/api/?name=' . urlencode($c['first_name'] . '+' . $c['last_name']) . '&size=200&background=f9a825&color=fff&bold=true';
        $bName = htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES);
      ?>
      <div class="col-sm-6 col-md-4 col-lg-3">
        <div class="card border-0 shadow text-center h-100"
             style="border-radius:16px;overflow:hidden;background:#fff;">

          <!-- Festive top ribbon -->
          <div style="height:6px;background:linear-gradient(90deg,#f9a825,#e91e63,#f9a825);background-size:200% 100%;animation:bdayStripe 2s linear infinite;"></div>

          <div class="card-body px-3 py-4">
            <!-- Confetti ring around avatar -->
            <div class="position-relative d-inline-block mb-3">
              <div class="bday-ring">
                <img src="<?= $bAvatar ?>" alt="<?= $bName ?>"
                     style="width:88px;height:88px;border-radius:50%;object-fit:cover;object-position:center 5%;border:3px solid #fff;box-shadow:0 4px 14px rgba(0,0,0,.15);">
              </div>
              <span class="position-absolute bottom-0 end-0"
                    style="font-size:1.4rem;line-height:1;">🎈</span>
            </div>

            <h6 class="fw-bold mb-1" style="color:#1a1a2e;"><?= $bName ?></h6>

            <?php if (!empty($c['current_job_role'])): ?>
              <p class="mb-1 small" style="color:#666;">
                <i class="bi bi-briefcase-fill me-1" style="color:#f9a825;"></i>
                <?= htmlspecialchars($c['current_job_role'], ENT_QUOTES) ?>
              </p>
            <?php endif; ?>

            <?php if (!empty($c['department'])): ?>
              <p class="mb-2 small" style="color:#888;">
                <i class="bi bi-building me-1"></i>
                <?= htmlspecialchars($c['department'], ENT_QUOTES) ?>
              </p>
            <?php endif; ?>

            <div class="mt-3 pt-3 border-top">
              <p class="mb-0 fw-semibold" style="color:#e65100;font-size:.85rem;">
                🥳 Happy Birthday, <?= htmlspecialchars($c['first_name'], ENT_QUOTES) ?>!
              </p>
              <p class="mb-0 text-muted" style="font-size:.75rem;">
                Wishing you joy, good health &amp; many blessings!
              </p>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</section>

<style>
@keyframes bdayStripe {
  0%   { background-position: 0% 50%; }
  100% { background-position: 200% 50%; }
}
.bday-ring {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100px;
  height: 100px;
  border-radius: 50%;
  background: conic-gradient(#f9a825 0deg, #e91e63 90deg, #4caf50 180deg, #2196f3 270deg, #f9a825 360deg);
  padding: 4px;
}
</style>
<?php endif; ?>

<!-- ── Features ───────────────────────────────────────────────── -->
<section class="py-5">
  <div class="container">
    <div class="text-center mb-5">
      <h2 class="fw-bold">Why Join <?= htmlspecialchars($siteName, ENT_QUOTES) ?>?</h2>
      <p class="text-muted">Everything you need in one place.</p>
    </div>

    <div class="row g-4">
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="icon-wrap" style="background:#dbeafe">
            <i class="bi bi-people-fill" style="color:var(--primary)"></i>
          </div>
          <h5 class="fw-bold">Alumni Network</h5>
          <p class="text-muted small">Connect with thousands of alumni across all Nigerian states and around the world.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="icon-wrap" style="background:var(--accent-lt)">
            <i class="bi bi-credit-card-fill" style="color:var(--accent-dk)"></i>
          </div>
          <h5 class="fw-bold">Easy Payment</h5>
          <p class="text-muted small">Pay online via Paystack or upload a bank transfer receipt for offline verification.</p>
        </div>
      </div>
      <div class="col-md-4">
        <div class="feature-card h-100">
          <div class="icon-wrap" style="background:var(--success-lt)">
            <i class="bi bi-award-fill" style="color:var(--success)"></i>
          </div>
          <h5 class="fw-bold">Official Membership ID</h5>
          <p class="text-muted small">Receive your unique membership ID in the format <code>08/TUN/XXX/0001</code> upon activation.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ── Stats Counter ─────────────────────────────────────────── -->
<section class="py-5 stats-counter-section">
  <div class="container">
    <div class="text-center mb-4">
      <h2 class="fw-bold">Our Community in Numbers</h2>
      <p class="text-muted">Growing stronger every year.</p>
    </div>
    <div class="row g-4 justify-content-center">
      <?php
        $stats = [
          ['value' => statBadge($statTotalUsers),    'label' => 'Registered Members', 'icon' => 'bi-people-fill',      'color' => '#0f4c75', 'bg' => '#dbeafe'],
          ['value' => statBadge($statActiveMembers), 'label' => 'Active Members',      'icon' => 'bi-patch-check-fill', 'color' => '#16a34a', 'bg' => '#dcfce7'],
          ['value' => statBadge($statStates),        'label' => 'States Represented',  'icon' => 'bi-geo-alt-fill',     'color' => '#d97706', 'bg' => '#fef3c7'],
          ['value' => $statYears . '+',              'label' => 'Years of Excellence',  'icon' => 'bi-mortarboard-fill', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
        ];
        foreach ($stats as $s):
      ?>
      <div class="col-6 col-md-3">
        <div class="stats-card text-center h-100">
          <div class="stats-icon-wrap mx-auto mb-3" style="background:<?= $s['bg'] ?>">
            <i class="bi <?= $s['icon'] ?>" style="color:<?= $s['color'] ?>;font-size:1.6rem"></i>
          </div>
          <div class="stats-number" style="color:<?= $s['color'] ?>"><?= $s['value'] ?></div>
          <div class="stats-label"><?= $s['label'] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── How It Works ─────────────────────────────────────────── -->
<section class="py-5 bg-white">
  <div class="container">
    <div class="text-center mb-5">
      <h2 class="fw-bold">How It Works</h2>
    </div>
    <div class="row g-4 text-center">
      <?php
        $steps = [
          ['num'=>'1','icon'=>'bi-person-plus-fill','title'=>'Register','desc'=>'Create your account with your personal and academic details.'],
          ['num'=>'2','icon'=>'bi-credit-card-2-front-fill','title'=>'Pay Membership','desc'=>"Pay the annual fee ({$memberFee}) online or via bank transfer."],
          ['num'=>'3','icon'=>'bi-check-circle-fill','title'=>'Get Activated','desc'=>'Receive your unique membership ID immediately after payment verification.'],
          ['num'=>'4','icon'=>'bi-people-fill','title'=>'Stay Connected','desc'=>'Access your alumni community and renew membership every year.'],
        ];
        foreach ($steps as $step):
      ?>
      <div class="col-sm-6 col-lg-3">
        <div class="p-3">
          <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
               style="width:64px;height:64px;background:var(--primary);color:white;font-size:1.6rem;">
            <i class="bi <?= $step['icon'] ?>"></i>
          </div>
          <h6 class="fw-bold">Step <?= $step['num'] ?>: <?= $step['title'] ?></h6>
          <p class="text-muted small"><?= $step['desc'] ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ── Active Donation Campaigns ─────────────────────────────── -->
<?php if ($activeCampaigns): ?>
<section class="py-5" style="background:#f8f9fa">
  <div class="container">
    <div class="text-center mb-5">
      <h2 class="fw-bold"><i class="bi bi-heart-fill me-2 text-danger"></i>Support a Cause</h2>
      <p class="text-muted">Help fellow alumni and community projects through our fundraising campaigns.</p>
    </div>
    <div class="row g-4">
      <?php foreach ($activeCampaigns as $c):
        $target  = (float)$c['target_amount'];
        $raised  = (float)$c['raised'];
        $donors  = (int)$c['donors'];
        $pct     = ($target > 0) ? min(100, round($raised / $target * 100)) : null;

        // progress bar colour
        if ($pct === null)      $barCls = 'bg-primary';
        elseif ($pct >= 100)    $barCls = 'bg-success';
        elseif ($pct >= 50)     $barCls = 'bg-info';
        elseif ($pct >= 25)     $barCls = 'bg-warning';
        else                    $barCls = 'bg-danger';

        // beneficiary label
        if ($c['beneficiary_user_id'] && $c['benef_first']) {
            $benefLabel = htmlspecialchars($c['benef_first'] . ' ' . $c['benef_last'], ENT_QUOTES);
            $benefIcon  = 'bi-person-fill text-primary';
        } elseif ($c['beneficiary_name']) {
            $benefLabel = htmlspecialchars($c['beneficiary_name'], ENT_QUOTES);
            $benefIcon  = 'bi-folder-fill text-secondary';
        } else {
            $benefLabel = null;
            $benefIcon  = null;
        }

        // days left
        $daysLeft = null;
        if ($c['deadline']) {
            $diff = (new DateTime('today'))->diff(new DateTime($c['deadline']));
            if (!$diff->invert) $daysLeft = (int)$diff->days;
        }

        $donateUrl = BASE_URL . '/pages/donate.php?ref=' . $c['slug'];
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm border-0" style="border-radius:12px;overflow:hidden">

          <!-- Coloured top strip -->
          <div style="height:5px;background:linear-gradient(90deg,var(--primary),#1565c0)"></div>

          <div class="card-body d-flex flex-column p-4">

            <?php if ($benefLabel): ?>
              <div class="small text-muted mb-1">
                <i class="bi <?= $benefIcon ?> me-1"></i>For: <strong><?= $benefLabel ?></strong>
              </div>
            <?php endif; ?>

            <h6 class="fw-bold mb-2" style="line-height:1.3">
              <?= htmlspecialchars($c['title'], ENT_QUOTES) ?>
            </h6>

            <?php if ($c['description']): ?>
              <p class="text-muted small mb-3"
                 style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                <?= htmlspecialchars($c['description'], ENT_QUOTES) ?>
              </p>
            <?php endif; ?>

            <!-- Progress -->
            <div class="mt-auto">
              <?php if ($target > 0): ?>
                <div class="d-flex justify-content-between small mb-1">
                  <span class="fw-semibold text-primary"><?= formatCurrency($raised) ?></span>
                  <span class="text-muted">of <?= formatCurrency($target) ?></span>
                </div>
                <div class="progress mb-1" style="height:8px;border-radius:4px">
                  <div class="progress-bar <?= $barCls ?>" style="width:<?= $pct ?>%;border-radius:4px"></div>
                </div>
                <div class="d-flex justify-content-between" style="font-size:.75rem">
                  <span class="text-muted"><?= $pct ?>% funded</span>
                  <span class="text-muted"><?= number_format($donors) ?> donor<?= $donors !== 1 ? 's' : '' ?></span>
                </div>
              <?php else: ?>
                <div class="d-flex justify-content-between small">
                  <span class="fw-semibold text-primary"><?= formatCurrency($raised) ?> raised</span>
                  <span class="text-muted"><?= number_format($donors) ?> donor<?= $donors !== 1 ? 's' : '' ?></span>
                </div>
                <div class="progress mt-1 mb-1" style="height:8px;border-radius:4px">
                  <div class="progress-bar bg-primary progress-bar-striped" style="width:100%;border-radius:4px"></div>
                </div>
              <?php endif; ?>

              <?php if ($daysLeft !== null): ?>
                <div class="mt-1 small text-muted">
                  <i class="bi bi-clock me-1"></i><?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
            <a href="<?= htmlspecialchars($donateUrl, ENT_QUOTES) ?>"
               class="btn btn-primary w-100 fw-semibold">
              <i class="bi bi-heart me-2"></i>Donate Now
            </a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ── CTA ───────────────────────────────────────────────────── -->
<?php if (!isLoggedIn()): ?>
<section class="py-5" style="background:linear-gradient(135deg,var(--primary),var(--primary-lt))">
  <div class="container text-center text-white">
    <h2 class="fw-bold mb-3">Ready to Join?</h2>
    <p class="opacity-75 mb-4">Membership fee: <strong><?= $memberFee ?> / year</strong></p>
    <a href="<?= BASE_URL ?>/pages/register.php" class="btn btn-warning btn-lg px-5 fw-bold">
      <i class="bi bi-person-plus me-2"></i>Register Now
    </a>
  </div>
</section>
<?php endif; ?>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
