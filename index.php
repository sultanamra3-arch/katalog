<?php
/**
 * Katalog Buku - Perpustakaan SMPN 6 Keritang
 * Mencari buku dan melihat ketersediaannya. Hanya MEMBACA database SLiMS.
 * PHP 7.4+ dengan PDO MySQL.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Jakarta');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$CFG = require __DIR__ . '/config.php';
$SLIMS = rtrim($CFG['folder_slims'], '/');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- Database ---------------- */
function db()
{
    static $pdo;
    global $CFG, $SLIMS;
    if ($pdo) return $pdo;
    if (!empty($CFG['db_nama'])) {
        $c = [$CFG['db_host'] ?: 'localhost', $CFG['db_port'] ?: '3306', $CFG['db_nama'], $CFG['db_user'], $CFG['db_pass']];
    } elseif (is_file("$SLIMS/config/database.php")) {
        $d = include "$SLIMS/config/database.php";
        $n = $d['nodes'][$d['default_profile'] ?? 'SLiMS'] ?? reset($d['nodes']);
        $c = [$n['host'], $n['port'] ?: '3306', $n['database'], $n['username'], $n['password']];
    } elseif (is_file("$SLIMS/sysconfig.local.inc.php")) {
        $src = file_get_contents("$SLIMS/sysconfig.local.inc.php");
        $v = [];
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $k)
            if (preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*[\'"](.*?)[\'"]/', $src, $m)) $v[$k] = $m[1];
        $c = [$v['DB_HOST'] ?? 'localhost', $v['DB_PORT'] ?? '3306', $v['DB_NAME'] ?? '', $v['DB_USERNAME'] ?? '', $v['DB_PASSWORD'] ?? ''];
    } else {
        throw new RuntimeException('Folder SLiMS tidak ditemukan.');
    }
    $pdo = new PDO("mysql:host={$c[0]};port={$c[1]};dbname={$c[2]};charset=utf8mb4", $c[3], $c[4], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}
function q($sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s; }

function lib_name()
{
    try {
        $v = db()->query("SELECT setting_value FROM setting WHERE setting_name = 'library_name'")->fetchColumn();
        $n = $v ? @unserialize(stripslashes($v)) : '';
        if (is_string($n) && $n !== '') return $n;
    } catch (Throwable $e) {}
    return 'Perpustakaan';
}

/* ---------------- Gambar: logo & sampul dari folder SLiMS ---------------- */
function send_image($path)
{
    if (!is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? null;
    if (!$mime) { http_response_code(404); exit; }
    header("Content-Type: $mime");
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}
if (isset($_GET['logo'])) {
    $logo = 'logo.png';
    try {
        $v = db()->query("SELECT setting_value FROM setting WHERE setting_name = 'membercard_print_settings'")->fetchColumn();
        $a = $v ? @unserialize(stripslashes($v)) : null;
        if (is_array($a) && !empty($a['logo'])) $logo = basename($a['logo']);
    } catch (Throwable $e) {}
    send_image("$SLIMS/files/membercard/classic/images/$logo");
}
if (isset($_GET['sampul'])) {
    $img = q('SELECT image FROM biblio WHERE biblio_id = ? AND opac_hide = 0', [(int)$_GET['sampul']])->fetchColumn();
    if (!$img) { http_response_code(404); exit; }
    send_image("$SLIMS/images/docs/" . basename($img));
}

/* ---------------- Bantuan ---------------- */
function tgl($d)
{
    if (!$d || $d === '0000-00-00') return '-';
    $b = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($d);
    return date('j', $t) . ' ' . $b[(int)date('n', $t)] . ' ' . date('Y', $t);
}
function cover_color($s)
{
    $pal = [['#2A2C84', '#4A4DC4'], ['#1F7A4A', '#2E9E48'], ['#A0522D', '#D2843C'], ['#7B2D8E', '#A852BF'], ['#0E6B8C', '#1E9CC8'], ['#8C2A2A', '#C24848']];
    return $pal[abs(crc32($s)) % count($pal)];
}
function links_for($id, $notes)
{
    $out = [];
    try {
        $rows = q("SELECT f.file_title, f.file_name, f.file_url FROM biblio_attachment a JOIN files f ON f.file_id = a.file_id
                   WHERE a.biblio_id = ? AND a.access_type = 'public'", [$id])->fetchAll();
        foreach ($rows as $r) {
            $u = $r['file_url'] ?: $r['file_name'];
            if (preg_match('~^https?://~i', $u)) $out[$u] = $r['file_title'] ?: 'Buku digital';
        }
    } catch (Throwable $e) {}
    if (preg_match_all('~https?://[^\s<>"\']+~i', (string)$notes, $m)) foreach ($m[0] as $u) $out[rtrim($u, '.,;)')] = 'Versi digital';
    return $out;
}

const SQL_ITEMS = "SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id";
const SQL_AVAIL = "SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id
                   AND (i.item_status_id IS NULL OR i.item_status_id IN ('', '0'))
                   AND NOT EXISTS (SELECT 1 FROM loan l WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0)";
const SQL_ONSITE = "SELECT COUNT(*) FROM item i WHERE i.biblio_id = b.biblio_id AND i.item_status_id = 'NL'
                   AND NOT EXISTS (SELECT 1 FROM loan l WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0)";
const SQL_NEXT = "SELECT MIN(l.due_date) FROM loan l JOIN item i ON i.item_code = l.item_code WHERE i.biblio_id = b.biblio_id AND l.is_lent = 1 AND l.is_return = 0";
const SQL_AUTH = "SELECT GROUP_CONCAT(a.author_name ORDER BY ba.level, a.author_name SEPARATOR '; ') FROM biblio_author ba JOIN mst_author a ON a.author_id = ba.author_id WHERE ba.biblio_id = b.biblio_id";

/* ---------------- Logika halaman ---------------- */
$q = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($_GET['q'] ?? ''), 0, 80)));
$kelas = in_array($_GET['kelas'] ?? '', ['7', '8', '9'], true) ? $_GET['kelas'] : '';
$jenis = in_array($_GET['jenis'] ?? '', ['paket', 'umum'], true) ? $_GET['jenis'] : '';
$tersedia = !empty($_GET['tersedia']);
$page = max(1, (int)($_GET['hal'] ?? 1));
$per = max(4, min(48, (int)$CFG['per_halaman']));
$detailId = (int)($_GET['id'] ?? 0);
$err = '';
$books = []; $total = 0; $detail = null; $stats = null; $subjects = [];
$semua = !empty($_GET['semua']);
$searching = $q !== '' || $kelas !== '' || $jenis !== '' || $tersedia || $semua;

try {
    $lib = lib_name();
    if ($detailId) {
        $detail = q('SELECT b.*, p.publisher_name, pl.place_name, l.language_name, g.gmd_name, (' . SQL_AUTH . ') authors
                     FROM biblio b LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
                     LEFT JOIN mst_language l ON l.language_id = b.language_id LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
                     WHERE b.biblio_id = ? AND b.opac_hide = 0', [$detailId])->fetch();
        if ($detail) {
            $detail['subjek'] = q('SELECT t.topic FROM biblio_topic bt JOIN mst_topic t ON t.topic_id = bt.topic_id WHERE bt.biblio_id = ? ORDER BY bt.level, t.topic', [$detailId])->fetchAll(PDO::FETCH_COLUMN);
            $detail['items'] = q("SELECT i.item_code, lo.location_name, c.coll_type_name, i.item_status_id, s.item_status_name, ln.due_date
                                  FROM item i LEFT JOIN mst_location lo ON lo.location_id = i.location_id LEFT JOIN mst_coll_type c ON c.coll_type_id = i.coll_type_id
                                  LEFT JOIN mst_item_status s ON s.item_status_id = i.item_status_id
                                  LEFT JOIN loan ln ON ln.item_code = i.item_code AND ln.is_lent = 1 AND ln.is_return = 0
                                  WHERE i.biblio_id = ? ORDER BY i.item_code", [$detailId])->fetchAll();
            $detail['links'] = links_for($detailId, $detail['notes']);
        }
    } else {
        $where = ['b.opac_hide = 0'];
        $p = [];
        if ($q !== '') {
            foreach (array_slice(preg_split('/\s+/u', $q), 0, 6) as $w) {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w) . '%';
                $where[] = "(b.title LIKE ? OR b.isbn_issn LIKE ? OR b.call_number LIKE ? OR b.series_title LIKE ?
                            OR EXISTS (SELECT 1 FROM biblio_author ba JOIN mst_author a ON a.author_id = ba.author_id WHERE ba.biblio_id = b.biblio_id AND a.author_name LIKE ?)
                            OR EXISTS (SELECT 1 FROM biblio_topic bt JOIN mst_topic t ON t.topic_id = bt.topic_id WHERE bt.biblio_id = b.biblio_id AND t.topic LIKE ?)
                            OR EXISTS (SELECT 1 FROM mst_publisher pu WHERE pu.publisher_id = b.publisher_id AND pu.publisher_name LIKE ?))";
                array_push($p, $like, $like, $like, $like, $like, $like, $like);
            }
        }
        if ($kelas !== '') {
            $roman = ['7' => 'VII', '8' => 'VIII', '9' => 'IX'][$kelas];
            $where[] = "(EXISTS (SELECT 1 FROM item i WHERE i.biblio_id = b.biblio_id AND i.item_code REGEXP ?)
                         OR b.title REGEXP ? OR b.title REGEXP ?)";
            array_push($p, '^[A-Z]{2,3}' . $kelas . 'G?-', '[Kk]elas[[:space:]]+' . $roman . '([^I]|$)', '[Kk]elas[[:space:]]+' . $kelas . '([^0-9]|$)');
        }
        if ($jenis === 'paket') $where[] = "EXISTS (SELECT 1 FROM item i WHERE i.biblio_id = b.biblio_id AND i.item_code REGEXP '^[A-Z]{2,3}[789]G?-')";
        if ($jenis === 'umum') $where[] = "NOT EXISTS (SELECT 1 FROM item i WHERE i.biblio_id = b.biblio_id AND i.item_code REGEXP '^[A-Z]{2,3}[789]G?-')";
        if ($tersedia) $where[] = '((' . SQL_AVAIL . ') > 0 OR (' . SQL_ONSITE . ') > 0)';
        $w = implode(' AND ', $where);
        $total = (int)q("SELECT COUNT(*) FROM biblio b WHERE $w", $p)->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        $page = min($page, $pages);
        $off = ($page - 1) * $per;
        $books = q("SELECT b.biblio_id, b.title, b.publish_year, b.call_number, b.image, b.edition,
                      (SELECT publisher_name FROM mst_publisher WHERE publisher_id = b.publisher_id) publisher,
                      (" . SQL_AUTH . ") authors, (" . SQL_ITEMS . ") n_items, (" . SQL_AVAIL . ") n_avail,
                      (" . SQL_ONSITE . ") n_onsite, (" . SQL_NEXT . ") next_due
                    FROM biblio b WHERE $w ORDER BY " . ($q !== '' ? '(b.title LIKE ?) DESC, ' : '') . "b.input_date DESC, b.biblio_id DESC LIMIT $per OFFSET $off",
                    $q !== '' ? array_merge($p, ['%' . $q . '%']) : $p)->fetchAll();
        if (!$searching) {
            $stats = q("SELECT (SELECT COUNT(*) FROM biblio WHERE opac_hide = 0) judul, (SELECT COUNT(*) FROM item) eksemplar,
                          (SELECT COUNT(*) FROM item i JOIN biblio b ON b.biblio_id = i.biblio_id WHERE b.opac_hide = 0 AND (i.item_status_id IS NULL OR i.item_status_id IN ('', '0'))
                            AND NOT EXISTS (SELECT 1 FROM loan l WHERE l.item_code = i.item_code AND l.is_lent = 1 AND l.is_return = 0)) siap")->fetch();
            $subjects = q("SELECT t.topic, COUNT(*) n FROM biblio_topic bt JOIN mst_topic t ON t.topic_id = bt.topic_id JOIN biblio b ON b.biblio_id = bt.biblio_id
                           WHERE b.opac_hide = 0 GROUP BY t.topic ORDER BY n DESC, t.topic LIMIT 12")->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('[katalog] ' . $e->getMessage());
    $err = 'Katalog sedang tidak dapat diakses. Silakan coba beberapa saat lagi.';
    $lib = $lib ?? 'Perpustakaan';
}

function url_with(array $over)
{
    global $q, $kelas, $jenis, $tersedia;
    $a = array_filter(['q' => $q, 'kelas' => $kelas, 'jenis' => $jenis, 'tersedia' => $tersedia ? 1 : '', 'semua' => !empty($GLOBALS['semua']) ? 1 : ''], 'strlen');
    foreach ($over as $k => $v) { if ($v === null || $v === '') unset($a[$k]); else $a[$k] = $v; }
    return './' . ($a ? '?' . http_build_query($a) : '');
}

function cover_html($b, $big = false)
{
    $c = cover_color($b['title']);
    $img = !empty($b['image']) ? '<img src="?sampul=' . (int)$b['biblio_id'] . '" alt="" loading="lazy" onerror="this.remove()">' : '';
    return '<div class="cover' . ($big ? ' big' : '') . '" style="background:linear-gradient(150deg,' . $c[0] . ',' . $c[1] . ')"><span>' . h(mb_strimwidth($b['title'], 0, $big ? 70 : 48, '…')) . '</span>' . $img . '</div>';
}

function status_html($b)
{
    $n = (int)$b['n_items']; $a = (int)$b['n_avail']; $o = (int)$b['n_onsite'];
    if ($n === 0) return '<span class="st grey">Belum ada eksemplar</span>';
    if ($a > 0) return '<span class="st green">✓ Tersedia ' . $a . ' dari ' . $n . '</span>';
    if ($o > 0) return '<span class="st blue">Baca di tempat</span>';
    if (!empty($b['next_due'])) return '<span class="st red">Sedang dipinjam · kembali ±' . h(tgl($b['next_due'])) . '</span>';
    return '<span class="st red">Tidak tersedia</span>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $detail ? h($detail['title']) . ' · ' : ($q !== '' ? 'Cari "' . h($q) . '" · ' : '') ?>Katalog <?php echo h($lib) ?></title>
<meta name="description" content="Cari buku dan cek ketersediaannya di <?php echo h($lib) ?>.">
<link rel="icon" href="?logo=1">
<style>
@font-face { font-family: "Quicksand"; src: url('fonts/Quicksand-Regular.ttf') format('truetype'); font-weight: 400; font-display: swap; }
@font-face { font-family: "Quicksand"; src: url('fonts/Quicksand-Bold.ttf') format('truetype'); font-weight: 700; font-display: swap; }
:root { --navy:#2A2C84; --navy-2:#1B1D5E; --sky:#A0D9F6; --sky-soft:#EAF6FD; --gold:#F2C230; --green:#2E9E48; --ink:#1A1A2E; --muted:#5B6382; --line:#E3E8F2; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Quicksand', 'Segoe UI', sans-serif; color: var(--ink); background: #F5F8FC; }
a { color: var(--navy); }
.hero { background: linear-gradient(120deg, var(--navy), var(--navy-2)); color: #fff; position: relative; padding: 18px 16px 26px; }
.hero::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: linear-gradient(90deg, var(--gold) 0 70%, var(--green) 70%); }
.wrap { max-width: 1060px; margin: 0 auto; }
.brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: #fff; }
.brand img { width: 46px; height: 46px; object-fit: contain; }
.brand .k { color: var(--gold); font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
.brand .t { font-size: 19px; font-weight: 700; line-height: 1.15; }
.hero h1 { font-size: 28px; margin: 22px 0 6px; }
.hero p { margin: 0 0 16px; opacity: .85; }
form.search { display: flex; gap: 8px; background: #fff; padding: 6px; border-radius: 14px; box-shadow: 0 6px 24px rgba(0,0,0,.18); }
form.search input[type=search] { flex: 1; min-width: 0; border: 0; outline: none; font: inherit; font-size: 17px; padding: 10px 12px; color: var(--ink); }
form.search button { border: 0; border-radius: 10px; background: var(--gold); color: var(--navy-2); font: inherit; font-weight: 700; font-size: 16px; padding: 0 20px; cursor: pointer; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; background: rgba(255,255,255,.12); color: #fff; text-decoration: none; font-size: 14px; font-weight: 700; border: 1px solid rgba(255,255,255,.2); }
.chip.on { background: var(--gold); color: var(--navy-2); border-color: var(--gold); }
main { padding: 22px 16px 50px; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 22px; }
.stat { background: #fff; border-radius: 14px; padding: 14px 16px; box-shadow: 0 2px 10px rgba(27,29,94,.06); }
.stat b { font-size: 28px; color: var(--navy); display: block; }
.stat span { color: var(--muted); font-size: 14px; }
h2 { color: var(--navy); font-size: 20px; margin: 0 0 12px; }
.subjects { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; }
.subjects a { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 6px 12px; text-decoration: none; font-size: 14px; font-weight: 700; }
.subjects a:hover { border-color: var(--navy); }
.info { color: var(--muted); margin: 0 0 14px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 16px; }
.book { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(27,29,94,.06); text-decoration: none; color: inherit; display: flex; flex-direction: column; transition: transform .15s, box-shadow .15s; }
.book:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(27,29,94,.12); }
.cover { position: relative; height: 150px; display: flex; align-items: flex-end; padding: 14px; color: #fff; overflow: hidden; }
.cover span { font-weight: 700; font-size: 16px; line-height: 1.25; text-shadow: 0 1px 2px rgba(0,0,0,.25); position: relative; z-index: 1; }
.cover::before { content: '📖'; position: absolute; right: 12px; top: 8px; font-size: 34px; opacity: .35; }
.cover img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 2; background: #fff; }
.cover.big { height: 260px; border-radius: 14px; }
.book .b { padding: 12px 14px 14px; display: flex; flex-direction: column; gap: 4px; flex: 1; }
.book .tt { font-weight: 700; font-size: 16px; line-height: 1.3; }
.book .au { color: var(--muted); font-size: 13.5px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.book .cn { font-size: 13px; color: var(--muted); margin-top: auto; padding-top: 6px; }
.st { display: inline-block; font-size: 13px; font-weight: 700; border-radius: 8px; padding: 3px 8px; margin-top: 6px; align-self: flex-start; }
.st.green { background: #E3F5E8; color: #1F7A3A; } .st.red { background: #FCE8E6; color: #B3261E; }
.st.blue { background: var(--sky-soft); color: var(--navy); } .st.grey { background: #EEF0F5; color: var(--muted); }
.empty { background: #fff; border-radius: 14px; padding: 30px; text-align: center; color: var(--muted); }
.pager { display: flex; gap: 6px; justify-content: center; margin-top: 22px; flex-wrap: wrap; }
.pager a, .pager span { padding: 8px 13px; border-radius: 8px; background: #fff; text-decoration: none; font-weight: 700; border: 1px solid var(--line); }
.pager span { background: var(--navy); color: #fff; border-color: var(--navy); }
.detail { display: grid; grid-template-columns: 220px 1fr; gap: 26px; background: #fff; border-radius: 16px; padding: 22px; box-shadow: 0 2px 10px rgba(27,29,94,.06); }
.detail h1 { margin: 0 0 6px; font-size: 26px; color: var(--navy); line-height: 1.25; }
.detail .au { color: var(--muted); margin-bottom: 12px; }
dl { display: grid; grid-template-columns: 150px 1fr; gap: 6px 12px; margin: 14px 0; font-size: 15px; }
dt { color: var(--muted); } dd { margin: 0; font-weight: 700; }
.tags a { display: inline-block; background: var(--sky-soft); border-radius: 14px; padding: 3px 10px; margin: 0 6px 6px 0; font-size: 13px; text-decoration: none; font-weight: 700; }
table.items { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 14.5px; }
table.items th, table.items td { text-align: left; padding: 9px 8px; border-bottom: 1px solid var(--line); }
table.items th { color: var(--muted); font-size: 13px; text-transform: uppercase; letter-spacing: .5px; }
.btn { display: inline-flex; align-items: center; gap: 6px; background: var(--navy); color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: 700; margin: 4px 6px 0 0; }
.btn.gold { background: var(--gold); color: var(--navy-2); }
.back { display: inline-block; margin-bottom: 14px; font-weight: 700; text-decoration: none; }
.note { background: #FFF8E1; border-left: 4px solid var(--gold); padding: 10px 12px; border-radius: 8px; font-size: 14px; margin-top: 14px; }
footer { text-align: center; color: #8a91a8; font-size: 13px; padding: 0 16px 30px; }
@media (max-width: 680px) {
  .hero h1 { font-size: 22px; } .stats { grid-template-columns: repeat(3, 1fr); } .stat b { font-size: 22px; }
  .detail { grid-template-columns: 1fr; } .cover.big { height: 180px; } dl { grid-template-columns: 120px 1fr; }
  .grid { grid-template-columns: 1fr 1fr; gap: 10px; } .cover { height: 120px; } .cover span { font-size: 14px; }
  .book .tt { font-size: 14.5px; } table.items .hide-m { display: none; }
}
</style>
</head>
<body>
<header class="hero">
  <div class="wrap">
    <a class="brand" href="./"><img src="?logo=1" alt=""><div><div class="k">Katalog Online</div><div class="t"><?php echo h($lib) ?></div></div></a>
    <?php if (!$detail) : ?>
      <h1>Cari buku di perpustakaan</h1>
      <p>Ketik judul, pengarang, mata pelajaran, atau ISBN, lalu lihat apakah bukunya sedang tersedia.</p>
    <?php else : ?><div style="height:16px"></div><?php endif ?>
    <form class="search" action="./" method="get" role="search">
      <input type="search" name="q" value="<?php echo h($q) ?>" placeholder="Contoh: informatika, laskar pelangi, matematika kelas 8" aria-label="Cari buku">
      <?php if ($kelas) : ?><input type="hidden" name="kelas" value="<?php echo h($kelas) ?>"><?php endif ?>
      <?php if ($jenis) : ?><input type="hidden" name="jenis" value="<?php echo h($jenis) ?>"><?php endif ?>
      <?php if ($tersedia) : ?><input type="hidden" name="tersedia" value="1"><?php endif ?>
      <button type="submit">Cari</button>
    </form>
    <?php if (!$detail) : ?>
    <nav class="filters" aria-label="Saring">
      <?php foreach (['7', '8', '9'] as $k) : ?>
        <a class="chip<?php echo $kelas === $k ? ' on' : '' ?>" href="<?php echo h(url_with(['kelas' => $kelas === $k ? null : $k, 'hal' => null])) ?>">Kelas <?php echo $k ?></a>
      <?php endforeach ?>
      <a class="chip<?php echo $jenis === 'paket' ? ' on' : '' ?>" href="<?php echo h(url_with(['jenis' => $jenis === 'paket' ? null : 'paket', 'hal' => null])) ?>">Buku paket</a>
      <a class="chip<?php echo $jenis === 'umum' ? ' on' : '' ?>" href="<?php echo h(url_with(['jenis' => $jenis === 'umum' ? null : 'umum', 'hal' => null])) ?>">Bacaan umum</a>
      <a class="chip<?php echo $tersedia ? ' on' : '' ?>" href="<?php echo h(url_with(['tersedia' => $tersedia ? null : 1, 'hal' => null])) ?>">✓ Hanya yang tersedia</a>
    </nav>
    <?php endif ?>
  </div>
</header>

<main><div class="wrap">
<?php if ($err) : ?>
  <div class="empty"><?php echo h($err) ?></div>

<?php elseif ($detailId) : ?>
  <a class="back" href="javascript:history.length>1?history.back():location.href='./'">← Kembali</a>
  <?php if (!$detail) : ?>
    <div class="empty">Buku tidak ditemukan.</div>
  <?php else :
      $b = $detail; $items = $b['items'];
      $avail = 0; $next = null;
      foreach ($items as $it) {
          $ok = !$it['due_date'] && in_array((string)$it['item_status_id'], ['', '0'], true);
          if ($ok) $avail++;
          if ($it['due_date'] && (!$next || $it['due_date'] < $next)) $next = $it['due_date'];
      } ?>
    <article class="detail">
      <div><?php echo cover_html($b, true) ?></div>
      <div>
        <h1><?php echo h($b['title']) ?></h1>
        <div class="au"><?php echo h(str_replace(';', ',', (string)$b['authors'])) ?: '<i>Pengarang tidak tercatat</i>' ?></div>
        <?php echo status_html(['n_items' => count($items), 'n_avail' => $avail, 'n_onsite' => 0, 'next_due' => $next]) ?>
        <dl>
          <?php if ($b['call_number']) : ?><dt>No. panggil</dt><dd><?php echo h($b['call_number']) ?></dd><?php endif ?>
          <?php if ($b['publisher_name']) : ?><dt>Penerbit</dt><dd><?php echo h($b['publisher_name']) ?></dd><?php endif ?>
          <?php if ($b['place_name'] || $b['publish_year']) : ?><dt>Terbit</dt><dd><?php echo h(trim($b['place_name'] . ($b['place_name'] && $b['publish_year'] ? ', ' : '') . $b['publish_year'])) ?></dd><?php endif ?>
          <?php if ($b['edition']) : ?><dt>Edisi</dt><dd><?php echo h($b['edition']) ?></dd><?php endif ?>
          <?php if ($b['collation']) : ?><dt>Deskripsi fisik</dt><dd><?php echo h($b['collation']) ?></dd><?php endif ?>
          <?php if ($b['isbn_issn']) : ?><dt>ISBN</dt><dd><?php echo h($b['isbn_issn']) ?></dd><?php endif ?>
          <?php if ($b['language_name']) : ?><dt>Bahasa</dt><dd><?php echo h($b['language_name']) ?></dd><?php endif ?>
        </dl>
        <?php if ($b['subjek']) : ?><div class="tags"><?php foreach ($b['subjek'] as $s) echo '<a href="./?q=' . urlencode($s) . '">' . h($s) . '</a>'; ?></div><?php endif ?>
        <?php foreach ($b['links'] as $u => $t) : ?><a class="btn gold" href="<?php echo h($u) ?>" target="_blank" rel="noopener">📱 Baca <?php echo h(mb_strtolower($t) === 'versi digital' ? 'versi digital' : 'buku digital') ?></a><?php endforeach ?>

        <h2 style="margin-top:22px">Eksemplar di perpustakaan</h2>
        <?php if (!$items) : ?>
          <p class="info">Buku ini belum memiliki eksemplar fisik.</p>
        <?php else : ?>
        <table class="items">
          <tr><th>Kode</th><th class="hide-m">Lokasi</th><th>Status</th></tr>
          <?php foreach ($items as $it) :
              if ($it['due_date']) $s = '<span class="st red">Dipinjam s.d. ' . h(tgl($it['due_date'])) . '</span>';
              elseif ($it['item_status_id'] === 'NL') $s = '<span class="st blue">Baca di tempat</span>';
              elseif (in_array((string)$it['item_status_id'], ['', '0'], true)) $s = '<span class="st green">Tersedia</span>';
              else $s = '<span class="st grey">' . h($it['item_status_name'] ?: 'Tidak tersedia') . '</span>'; ?>
            <tr><td><b><?php echo h($it['item_code']) ?></b></td><td class="hide-m"><?php echo h($it['location_name'] ?: '-') ?></td><td><?php echo $s ?></td></tr>
          <?php endforeach ?>
        </table>
        <?php endif ?>
        <div class="note">Untuk meminjam, catat <b>no. panggil</b> di atas, ambil buku di rak, lalu bawa ke meja petugas bersama kartu anggota.</div>
      </div>
    </article>
  <?php endif ?>

<?php else : ?>
  <?php if ($stats) : ?>
    <div class="stats">
      <div class="stat"><b><?php echo (int)$stats['judul'] ?></b><span>Judul buku</span></div>
      <div class="stat"><b><?php echo (int)$stats['eksemplar'] ?></b><span>Eksemplar</span></div>
      <div class="stat"><b><?php echo (int)$stats['siap'] ?></b><span>Siap dipinjam</span></div>
    </div>
    <?php if ($subjects) : ?>
      <h2>Jelajahi subjek</h2>
      <div class="subjects"><?php foreach ($subjects as $s) echo '<a href="./?q=' . urlencode($s['topic']) . '">' . h($s['topic']) . '</a>'; ?></div>
    <?php endif ?>
    <h2 style="display:flex;justify-content:space-between;align-items:baseline">Buku terbaru <?php if ((int)$stats['judul'] > count($books)) : ?><a href="./?semua=1" style="font-size:15px">Lihat semua →</a><?php endif ?></h2>
  <?php else : ?>
    <p class="info"><?php echo $total ? '<b>' . $total . '</b> buku ditemukan' : '' ?><?php echo $q !== '' ? ' untuk "<b>' . h($q) . '</b>"' : '' ?><?php echo $kelas ? ' · Kelas ' . h($kelas) : '' ?><?php echo $jenis === 'paket' ? ' · Buku paket' : ($jenis === 'umum' ? ' · Bacaan umum' : '') ?><?php echo $tersedia ? ' · hanya yang tersedia' : '' ?></p>
  <?php endif ?>

  <?php if (!$books) : ?>
    <div class="empty">
      <?php if ($searching) : ?>Tidak ada buku yang cocok. Coba kata kunci lain, atau <a href="./">lihat semua buku</a>.
      <?php else : ?>Katalog masih kosong.<?php endif ?>
    </div>
  <?php else : ?>
    <div class="grid">
      <?php foreach ($books as $b) : ?>
        <a class="book" href="?id=<?php echo (int)$b['biblio_id'] ?>">
          <?php echo cover_html($b) ?>
          <div class="b">
            <div class="tt"><?php echo h($b['title']) ?></div>
            <div class="au"><?php echo h(str_replace(';', ',', (string)$b['authors'])) ?></div>
            <?php echo status_html($b) ?>
            <div class="cn"><?php echo h(trim(($b['call_number'] ? $b['call_number'] . ' · ' : '') . ($b['publish_year'] ?: ''), ' ·')) ?></div>
          </div>
        </a>
      <?php endforeach ?>
    </div>
    <?php if ($searching && $total > $per) : $pages = (int)ceil($total / $per); ?>
      <nav class="pager">
        <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) echo $i === $page ? "<span>$i</span>" : '<a href="' . h(url_with(['hal' => $i])) . '">' . $i . '</a>'; ?>
      </nav>
    <?php endif ?>
  <?php endif ?>
<?php endif ?>
</div></main>
<footer><?php echo h($lib) ?> · <a href="<?php echo h($CFG['url_opac']) ?>">OPAC lengkap</a> · <a href="<?php echo h(rtrim($CFG['url_opac'], '/')) ?>/index.php?p=member">Area anggota</a></footer>
</body>
</html>
