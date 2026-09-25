<?php
// Backend do site Keila Costa: agenda, pré-atendimento e painel.
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DATA_DIR = __DIR__ . '/_dados';
const STATUS = ['novo', 'confirmado', 'realizado', 'cancelado'];
const DEFAULTS = [
  'whatsapp' => '', 'valor' => 100, 'pix' => '', 'endereco' => '',
  // dias da semana: 0 = domingo ... 6 = sábado
  'agenda' => [
    'dias' => [
      '0' => ['on' => false, 'ini' => '09:00', 'fim' => '19:00'],
      '1' => ['on' => false, 'ini' => '09:00', 'fim' => '19:00'],
      '2' => ['on' => true,  'ini' => '09:00', 'fim' => '19:00'],
      '3' => ['on' => true,  'ini' => '09:00', 'fim' => '19:00'],
      '4' => ['on' => true,  'ini' => '09:00', 'fim' => '19:00'],
      '5' => ['on' => true,  'ini' => '09:00', 'fim' => '19:00'],
      '6' => ['on' => true,  'ini' => '09:00', 'fim' => '19:00'],
    ],
    'duracao' => 60, 'pausaIni' => '12:00', 'pausaFim' => '13:00',
    'antecedencia' => 3, 'janela' => 60, 'bloqueios' => [],
  ],
];

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'erro' => $msg], $code); }

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
  $ht = DATA_DIR . '/.htaccess';
  if (!is_file($ht)) file_put_contents($ht, "Require all denied\nDeny from all\n");
  $pdo = new PDO('sqlite:' . DATA_DIR . '/agenda.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=4000;');
  $pdo->exec('CREATE TABLE IF NOT EXISTS agendamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT, criado TEXT NOT NULL, atualizado TEXT,
    data TEXT, hora TEXT, status TEXT NOT NULL DEFAULT "novo", pago INTEGER NOT NULL DEFAULT 0, valor REAL,
    nome TEXT, whatsapp TEXT, respostas TEXT, notas TEXT DEFAULT "", origem TEXT, ip TEXT)');
  $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS slot_unico ON agendamentos(data, hora) WHERE status <> "cancelado" AND data IS NOT NULL AND data <> ""');
  $pdo->exec('CREATE TABLE IF NOT EXISTS ajustes (k TEXT PRIMARY KEY, v TEXT)');
  // senha inicial: _dados/senha-inicial.txt é consumida no primeiro acesso
  $init = DATA_DIR . '/senha-inicial.txt';
  if (is_file($init)) {
    $pw = trim((string)file_get_contents($init));
    if ($pw !== '') setting('senha', password_hash($pw, PASSWORD_DEFAULT));
    unlink($init);
  }
  return $pdo;
}
function setting(string $k, $v = null) {
  if ($v === null) {
    $st = db()->prepare('SELECT v FROM ajustes WHERE k = ?'); $st->execute([$k]);
    $r = $st->fetchColumn(); return $r === false ? null : json_decode($r, true);
  }
  db()->prepare('INSERT INTO ajustes(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
  return $v;
}
function config(): array {
  $c = setting('config') ?? [];
  $out = array_replace(DEFAULTS, $c);
  $out['agenda'] = array_replace(DEFAULTS['agenda'], $c['agenda'] ?? []);
  $out['agenda']['dias'] = array_replace(DEFAULTS['agenda']['dias'], $c['agenda']['dias'] ?? []);
  return $out;
}
function mins(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm . ':0')); return $h * 60 + $m; }
function hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function validDate($d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
function validTime($t): bool { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

// horários de trabalho de um dia (sem descontar ocupados)
function daySlots(array $ag, string $date): array {
  $dow = (string)(int)date('w', strtotime($date));
  $d = $ag['dias'][$dow] ?? null;
  if (!$d || empty($d['on']) || in_array($date, $ag['bloqueios'] ?? [], true)) return [];
  $dur = max(15, (int)$ag['duracao']); $ini = mins($d['ini']); $fim = mins($d['fim']);
  $pi = $ag['pausaIni'] ? mins($ag['pausaIni']) : -1; $pf = $ag['pausaFim'] ? mins($ag['pausaFim']) : -1;
  $out = [];
  for ($t = $ini; $t + $dur <= $fim; $t += $dur) {
    if ($pi >= 0 && $pf > $pi && $t < $pf && $t + $dur > $pi) { $t = $pf - $dur; continue; }
    $out[] = hm($t);
  }
  return $out;
}
function taken(string $from, string $to, int $except = 0): array {
  $st = db()->prepare('SELECT data, hora FROM agendamentos WHERE status <> "cancelado" AND data BETWEEN ? AND ? AND id <> ?');
  $st->execute([$from, $to, $except]);
  $t = [];
  foreach ($st as $r) $t[$r['data'] . ' ' . $r['hora']] = true;
  return $t;
}
function freeSlots(string $from, string $to): array {
  $ag = config()['agenda'];
  $now = time(); $minTs = $now + (int)$ag['antecedencia'] * 3600;
  $maxDate = date('Y-m-d', strtotime('+' . (int)$ag['janela'] . ' days', $now));
  $today = date('Y-m-d', $now);
  if ($from < $today) $from = $today;
  if ($to > $maxDate) $to = $maxDate;
  $busy = taken($from, $to);
  $days = [];
  for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $free = [];
    foreach (daySlots($ag, $d) as $h) {
      if (strtotime("$d $h") < $minTs || isset($busy["$d $h"])) continue;
      $free[] = $h;
    }
    $days[$d] = $free;
  }
  return $days;
}

function isAdmin(): bool { return !empty($_SESSION['adm']) && ($_SESSION['exp'] ?? 0) > time(); }
function needAdmin(): void {
  if (!isAdmin()) fail('Sessão expirada. Entre de novo.', 401);
  if (($_SERVER['HTTP_X_REQ'] ?? '') !== '1') fail('Requisição inválida.', 403);
  $_SESSION['exp'] = time() + 8 * 3600;
}
function row(array $r): array {
  $r['id'] = (int)$r['id']; $r['pago'] = (bool)$r['pago']; $r['valor'] = (float)$r['valor'];
  $r['respostas'] = json_decode($r['respostas'] ?: '{}', true) ?: [];
  unset($r['ip']);
  return $r;
}
function clean($v, int $max = 600) {
  if (is_array($v)) return array_slice(array_values(array_filter(array_map(fn($x) => is_string($x) ? mb_substr(trim($x), 0, 160) : null, $v), fn($x) => $x !== null && $x !== '')), 0, 30);
  return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : '';
}

session_name('kcadm');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$a = $_GET['a'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input', false, null, 0, 200000);
  $in = json_decode($raw ?: '[]', true) ?: [];
}

try {
  switch ($a) {
    case 'config': {
      $c = config();
      out(['ok' => true, 'config' => ['whatsapp' => $c['whatsapp'], 'valor' => $c['valor'], 'pix' => $c['pix'], 'endereco' => $c['endereco'], 'duracao' => $c['agenda']['duracao']]]);
    }
    case 'horarios': {
      $from = $_GET['de'] ?? date('Y-m-d'); $to = $_GET['ate'] ?? date('Y-m-d', strtotime('+45 days'));
      if (!validDate($from) || !validDate($to) || $to < $from) fail('Período inválido.');
      if ((strtotime($to) - strtotime($from)) > 92 * 86400) $to = date('Y-m-d', strtotime($from . ' +92 days'));
      $ag = config()['agenda'];
      out(['ok' => true, 'dias' => freeSlots($from, $to), 'limite' => date('Y-m-d', strtotime('+' . (int)$ag['janela'] . ' days'))]);
    }
    case 'agendar': {
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
      if (!empty($in['site'])) out(['ok' => true, 'id' => 0]); // honeypot
      $r = is_array($in['respostas'] ?? null) ? $in['respostas'] : [];
      $nome = clean($r['nome'] ?? '', 120); $wh = clean($r['whatsapp'] ?? '', 40);
      $data = $in['data'] ?? ''; $hora = $in['hora'] ?? '';
      if (mb_strlen($nome) < 3) fail('Informe o nome completo.');
      if (strlen(preg_replace('/\D/', '', $wh)) < 10) fail('Informe o WhatsApp com DDD.');
      if (!validDate($data) || !validTime($hora)) fail('Escolha um dia e um horário.');
      $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . 'kc');
      $st = db()->prepare('SELECT COUNT(*) FROM agendamentos WHERE ip = ? AND criado > ?');
      $st->execute([$ip, date('c', time() - 3600)]);
      if ((int)$st->fetchColumn() >= 5) fail('Muitos pedidos seguidos. Tente de novo mais tarde ou chame no WhatsApp.', 429);
      $free = freeSlots($data, $data)[$data] ?? [];
      if (!in_array($hora, $free, true)) fail('Esse horário acabou de ser reservado. Escolha outro.', 409);
      $resp = [];
      foreach ($r as $k => $v) if (is_string($k) && preg_match('/^[a-zA-Z]{1,30}$/', $k)) $resp[$k] = clean($v, 1500);
      $c = config();
      try {
        db()->prepare('INSERT INTO agendamentos(criado, data, hora, status, pago, valor, nome, whatsapp, respostas, origem, ip) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([date('c'), $data, $hora, 'novo', 0, (float)$c['valor'], $nome, $wh, json_encode($resp, JSON_UNESCAPED_UNICODE), 'site', $ip]);
      } catch (PDOException $e) { fail('Esse horário acabou de ser reservado. Escolha outro.', 409); }
      out(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    }

    case 'entrar': {
      $hash = setting('senha');
      if (!$hash) fail('Painel ainda sem senha configurada.', 503);
      $tries = $_SESSION['tries'] ?? 0;
      if ($tries >= 5 && ($_SESSION['lock'] ?? 0) > time()) fail('Muitas tentativas. Aguarde alguns minutos.', 429);
      if (!password_verify((string)($in['senha'] ?? ''), $hash)) {
        $_SESSION['tries'] = $tries + 1; if ($_SESSION['tries'] >= 5) $_SESSION['lock'] = time() + 600;
        usleep(400000); fail('Senha incorreta.', 401);
      }
      session_regenerate_id(true);
      $_SESSION = ['adm' => 1, 'exp' => time() + 8 * 3600];
      out(['ok' => true]);
    }
    case 'sair': { $_SESSION = []; session_destroy(); out(['ok' => true]); }
    case 'eu': out(['ok' => true, 'admin' => isAdmin()]);

    case 'lista': {
      needAdmin();
      $rows = db()->query('SELECT * FROM agendamentos ORDER BY criado DESC LIMIT 2000')->fetchAll();
      out(['ok' => true, 'itens' => array_map('row', $rows)]);
    }
    case 'salvar': {
      needAdmin();
      $id = (int)($in['id'] ?? 0);
      $cur = db()->prepare('SELECT * FROM agendamentos WHERE id = ?'); $cur->execute([$id]); $cur = $cur->fetch();
      if (!$cur) fail('Agendamento não encontrado.', 404);
      $status = in_array($in['status'] ?? '', STATUS, true) ? $in['status'] : $cur['status'];
      $data = $in['data'] ?? $cur['data']; $hora = $in['hora'] ?? $cur['hora'];
      if ($data !== '' && !validDate($data)) fail('Data inválida.');
      if ($hora !== '' && !validTime($hora)) fail('Horário inválido.');
      try {
        db()->prepare('UPDATE agendamentos SET status=?, data=?, hora=?, pago=?, valor=?, notas=?, atualizado=? WHERE id=?')
          ->execute([$status, $data ?: null, $hora ?: null, !empty($in['pago']) ? 1 : 0, (float)($in['valor'] ?? $cur['valor']), clean($in['notas'] ?? $cur['notas'], 4000), date('c'), $id]);
      } catch (PDOException $e) { fail('Já existe outro agendamento neste dia e horário.', 409); }
      out(['ok' => true]);
    }
    case 'excluir': {
      needAdmin();
      db()->prepare('DELETE FROM agendamentos WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
      out(['ok' => true]);
    }
    case 'novo': {
      needAdmin();
      $nome = clean($in['nome'] ?? '', 120); $wh = clean($in['whatsapp'] ?? '', 40);
      if (mb_strlen($nome) < 2) fail('Informe o nome.');
      $data = $in['data'] ?? ''; $hora = $in['hora'] ?? '';
      if ($data !== '' && !validDate($data)) fail('Data inválida.');
      if ($hora !== '' && !validTime($hora)) fail('Horário inválido.');
      $resp = ['queixas' => array_filter([clean($in['queixa'] ?? '', 200)])];
      try {
        db()->prepare('INSERT INTO agendamentos(criado, data, hora, status, pago, valor, nome, whatsapp, respostas, notas, origem) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([date('c'), $data ?: null, $hora ?: null, $data ? 'confirmado' : 'novo', 0, (float)config()['valor'], $nome, $wh, json_encode($resp, JSON_UNESCAPED_UNICODE), clean($in['notas'] ?? '', 4000), 'manual']);
      } catch (PDOException $e) { fail('Já existe outro agendamento neste dia e horário.', 409); }
      out(['ok' => true]);
    }
    case 'ajustes': {
      needAdmin();
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c = config(); $n = $in;
        $c['whatsapp'] = clean($n['whatsapp'] ?? $c['whatsapp'], 40);
        $c['valor'] = max(0, (float)($n['valor'] ?? $c['valor']));
        $c['pix'] = clean($n['pix'] ?? $c['pix'], 200);
        $c['endereco'] = clean($n['endereco'] ?? $c['endereco'], 300);
        if (is_array($n['agenda'] ?? null)) {
          $g = $n['agenda']; $ag = $c['agenda'];
          foreach (range(0, 6) as $i) {
            $d = $g['dias'][(string)$i] ?? null; if (!is_array($d)) continue;
            $ini = validTime($d['ini'] ?? '') ? $d['ini'] : '09:00'; $fim = validTime($d['fim'] ?? '') ? $d['fim'] : '19:00';
            $ag['dias'][(string)$i] = ['on' => !empty($d['on']), 'ini' => $ini, 'fim' => $fim];
          }
          $ag['duracao'] = min(240, max(15, (int)($g['duracao'] ?? 60)));
          $ag['pausaIni'] = validTime($g['pausaIni'] ?? '') ? $g['pausaIni'] : '';
          $ag['pausaFim'] = validTime($g['pausaFim'] ?? '') ? $g['pausaFim'] : '';
          $ag['antecedencia'] = min(168, max(0, (int)($g['antecedencia'] ?? 3)));
          $ag['janela'] = min(180, max(7, (int)($g['janela'] ?? 60)));
          $ag['bloqueios'] = array_values(array_unique(array_filter($g['bloqueios'] ?? [], 'validDate')));
          sort($ag['bloqueios']);
          $c['agenda'] = $ag;
        }
        setting('config', $c);
      }
      out(['ok' => true, 'config' => config()]);
    }
    case 'senha': {
      needAdmin();
      if (!password_verify((string)($in['atual'] ?? ''), (string)setting('senha'))) fail('Senha atual incorreta.', 401);
      $nova = (string)($in['nova'] ?? '');
      if (strlen($nova) < 8) fail('A nova senha precisa ter pelo menos 8 caracteres.');
      setting('senha', password_hash($nova, PASSWORD_DEFAULT));
      out(['ok' => true]);
    }
    default: fail('Ação desconhecida.', 404);
  }
} catch (Throwable $e) {
  error_log('api.php: ' . $e->getMessage());
  fail('Erro no servidor. Tente de novo.', 500);
}
