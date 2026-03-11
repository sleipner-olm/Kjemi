<?php
declare(strict_types=1);

/**
 * Simple HTML escaper
 */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Open main SQLite database (kjemikalier.db).
 *
 * IMPORTANT:
 * Change $dbFile so it matches the path used in your index.php.
 * Example: __DIR__ . '/data/kjemikalier.db'
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // TODO – adjust this path if your DB lives somewhere else
    $dbFile = __DIR__ . '/kjemikalier.db';
    $pdo = new PDO(
        'sqlite:' . $dbFile,
        null,
        null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("PRAGMA foreign_keys=ON;");
    return $pdo;
}

/**
 * Open SQLite database for additional chemistry info (kjemi-info.db).
 *
 * Adjust $dbFile, table name and column names further down if needed.
 */
function dbInfo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Adjust path if kjemi-info.db is in another folder
    $dbFile = __DIR__ . '/kjemi-info.db';

    $pdo = new PDO(
        'sqlite:' . $dbFile,
        null,
        null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

/**
 * Accepts:
 * - HEX (#RGB, #RRGGBB, RGB, RRGGBB)
 * - English CSS color names (red, blue, lightgray...)
 * - rgb()/rgba()/hsl()/hsla()
 */
function normalize_color(?string $c): ?string {
    $c = trim((string)$c);
    if ($c === '') {
        return null;
    }
    // HEX
    $hex = $c;
    if ($hex[0] !== '#') {
        $hex = '#' . $hex;
    }
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex)) {
        return strtoupper($hex);
    }
    // CSS color names
    if (preg_match('/^[a-zA-Z]+$/', $c)) {
        return strtolower($c);
    }
    // rgb/rgba/hsl/hsla – simple/defensive check
    $cLower = strtolower($c);
    if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9%,.\s]+\)$/', $cLower)) {
        return $cLower;
    }
    return null;
}

/**
 * Convert helse-string into small badges.
 */
function helse_badges(?string $helse): string {
    $helse = trim((string)$helse);
    if ($helse === '') {
        return '';
    }
    $map = [
        'etsende'      => ['Etsende', 'b-red'],
        'giftig'       => ['Giftig', 'b-red'],
        'brannfarlig'  => ['Brannfarlig', 'b-orange'],
        'miljø skade'  => ['Miljø skade', 'b-green'],
        'miljo skade'  => ['Miljø skade', 'b-green'],
        'irriterende'  => ['Irriterende', 'b-yellow'],
        'organskader'  => ['Organskader', 'b-purple'],
    ];
    $parts = array_filter(array_map('trim', explode(',', $helse)));
    $out   = [];
    $seen  = [];
    foreach ($parts as $p) {
        $key     = mb_strtolower($p);
        $matched = false;
        foreach ($map as $needle => $info) {
            if (strpos($key, $needle) !== false) {
                [$label, $class] = $info;
                $uniq = $label . '|' . $class;
                if (!isset($seen[$uniq])) {
                    $out[]          = '<span class="badge ' . h($class) . '">' . h($label) . '</span>';
                    $seen[$uniq] = true;
                }
                $matched = true;
            }
        }
        if (!$matched && $p !== '') {
            $uniq = $p . '|b-neutral';
            if (!isset($seen[$uniq])) {
                $out[]          = '<span class="badge b-neutral">' . h($p) . '</span>';
                $seen[$uniq] = true;
            }
        }
    }
    return implode('', $out);
}

// ---------------------------------------------------------------------
// Read id from query
// ---------------------------------------------------------------------
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    die('Ugyldig id.');
}

$errorMsg = null;
$saved    = (($_GET['saved'] ?? '') === '1');
$row      = [];
$info     = null;

try {
    $pdo = db();

    // Handle POST (save mengde)
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $mengde = trim((string)($_POST['mengde'] ?? ''));
                // max 4 characters
                if (mb_strlen($mengde) > 4) {
                    $mengde = mb_substr($mengde, 0, 4);
                }
            
                // Create timestamp in format dd.mm.yy hh:mm (24-hour)
                $now = new DateTime('now', new DateTimeZone('Europe/Oslo'));
                $oppdatert = $now->format('d.m.y H:i');  // e.g. 07.03.26 14:35
            
                $stmt = $pdo->prepare(
                    "UPDATE kjemikalier
                     SET mengde = :mengde,
                         oppdatert = :oppdatert
                     WHERE id = :id"
                );
                $stmt->execute([
                    ':mengde'    => $mengde,
                    ':oppdatert' => $oppdatert,
                    ':id'        => $id,
                ]);
            
                // PRG – avoid resubmit on reload
                header('Location: edit.php?id=' . $id . '&saved=1');
                exit;
            }

    // Load selected chemical
    $stmt = $pdo->prepare(
        "SELECT id, item, description, tanker, mengde, helse, farge1, farge2, equchem
         FROM kjemikalier
         WHERE id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        die('Fant ikke kjemikalie.');
    }


        // -----------------------------------------------------
        // Extra info from kjemi-info.db, matched by ITEM
        // -----------------------------------------------------
        try {
            $pdoInfo = dbInfo();
        
            $stmtInfo = $pdoInfo->prepare(
                "SELECT
                     hva_det_er,
                     typisk_bruk,
                     b5,
                     b7
                 FROM kjemi_info
                 WHERE kjemikalie = :kjemikalie
                 LIMIT 1"
            );
        
            $stmtInfo->execute([
                ':kjemikalie' => $row['item'] ?? '',
            ]);
        
            $info = $stmtInfo->fetch() ?: null;
        
        } catch (Throwable $eInfo) {
            // Do not break edit page if info DB is missing or query fails
            $info = null;
        }


} catch (Throwable $e) {
    $errorMsg = $e->getMessage();
    $row      = [
        'id'          => $id,
        'item'        => '',
        'description' => '',
        'tanker'      => '',
        'mengde'      => '',
        'helse'       => '',
        'farge1'      => '',
        'farge2'      => '',
        'equchem'     => '',
    ];
}

// Prepare color and Equ!Chem values
$f1 = normalize_color($row['farge1'] ?? null);
$f2 = normalize_color($row['farge2'] ?? null);
$equchem = trim((string)($row['equchem'] ?? ''));
if ($equchem === '') {
    $equchem = null;
}
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <title>Sleipner A/R – Rediger kjemikalie</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Shared theme -->
    <link rel="stylesheet" href="../data/style.css">
    <!-- Page-specific styles (including popup calculator) -->
    <style>
        .page-main {
            flex: 1 1 auto;
            display: flex;
            justify-content: center;
            padding: 18px 10px 26px;
            overflow-y: auto;
        }
        .hidden {
            display: none !important;
        }
        /* Back + Equ!Chem bar (NAS-like) */
        .detail-topbar {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            background: var(--panel);
            border-bottom: 1px solid rgba(148, 163, 184, 0.3);
            box-shadow:
                0 14px 36px rgba(15, 23, 42, 0.9),
                0 0 0 1px rgba(15, 23, 42, 1);
        }
        .btn {
            padding: 9px 14px;
            min-height: 40px;
            border-radius: 999px;
            border: 1px solid var(--border-soft);
            background: var(--panel-soft);
            color: var(--text);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
            outline: none;
            transition:
                background 0.15s ease,
                transform 0.08s ease,
                box-shadow 0.12s ease,
                border 0.12s ease;
        }
        .btn:hover {
            background: #111827;
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.8);
        }
        .btn:active {
            transform: translateY(0);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.9);
        }
        .btn-back {
            border-color: rgba(148, 163, 184, 0.45);
        }
        .btn-equchem {
            border-color: rgba(56, 189, 248, 0.9);
            background: #020617;
            color: #38bdf8;
        }
        .btn-equchem:hover {
            background: #111827;
        }
        .btn-save {
            border-color: rgba(34, 197, 94, 0.9);
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #020617;
        }
        .btn-save:hover {
            background: linear-gradient(135deg, #16a34a, #15803d);
        }
        .btn-calc {
            border-color: rgba(148, 163, 184, 0.6);
        }
        .card-header {
            margin-bottom: 8px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            padding-bottom: 4px;
        }
        .card-title {
            margin: 0;
            font-size: 18px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .card-sub {
            margin: 4px 0 0 0;
            font-size: 12px;
            color: var(--muted);
        }
        .field-row {
            margin-top: 10px;
            margin-bottom: 6px;
        }
        .field-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.11em;
            color: var(--muted);
            margin-bottom: 3px;
        }
        .field-value {
            font-size: 14px;
        }
        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid rgba(148, 163, 184, 0.4);
        }
        .b-red { background: rgba(220, 38, 38, 0.08); color: #fecaca; border-color: rgba(248, 113, 113, 0.9); }
        .b-orange { background: rgba(249, 115, 22, 0.10); color: #fed7aa; border-color: rgba(251, 146, 60, 0.9); }
        .b-yellow { background: rgba(234, 179, 8, 0.06); color: #fef3c7; border-color: rgba(250, 204, 21, 0.85); }
        .b-green { background: rgba(34, 197, 94, 0.10); color: #bbf7d0; border-color: rgba(34, 197, 94, 0.9); }
        .b-purple { background: rgba(168, 85, 247, 0.12); color: #e9d5ff; border-color: rgba(168, 85, 247, 0.9); }
        .b-neutral{ background: rgba(148, 163, 184, 0.12); color: #e5e7eb; }
        .color-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 13px;
        }
        .color-chip {
            width: 36px;
            height: 18px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.9);
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.8);
        }
        /* Mengde row: input + Kalkulator left, Lagre right */
        .mengde-block {
            background: rgba(255, 255, 255, 0.11); /* litt lysere tone */
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .mengde-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: space-between;
            margin-top: 6px;
        }
        .mengde-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mengde-right {
            margin-left: auto;
        }
        .mengde-input {
            width: 120px;
            padding: 6px 9px;
            border-radius: 999px;
            border: 1px solid var(--border-soft);
            background: var(--panel);
            color: var(--text);
            font-size: 22px;
            outline: line;
        }
        .mengde-input:focus {
            border-color: var(--accent-strong);
            box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.75);
        }
        .status-ok {
            margin-top: 8px;
            font-size: 13px;
            color: #bbf7d0;
        }
        .status-error {
            margin-top: 8px;
            font-size: 13px;
            color: #fecaca;
        }
        /* Popup calculator (modal) */
        .calc-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.90);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 999;
        }
        .calc-modal {
            width: min(420px, 100% - 24px);
            background:
                radial-gradient(circle at top, rgba(56, 189, 248, 0.25), transparent 60%)
                #020617;
            border-radius: 18px;
            padding: 14px 14px 16px;
            box-shadow:
                0 22px 60px rgba(0, 0, 0, 0.9),
                0 0 0 1px rgba(15, 23, 42, 1);
            border: 1px solid rgba(51, 65, 85, 0.95);
        }
        .calc-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .calc-title {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent-strong);
        }
        .calc-close {
            width: 26px;
            height: 26px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.8);
            background: #020617;
            color: #e5e7eb;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .calc-display {
            width: 100%;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 1);
            background: #020617;
            color: #e5e7eb;
            font-size: 18px;
            text-align: right;
            box-shadow:
                inset 0 0 0 1px rgba(15, 23, 42, 1),
                0 6px 20px rgba(0, 0, 0, 0.8);
        }
        .calc-grid {
            margin-top: 10px;
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 8px;
        }
        .calc-btn {
            padding: 9px 0;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 1);
            background: #020617;
            color: #e5e7eb;
            font-size: 16px;
            cursor: pointer;
            box-shadow:
                0 8px 20px rgba(15, 23, 42, 0.9),
                0 0 0 1px rgba(15, 23, 42, 1);
        }
        .calc-btn:hover {
            background: #0b1120;
        }
        .calc-btn-op {
            background: #0b1120;
            border-color: rgba(37, 99, 235, 0.8);
        }
        .calc-btn-del {
            background: #451a03;
            border-color: rgba(249, 115, 22, 0.9);
            color: #fed7aa;
        }
        .calc-btn-c {
            background: #3f1f2b;
            border-color: rgba(248, 113, 113, 0.9);
            color: #fecaca;
        }
        .calc-btn-eq {
            background: #064e3b;
            border-color: rgba(34, 197, 94, 0.9);
            color: #bbf7d0;
        }
        .calc-footer {
            margin-top: 12px;
            display: flex;
            justify-content: flex-start;
        }
        .btn-calc-use {
            border-color: rgba(34, 197, 94, 0.9);
            background: #065f46;
            color: #ecfdf5;
            padding-inline: 18px;
        }
        .btn-calc-use:hover {
            background: #047857;
        }
        @media (max-width: 480px) {
            .detail-topbar {
                padding-inline: 10px;
                gap: 6px;
            }
            .btn {
                font-size: 12px;
                padding: 7px 10px;
                min-height: 36px;
            }
        }
    </style>
</head>
<body>
    <!-- TOP BAR – shared look with the rest of the app -->
    <div class="topbar">
        <div class="topbar-left">
            <div class="topbar-logo-wrap">
                <img
                    src="https://sleipner.datalager.net/Sleipner_logo.jpg"
                    alt="Sleipner logo"
                    class="topbar-logo"
                >
            </div>
            <div class="topbar-text">
                <div class="topbar-title">SLEIPNER A/R</div>
                <div class="topbar-subtitle">Kjemikalieoversikt</div>
                <div class="topbar-sub">Status: Detaljside</div>
            </div>
        </div>
        <div class="topbar-right">
            <div class="help-icon">
                <a href="brukerveiledning.html" style="color:inherit;text-decoration:none;">?</a>
            </div>
            <div class="topbar-version">v1.1 — 05.03.2026</div>
        </div>
    </div>

    <!-- Back/Equ!Chem bar (no ID text) -->
    <div class="detail-topbar">
        <a href="index.php" class="btn btn-back">⬅ Tilbake til listen</a>
        <div style="flex:1"></div>
        <?php if ($equchem): ?>
            <a href="<?php echo h($equchem); ?>" class="btn btn-equchem" target="_blank" rel="noreferrer">
                🔗 Equ!Chem
            </a>
        <?php endif; ?>
    </div>

    <main class="page-main">
        <div class="shared-container">
            <header class="card-header">
                <h1 class="card-title">Rediger kjemikalie</h1>
                <p class="card-sub">
                    Her kan du oppdatere <strong>mengde</strong> for valgt kjemikalie.
                    Andre felt vedlikeholdes i databasen.
                </p>
            </header>

            <?php if ($saved): ?>
                <div class="status-ok">✅ Mengde lagret.</div>
            <?php endif; ?>

            <?php if ($errorMsg): ?>
                <div class="status-error">
                    ⚠️ Feil under lesing/lagring: <?php echo h($errorMsg); ?>
                </div>
            <?php endif; ?>

            <!-- Read-only fields -->
            <div class="field-row">
                <div class="field-label">Item</div>
                <div class="field-value">
                    <?php echo h($row['item'] ?? ''); ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field-label">Beskrivelse</div>
                <div class="field-value">
                    <?php echo nl2br(h($row['description'] ?? '')); ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field-label">Tanker</div>
                <div class="field-value">
                    <?php echo nl2br(h($row['tanker'] ?? '')); ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field-label">Helse</div>
                <div class="field-value badges">
                    <?php echo helse_badges($row['helse'] ?? ''); ?>
                </div>
            </div>

            <div class="field-row">
                <div class="field-label">Kobling (farger)</div>
                <div class="field-value color-row">
                    <span>Farge 1:</span>
                    <?php if ($f1): ?>
                        <span class="color-chip" style="background: <?php echo h($f1); ?>;"></span>
                        <span><?php echo h($f1); ?></span>
                    <?php else: ?>
                        <span>(ingen)</span>
                    <?php endif; ?>
                    <span style="margin-left:16px;">Farge 2:</span>
                    <?php if ($f2): ?>
                        <span class="color-chip" style="background: <?php echo h($f2); ?>;"></span>
                        <span><?php echo h($f2); ?></span>
                    <?php else: ?>
                        <span>(ingen)</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form: only 'mengde' is editable -->
            <form method="post" autocomplete="off" class="field-row">
                
                <div class="mengde-block">
                    <div class="field-label">Mengde</div>
                        <div class="mengde-row">
                            <div class="mengde-left">
                                <input
                                    type="text"
                                    name="mengde"
                                    class="mengde-input"
                                    maxlength="4"
                                    value="<?php echo h((string)($row['mengde'] ?? '')); ?>"
                                    inputmode="decimal"
                                    pattern="[0-9.,+\- ]*"
                                >
                                <button type="button" class="btn btn-calc" id="calcOpen">
                                    🧮 Kalkulator
                                </button>
                            </div>
                                <div class="mengde-right">
                                    <button type="submit" class="btn btn-save">💾 Lagre</button>
                                </div>
                        </div>
                    
                    <p class="card-sub" style="margin-top:8px;">
                        Tips: bruk kalkulatoren for raske summer (kun + og -). Kun mengdefeltet blir lagret.
                    </p>
                </div>
            </form>

            <!-- Informasjonsseksjon fra kjemi-info.db -->
            

            
            <?php if ($info): ?>
                <section class="field-row" style="margin-top:18px;">
                    <div class="field-label">Informasjon</div>
                    <div class="card-sub" style="margin-bottom:8px;">
                        Tilleggsinformasjon fra kjemi-info: hva stoffet er, typisk bruk og B‑5 / B‑7.
                    </div>

                    <div style="
                        border:1px solid rgba(148,163,184,0.3);
                        border-radius:12px;
                        padding:12px 14px;
                        background:var(--panel-soft);
                    ">
                        
                        <div class="field-row">
                            <div class="field-label">Hva det er</div>
                            <div class="field-value">
                                <?php echo nl2br(h($info['hva_det_er'] ?? '')); ?>
                            </div>
                        </div>
                        
                        <div class="field-row" style="margin-top:12px;">
                            <div class="field-label">Typisk bruk / hvor i systemet</div>
                            <div class="field-value">
                                <?php echo nl2br(h($info['typisk_bruk'] ?? '')); ?>
                            </div>
                        </div>
                        
                        <div class="field-row" style="margin-top:12px;">
                            <div class="field-label">B-5</div>
                            <div class="field-value">
                                <?php echo nl2br(h($info['b5'] ?? '')); ?>
                            </div>
                        </div>
                        
                        <div class="field-row" style="margin-top:12px;">
                            <div class="field-label">B-7</div>
                            <div class="field-value">
                                <?php echo nl2br(h($info['b7'] ?? '')); ?>
                            </div>
                        </div>

                    </div>
                </section>
            <?php endif; ?>

        </div>
    </main>

    <footer class="footer">
        Sleipner A/R Kjemikalier – Intern bruk<br>
        (c) Olav Andre Martinussen
    </footer>

    <!-- Popup Mengde-kalkulator -->
    <div id="calcModal" class="calc-backdrop hidden">
        <div class="calc-modal">
            <div class="calc-header">
                <div class="calc-title">MENGDE-KALKULATOR</div>
                <button type="button" class="calc-close" id="calcClose">×</button>
            </div>
            <input
                id="calcDisplay"
                class="calc-display"
                type="text"
                readonly
            >
            <div class="calc-grid">
                <!-- Row 1 -->
                <button type="button" class="calc-btn" data-calc="7">7</button>
                <button type="button" class="calc-btn" data-calc="8">8</button>
                <button type="button" class="calc-btn" data-calc="9">9</button>
                <button type="button" class="calc-btn calc-btn-del" data-calc="del">DEL</button>
                <!-- Row 2 -->
                <button type="button" class="calc-btn" data-calc="4">4</button>
                <button type="button" class="calc-btn" data-calc="5">5</button>
                <button type="button" class="calc-btn" data-calc="6">6</button>
                <button type="button" class="calc-btn calc-btn-op" data-calc="+">+</button>
                <!-- Row 3 -->
                <button type="button" class="calc-btn" data-calc="1">1</button>
                <button type="button" class="calc-btn" data-calc="2">2</button>
                <button type="button" class="calc-btn" data-calc="3">3</button>
                <button type="button" class="calc-btn calc-btn-op" data-calc="-">-</button>
                <!-- Row 4 -->
                <button type="button" class="calc-btn" data-calc="0">0</button>
                <button type="button" class="calc-btn" data-calc=".">.</button>
                <button type="button" class="calc-btn calc-btn-c" data-calc="clear">C</button>
                <button type="button" class="calc-btn calc-btn-eq" data-calc="eq">=</button>
            </div>
            <div class="calc-footer">
                <button type="button" class="btn btn-calc-use" id="calcUse">
                    ➡ Bruk resultat
                </button>
            </div>
        </div>
    </div>

    <!-- Calculator JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modal       = document.getElementById('calcModal');
            var openBtn     = document.getElementById('calcOpen');
            var closeBtn    = document.getElementById('calcClose');
            var display     = document.getElementById('calcDisplay');
            var useBtn      = document.getElementById('calcUse');
            var mengdeInput = document.querySelector('input[name="mengde"]');

            if (!modal || !openBtn || !closeBtn || !display || !useBtn || !mengdeInput) {
                return;
            }

            function openModal() {
                // start with current mengde (or 0)
                var v = (mengdeInput.value || '').trim();
                if (!v) v = '0';
                display.value = v;
                modal.classList.remove('hidden');
            }

            function closeModal() {
                modal.classList.add('hidden');
            }

            openBtn.addEventListener('click', function () {
                openModal();
            });
            closeBtn.addEventListener('click', function () {
                closeModal();
            });

            // Optional: click outside closes modal
            modal.addEventListener('click', function (e) {
                if (e.target === modal) {
                    closeModal();
                }
            });

            // Calculator buttons
            document.querySelectorAll('[data-calc]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var v       = btn.getAttribute('data-calc');
                    var current = display.value || '';

                    if (v === 'del') {
                        display.value = current.slice(0, -1);
                        return;
                    }
                    if (v === 'clear') {
                        display.value = '';
                        return;
                    }
                    if (v === 'eq') {
                        var expr = current.replace(/,/g, '.').trim();
                        if (!expr) return;
                        // Only allow digits, +, -, dot, and spaces
                        if (!/^[0-9+\-.\s]+$/.test(expr)) {
                            return;
                        }
                        try {
                            var result = Function('"use strict";return (' + expr + ')')();
                            if (typeof result === 'number' && isFinite(result)) {
                                var out = String(result);
                                if (out.length > 4) {
                                    out = out.slice(0, 4);
                                }
                                display.value = out;
                            }
                        } catch (e) {
                            // ignore invalid expression
                        }
                        return;
                    }
                    // Default: append digit, dot, or operator
                    display.value = current + v;
                });
            });

            useBtn.addEventListener('click', function () {
                var v = (display.value || '').trim();
                if (v.length > 4) {
                    v = v.slice(0, 4);
                }
                mengdeInput.value = v;
                closeModal();
                mengdeInput.focus();
            });
        });
    </script>
</body>
</html>

