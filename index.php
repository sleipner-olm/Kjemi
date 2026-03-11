<?php
declare(strict_types=1);

function h(?string $s): string {
  return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO {
  $dbFile = __DIR__ . '/kjemikalier.db';
  $pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA foreign_keys=ON;");
  return $pdo;
}

$rows = [];
$errorMsg = null;
$lastUpdated = null;
$isOld = false;   // flag for “older than 8 days”

/* Handle "Null ALT" before reading rows */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'null_alt') {
    $pdo = db();

    // 1) Ensure log table exists
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS null_alt_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ts          TEXT    NOT NULL,
        remote_addr TEXT,
        user_agent  TEXT
      )
    ");

    // 2) Ensure snapshot table exists
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS null_alt_snapshot (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        log_id        INTEGER NOT NULL,
        kjemikalie_id INTEGER NOT NULL,
        old_mengde    TEXT,
        FOREIGN KEY (log_id) REFERENCES null_alt_log(id)
      )
    ");

    // Use transaction so log + snapshot + update are consistent
    $pdo->beginTransaction();

    // 3) Insert log row
    $stmtLog = $pdo->prepare("
      INSERT INTO null_alt_log (ts, remote_addr, user_agent)
      VALUES (:ts, :remote_addr, :user_agent)
    ");
    $stmtLog->execute([
      ':ts'          => (new DateTime('now', new DateTimeZone('Europe/Oslo')))->format('Y-m-d H:i:s'),
      ':remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
      ':user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);

    $logId = (int)$pdo->lastInsertId();

    // 4) Snapshot all current quantities
    $stmtSnap = $pdo->prepare("
      INSERT INTO null_alt_snapshot (log_id, kjemikalie_id, old_mengde)
      SELECT :log_id, id, mengde
      FROM kjemikalier
    ");
    $stmtSnap->execute([':log_id' => $logId]);

    // 5) Zero all quantities
    $pdo->exec("UPDATE kjemikalier SET mengde = 0");

    $pdo->commit();

    // 6) Redirect to avoid form re-post on refresh
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
  }

  $pdo = db();

  /* --- NEW: get latest oppdatert from kjemikalier and check age --- */
  try {
    $stmtLast = $pdo->query("
      SELECT MAX(oppdatert) AS last_oppdatert
      FROM kjemikalier
    ");
    $rowLast = $stmtLast->fetch();
    if (!empty($rowLast['last_oppdatert'])) {
      $lastUpdated = $rowLast['last_oppdatert'];   // e.g. 07.03.26 14:35 (d.m.y H:i)

      // Parse and compare with now - 8 days
      $tz   = new DateTimeZone('Europe/Oslo');
      $now  = new DateTime('now', $tz);
      $limit = (clone $now)->modify('-8 days');

      $dt = DateTime::createFromFormat('d.m.y H:i', $lastUpdated, $tz);
      if ($dt instanceof DateTime && $dt < $limit) {
        $isOld = true;   // older than 8 days
      }
    }
  } catch (Throwable $eLast) {
    $lastUpdated = null;
    $isOld = false;
  }

  // Sort by visning, then item
  $stmt = $pdo->query("
    SELECT id, visning, item, description, tanker, mengde, helse
    FROM kjemikalier
    ORDER BY
      CASE
        WHEN visning IS NULL OR TRIM(visning) = '' THEN 1
        WHEN CAST(visning AS INTEGER) = 0 AND TRIM(visning) NOT IN ('0','00','000') THEN 1
        ELSE 0
      END,
      CAST(visning AS INTEGER) ASC,
      item COLLATE NOCASE ASC
  ");
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $errorMsg = $e->getMessage();
}
?>
<!doctype html>
<html lang="no">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
  <title>Sleipner A/R – Kjemikalier</title>

  <!-- Samme CSS som NAS-ventiler -->
  <link rel="stylesheet" href="https://sleipner.datalager.net/data/style.css">

  <style>
    html, body { height:100%; }
    body { overflow:hidden; }

    .app-main{
      flex: 1 1 auto;
      display:flex;
      flex-direction:column;
      min-height:0;
      overflow:hidden;
    }

    .page{
      flex: 1 1 auto;
      min-height:0;
      overflow-y:auto;
      padding: 12px;
      background:
        radial-gradient(circle at top, rgba(56,189,248,0.10), transparent 55%),
        linear-gradient(180deg, rgba(2,6,23,0.92) 0%, rgba(2,6,23,0.86) 55%, rgba(2,6,23,0.92) 100%);
    }

    .topbar-logo{
      width: 64px !important;
      height: 64px !important;
      object-fit: contain !important;
    }

    .wrap{
      max-width: 1200px;
      margin: 0 auto;
    }

    .pill{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding: 10px 12px;
      margin: 0 0 12px 0;
      border-radius: 14px;
      background: rgba(15,23,42,0.70);
      border: 1px solid rgba(148,163,184,0.16);
      color: rgba(226,232,240,0.92);
      box-shadow: 0 14px 34px rgba(0,0,0,0.45);
      font-size: 13px;
    }
    .pill strong{ color: var(--accent-strong, #38bdf8); }

    /* NEW: structure pill into left / center / right */
    .pill-left,
    .pill-center,
    .pill-right{
      display:flex;
      align-items:center;
    }

    .pill-left{
      flex: 1;
    }

    .pill-center{
      flex: 0 0 auto;
      justify-content: center;
      text-align: center;
      font-size: 12px;
    }

    .pill-right{
      flex: 1;
      justify-content: flex-end;
      text-align: right;
    }

    /* NEW: color variants for last-updated status */
    .pill-center-ok{
      color: rgba(226,232,240,0.72);   /* normal muted */
    }

    .pill-center-old{
      color: #fecaca;                  /* light red text */
    }

    .no-data{
      padding: 34px 14px;
      text-align:center;
      color: rgba(226,232,240,0.70);
      font-style: italic;
      background: rgba(15,23,42,0.55);
      border: 1px solid rgba(148,163,184,0.16);
      border-radius: 16px;
    }

    .table-wrap{
      overflow:auto;
      -webkit-overflow-scrolling: touch;
      border-radius: 16px;
      border: 1px solid rgba(148,163,184,0.16);
      box-shadow: 0 18px 46px rgba(0,0,0,0.60);
      background: rgba(15,23,42,0.70);
    }

    table{
      width: 100%;
      min-width: 980px;
      border-collapse: collapse;
    }

    thead th{
      position: sticky;
      top: 0;
      z-index: 2;
      background: rgba(2,6,23,0.92);
      color: var(--accent-strong, #38bdf8);
      text-align:left;
      font-size: 11px;
      letter-spacing: .10em;
      text-transform: uppercase;
      padding: 12px 10px;
      border-bottom: 1px solid rgba(148,163,184,0.16);
      white-space: nowrap;
    }

    tbody td{
      padding: 10px 10px;
      border-bottom: 1px solid rgba(148,163,184,0.12);
      color: rgba(226,232,240,0.95);
      font-size: 13px;
      vertical-align: top;
    }

    tbody tr:hover td{ background: rgba(56,189,248,0.06); }

    .rowlink{
      color: var(--accent-strong, #38bdf8);
      text-decoration:none;
      font-weight: 900;
    }

    .muted{
      color: rgba(226,232,240,0.72);
    }

    .col-visning{ width: 35px; text-align: center; }
    .col-item{ width: 220px; }
    .col-mengde{ width: 110px; white-space: nowrap; }
    .col-edit{ width: 120px; white-space: nowrap; }

    .wraptext{
      overflow-wrap:anywhere;
      word-break:break-word;
      line-height: 1.35;
    }

    .null-alt-btn{
      position: fixed;
      right: 16px;
      bottom: 16px;
      background: #b91c1c;
      color: #fff;
      border: none;
      padding: 10px 18px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 13px;
      cursor: pointer;
      box-shadow: 0 12px 30px rgba(0,0,0,0.65);
      z-index: 50;
    }
    .null-alt-btn:hover{
      background: #dc2626;
    }

    .modal-overlay{
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.86);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 100;
    }
    .modal-overlay[hidden]{
      display: none;
    }

    .modal-box{
      background: #020617;
      border-radius: 18px;
      border: 1px solid rgba(248,113,113,0.70);
      padding: 20px 22px 18px;
      max-width: 440px;
      width: 100%;
      color: #fee2e2;
      box-shadow: 0 24px 60px rgba(0,0,0,0.90);
      font-size: 14px;
    }

    .modal-title{
      font-size: 18px;
      font-weight: 800;
      color: #fca5a5;
      margin: 0 0 6px 0;
    }

    .modal-text{
      margin: 4px 0;
    }

    .modal-buttons{
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      margin-top: 14px;
    }

    .btn-danger,
    .btn-success{
      border: none;
      border-radius: 999px;
      padding: 8px 16px;
      font-weight: 800;
      font-size: 13px;
      cursor: pointer;
      box-shadow: 0 8px 22px rgba(0,0,0,0.65);
    }

    .btn-danger{
      background: #b91c1c;
      color: #fff;
    }
    .btn-danger:hover{
      background: #dc2626;
    }

    .btn-success{
      background: #16a34a;
      color: #f0fdf4;
    }
    .btn-success:hover{
      background: #22c55e;
    }
  </style>
</head>

<body>
  <div class="topbar">
    <div class="topbar-left">
      <div class="topbar-logo-wrap">
        <img src="https://sleipner.datalager.net/Sleipner_logo.jpg" alt="Sleipner logo" class="topbar-logo">
      </div>
      <div class="topbar-text">
        <div class="topbar-title">SLEIPNER A/R</div>
        <div class="topbar-sub">Kjemikalieoversikt</div>
        <div class="topbar-sub">
          Status: <span id="statusText"><?= $errorMsg ? 'FEIL' : 'Klar' ?></span>
        </div>
      </div>
    </div>
    <div class="topbar-right">
      <a href="brukerveiledning.html" class="help-icon">?</a>
      <div class="topbar-version">v1.1 — 05.03.2026</div>
    </div>
  </div>

  <div class="app-main">
    <div class="page">
      <div class="wrap">

        <!-- Updated pill with center "Siste oppdaterte verdi" and color change -->
        <div class="pill">
          <div class="pill-left">
             📦  
            <?php if (!empty($lastUpdated)): ?>
              Siste oppdaterte mengde:  <strong><?= h($lastUpdated) ?></strong>
              <?php if ($isOld): ?>
                &nbsp;⚠️
              <?php endif; ?>
            <?php else: ?>
              Siste oppdaterte mengde:  <em>ingen registrert</em>
            <?php endif; ?>
          </div>

          <div class="pill-right muted">
            Sortert etter <strong>Visning</strong>
          </div>
        </div>

        <?php if ($errorMsg): ?>
          <div class="no-data">⚠️ Kunne ikke lese database: <?= h($errorMsg) ?></div>
        <?php elseif (empty($rows)): ?>
          <div class="no-data">Ingen data.</div>
        <?php else: ?>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th class="col-visning">#</th>
                  <th class="col-item">Item</th>
                  <th class="col-mengde">Mengde</th>
                  <th>Beskrivelse</th>
                  <th>Tanker</th>
                  <th>Helse</th>
                  <th class="col-edit"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php $id = (int)($r['id'] ?? 0); ?>
                  <tr>
                    <td class="wraptext"><?= h((string)($r['visning'] ?? '')) ?></td>
                    <td class="wraptext">
                      <a class="rowlink" href="edit.php?id=<?= $id ?>"><?= h((string)($r['item'] ?? '')) ?></a>
                    </td>
                    <td class="col-mengde"><?= h((string)($r['mengde'] ?? '')) ?></td>
                    <td class="wraptext"><?= h((string)($r['description'] ?? '')) ?></td>
                    <td class="wraptext"><?= h((string)($r['tanker'] ?? '')) ?></td>
                    <td class="wraptext"><?= h((string)($r['helse'] ?? '')) ?></td>
                    <td class="col-edit">
                      <a class="rowlink" href="edit.php?id=<?= $id ?>">Rediger</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Null ALT button bottom right -->
  <?php if (!$errorMsg && !empty($rows)): ?>
    <button type="button" class="null-alt-btn" id="nullAltBtn">Null ALT</button>
  <?php endif; ?>

  <!-- Modal / popup -->
  <div class="modal-overlay" id="nullAltModal" hidden>
    <div class="modal-box">
      <div class="modal-title">INFO!</div>
      <div class="modal-text">
        Denne er laget for å nullstille ALLE mengder før ny opptelling startes.
      </div>
      <div class="modal-text">
        Er du sikker?
      </div>
      <div class="modal-buttons">
        <form method="post">
          <input type="hidden" name="action" value="null_alt">
          <button type="submit" class="btn-danger">JA!</button>
        </form>
        <button type="button" class="btn-success" id="nullAltCancel">Nei!</button>
      </div>
    </div>
  </div>

  <footer class="footer">
    Sleipner A/R Kjemikalier – Intern bruk<br>
    (c) Olav Andre Martinussen
  </footer>

  <script>
    (function () {
      const btn = document.getElementById('nullAltBtn');
      const modal = document.getElementById('nullAltModal');
      const cancel = document.getElementById('nullAltCancel');

      if (!btn || !modal || !cancel) return;

      btn.addEventListener('click', function () {
        modal.hidden = false;
      });

      cancel.addEventListener('click', function () {
        modal.hidden = true;
      });
    })();
  </script>

  <script>
    // Auto-refresh hvert 30. sekund (30000 ms)
    setTimeout(function() {
        window.location.reload();
    }, 30000);
  </script>

</body>
</html>