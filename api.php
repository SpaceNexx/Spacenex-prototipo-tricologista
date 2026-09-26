<?php
// Backend do site Keila Costa: agenda, pré-atendimento e painel.
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const DATA_DIR = __DIR__ . '/_dados';
const MEDIA_DIR = __DIR__ . '/midia';
const STATUS = ['novo', 'confirmado', 'realizado', 'cancelado'];
// campos do formulário que não podem ser usados por perguntas criadas no painel
const RESERVED = ['nome', 'whatsapp', 'idade', 'observacoes', 'data', 'hora', 'site', 'padrao'];
const DEFAULTS = [
  'whatsapp' => '', 'valor' => 100, 'pix' => '', 'endereco' => '',
  'manutencao' => false, 'manutMsg' => 'Estamos preparando novidades. Volte em breve ou fale comigo pelo WhatsApp.',
  'foto' => '', 'fotoPos' => 0, 'perguntas' => null,
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
    'antecedencia' => 3, 'janela' => 60, 'bloqueios' => [], 'bloqHoras' => [],
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
function saveConfig(array $c): array { setting('config', $c); return config(); }
function mins(string $hm): int { [$h, $m] = array_map('intval', explode(':', $hm . ':0')); return $h * 60 + $m; }
function hm(int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); }
function validDate($d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
function validTime($t): bool { return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }
function nextDay(string $d): string { return date('Y-m-d', strtotime($d . ' +1 day')); }

// horários de atendimento de um dia pela grade semanal (sem bloqueios nem ocupados)
function gridSlots(array $ag, string $date): array {
  $d = $ag['dias'][(string)(int)date('w', strtotime($date))] ?? null;
  if (!$d || empty($d['on'])) return [];
  $dur = max(15, (int)$ag['duracao']); $ini = mins($d['ini']); $fim = mins($d['fim']);
  $pi = $ag['pausaIni'] ? mins($ag['pausaIni']) : -1; $pf = $ag['pausaFim'] ? mins($ag['pausaFim']) : -1;
  $out = [];
  for ($t = $ini; $t + $dur <= $fim; $t += $dur) {
    if ($pi >= 0 && $pf > $pi && $t < $pf && $t + $dur > $pi) { $t = $pf - $dur; continue; }
    $out[] = hm($t);
  }
  return $out;
}
function bookedIn(string $from, string $to, int $except = 0): array {
  $st = db()->prepare('SELECT id, data, hora, nome, status FROM agendamentos WHERE status <> "cancelado" AND data BETWEEN ? AND ? AND id <> ?');
  $st->execute([$from, $to, $except]);
  $t = [];
  foreach ($st as $r) $t[$r['data'] . ' ' . $r['hora']] = $r;
  return $t;
}
function freeSlots(string $from, string $to): array {
  $ag = config()['agenda'];
  $now = time(); $minTs = $now + (int)$ag['antecedencia'] * 3600;
  $maxDate = date('Y-m-d', strtotime('+' . (int)$ag['janela'] . ' days', $now));
  $today = date('Y-m-d', $now);
  if ($from < $today) $from = $today;
  if ($to > $maxDate) $to = $maxDate;
  $busy = bookedIn($from, $to);
  $days = [];
  for ($d = $from; $d <= $to; $d = nextDay($d)) {
    $free = [];
    if (!in_array($d, $ag['bloqueios'] ?? [], true)) {
      $off = $ag['bloqHoras'][$d] ?? [];
      foreach (gridSlots($ag, $d) as $h) {
        if (strtotime("$d $h") < $minTs || isset($busy["$d $h"]) || in_array($h, $off, true)) continue;
        $free[] = $h;
      }
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
function str($v, int $max): string { return is_scalar($v) ? mb_substr(trim((string)$v), 0, $max) : ''; }

// valida a lista de perguntas enviada pelo painel
function cleanQuestions($list): array {
  if (!is_array($list)) fail('Formulário inválido.');
  $out = []; $ids = [];
  foreach (array_slice($list, 0, 80) as $q) {
    if (!is_array($q)) continue;
    $id = (string)($q['id'] ?? '');
    if (!preg_match('/^[a-z][a-zA-Z0-9]{1,29}$/', $id) || isset($ids[$id])) fail('Identificador de pergunta inválido: ' . $id);
    $tipo = in_array($q['tipo'] ?? '', ['unica', 'multipla', 'texto', 'imagens'], true) ? $q['tipo'] : 'unica';
    if ($tipo === 'imagens' && $id !== 'padrao') fail('Só a pergunta das imagens pode usar esse tipo.');
    if ($id === 'padrao') $tipo = 'imagens';
    elseif (in_array($id, RESERVED, true)) fail('Identificador reservado: ' . $id);
    $ids[$id] = true;
    $n = [
      'id' => $id, 'etapa' => ((int)($q['etapa'] ?? 1)) === 2 ? 2 : 1, 'tipo' => $tipo,
      'titulo' => str($q['titulo'] ?? '', 200), 'rotulo' => str($q['rotulo'] ?? '', 60), 'sub' => str($q['sub'] ?? '', 300),
      'porque' => str($q['porque'] ?? '', 500), 'obrig' => !empty($q['obrig']), 'ativa' => !array_key_exists('ativa', $q) || !empty($q['ativa']),
      'sx' => in_array($q['sx'] ?? '', ['F', 'M'], true) ? $q['sx'] : '', 'fixa' => !empty($q['fixa']) || in_array($id, ['padrao', 'sexo'], true),
    ];
    if ($n['titulo'] === '') fail('Toda pergunta precisa de um texto.');
    if (is_array($q['se'] ?? null) && preg_match('/^[a-z][a-zA-Z0-9]{1,29}$/', (string)($q['se']['q'] ?? ''))) $n['se'] = ['q' => $q['se']['q'], 'v' => str($q['se']['v'] ?? '', 120)];
    if (in_array($tipo, ['unica', 'multipla'], true)) {
      $ops = [];
      foreach (array_slice(is_array($q['opcoes'] ?? null) ? $q['opcoes'] : [], 0, 24) as $o) {
        $v = str($o['v'] ?? '', 120); if ($v === '') continue;
        $ops[] = ['v' => $v, 't' => str($o['t'] ?? '', 120) ?: $v, 'd' => str($o['d'] ?? '', 200), 'nenhum' => !empty($o['nenhum']), 'sx' => in_array($o['sx'] ?? '', ['F', 'M'], true) ? $o['sx'] : ''];
      }
      if (count($ops) < 2) fail('A pergunta "' . $n['titulo'] . '" precisa de pelo menos duas opções.');
      $n['opcoes'] = $ops;
    }
    if (is_array($q['extra'] ?? null) && preg_match('/^[a-z][a-zA-Z0-9]{1,29}$/', (string)($q['extra']['id'] ?? ''))) {
      if (in_array($q['extra']['id'], RESERVED, true)) fail('Identificador reservado: ' . $q['extra']['id']);
      $n['extra'] = ['id' => $q['extra']['id'], 'label' => str($q['extra']['label'] ?? '', 120), 'ph' => str($q['extra']['ph'] ?? '', 120)];
    }
    $out[] = $n;
  }
  if (!isset($ids['padrao'])) fail('A pergunta das imagens não pode ser removida.');
  if (!isset($ids['sexo'])) fail('A pergunta "Para quem é a avaliação?" não pode ser removida.');
  return $out;
}

function saveImage(array $f): string {
  if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('Não recebi a imagem. Tente de novo.');
  if ($f['size'] > 12 * 1024 * 1024) fail('A imagem é muito grande. Use uma de até 12 MB.');
  $info = @getimagesize($f['tmp_name']);
  $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
  if (!$info || !isset($types[$info[2]])) fail('Use uma foto em JPG, PNG ou WEBP.');
  if (!is_dir(MEDIA_DIR)) mkdir(MEDIA_DIR, 0755, true);
  $ht = MEDIA_DIR . '/.htaccess';
  if (!is_file($ht)) file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n");
  $name = 'foto-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.jpg';
  if (function_exists('imagecreatetruecolor')) {
    $src = @$types[$info[2]]($f['tmp_name']);
    if (!$src) fail('Não consegui abrir essa imagem.');
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
      $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
      if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
    }
    $w = imagesx($src); $h = imagesy($src); $max = 1400;
    $s = min(1, $max / max($w, $h)); $nw = (int)round($w * $s); $nh = (int)round($h * $s);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, MEDIA_DIR . '/' . $name, 86);
  } else {
    if ($info[2] !== IMAGETYPE_JPEG) fail('Use uma foto em JPG.');
    move_uploaded_file($f['tmp_name'], MEDIA_DIR . '/' . $name);
  }
  return 'midia/' . $name;
}
function dropOldPhoto(string $path): void {
  if (preg_match('#^midia/foto-[\w-]+\.jpg$#', $path) && is_file(__DIR__ . '/' . $path)) @unlink(__DIR__ . '/' . $path);
}

session_name('kcadm');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$a = $_GET['a'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')) {
  $raw = file_get_contents('php://input', false, null, 0, 400000);
  $in = json_decode($raw ?: '[]', true) ?: [];
}

try {
  switch ($a) {
    case 'config': {
      $c = config();
      out(['ok' => true, 'config' => [
        'whatsapp' => $c['whatsapp'], 'valor' => $c['valor'], 'pix' => $c['pix'], 'endereco' => $c['endereco'], 'duracao' => $c['agenda']['duracao'],
        'manutencao' => (bool)$c['manutencao'], 'manutMsg' => $c['manutMsg'], 'foto' => $c['foto'], 'fotoPos' => (int)$c['fotoPos'], 'perguntas' => $c['perguntas'],
      ]]);
    }
    case 'horarios': {
      $from = $_GET['de'] ?? date('Y-m-d'); $to = $_GET['ate'] ?? date('Y-m-d', strtotime('+45 days'));
      if (!validDate($from) || !validDate($to) || $to < $from) fail('Período inválido.');
      if ((strtotime($to) - strtotime($from)) > 92 * 86400) $to = date('Y-m-d', strtotime($from . ' +92 days'));
      $c = config();
      if ($c['manutencao']) out(['ok' => true, 'dias' => [], 'limite' => date('Y-m-d')]);
      out(['ok' => true, 'dias' => freeSlots($from, $to), 'limite' => date('Y-m-d', strtotime('+' . (int)$c['agenda']['janela'] . ' days'))]);
    }
    case 'agendar': {
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
      if (!empty($in['site'])) out(['ok' => true, 'id' => 0]); // honeypot
      $c = config();
      if ($c['manutencao']) fail('O agendamento pelo site está pausado no momento. Chame no WhatsApp.', 503);
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
      // rótulos das perguntas no momento da resposta, para o histórico continuar legível
      if (is_array($in['rotulos'] ?? null)) {
        $rot = [];
        foreach (array_slice($in['rotulos'], 0, 100, true) as $k => $v) if (is_string($k) && preg_match('/^[a-zA-Z]{1,30}$/', $k)) $rot[$k] = str($v, 120);
        $resp['_rotulos'] = $rot;
      }
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
      $rows = db()->query('SELECT * FROM agendamentos ORDER BY criado DESC LIMIT 5000')->fetchAll();
      out(['ok' => true, 'itens' => array_map('row', $rows)]);
    }
    case 'salvar': {
      needAdmin();
      $id = (int)($in['id'] ?? 0);
      $cur = db()->prepare('SELECT * FROM agendamentos WHERE id = ?'); $cur->execute([$id]); $cur = $cur->fetch();
      if (!$cur) fail('Agendamento não encontrado.', 404);
      $status = in_array($in['status'] ?? '', STATUS, true) ? $in['status'] : $cur['status'];
      $data = $in['data'] ?? $cur['data']; $hora = $in['hora'] ?? $cur['hora'];
      if ($data !== '' && $data !== null && !validDate($data)) fail('Data inválida.');
      if ($hora !== '' && $hora !== null && !validTime($hora)) fail('Horário inválido.');
      $nome = array_key_exists('nome', $in) ? clean($in['nome'], 120) : $cur['nome'];
      $wh = array_key_exists('whatsapp', $in) ? clean($in['whatsapp'], 40) : $cur['whatsapp'];
      try {
        db()->prepare('UPDATE agendamentos SET status=?, data=?, hora=?, pago=?, valor=?, notas=?, nome=?, whatsapp=?, atualizado=? WHERE id=?')
          ->execute([$status, $data ?: null, $hora ?: null, !empty($in['pago']) ? 1 : 0, (float)($in['valor'] ?? $cur['valor']), clean($in['notas'] ?? $cur['notas'], 4000), $nome, $wh, date('c'), $id]);
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
      $resp = ['queixas' => array_values(array_filter([clean($in['queixa'] ?? '', 200)]))];
      if (($idade = clean($in['idade'] ?? '', 3)) !== '') $resp['idade'] = $idade;
      try {
        db()->prepare('INSERT INTO agendamentos(criado, data, hora, status, pago, valor, nome, whatsapp, respostas, notas, origem) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
          ->execute([date('c'), $data ?: null, $hora ?: null, $data ? 'confirmado' : 'novo', 0, (float)config()['valor'], $nome, $wh, json_encode($resp, JSON_UNESCAPED_UNICODE), clean($in['notas'] ?? '', 4000), 'manual']);
      } catch (PDOException $e) { fail('Já existe outro agendamento neste dia e horário.', 409); }
      out(['ok' => true]);
    }

    // disponibilidade do mês para o painel: grade, bloqueios e ocupados
    case 'mes': {
      needAdmin();
      $from = $_GET['de'] ?? ''; $to = $_GET['ate'] ?? '';
      if (!validDate($from) || !validDate($to) || $to < $from || (strtotime($to) - strtotime($from)) > 45 * 86400) fail('Período inválido.');
      $ag = config()['agenda']; $busy = bookedIn($from, $to); $dias = [];
      for ($d = $from; $d <= $to; $d = nextDay($d)) {
        $grid = gridSlots($ag, $d); $off = $ag['bloqHoras'][$d] ?? []; $slots = [];
        foreach ($grid as $h) {
          $b = $busy["$d $h"] ?? null;
          $slots[$h] = $b ? ['h' => $h, 'estado' => 'ocupado', 'id' => (int)$b['id'], 'nome' => $b['nome'], 'status' => $b['status']] : ['h' => $h, 'estado' => in_array($h, $off, true) ? 'bloqueado' : 'livre'];
        }
        foreach ($busy as $k => $b) if ($b['data'] === $d && !isset($slots[$b['hora']])) $slots[$b['hora']] = ['h' => $b['hora'], 'estado' => 'ocupado', 'id' => (int)$b['id'], 'nome' => $b['nome'], 'status' => $b['status'], 'extra' => true];
        ksort($slots);
        $dias[$d] = ['fechado' => !$grid, 'bloqueado' => in_array($d, $ag['bloqueios'], true), 'slots' => array_values($slots)];
      }
      out(['ok' => true, 'dias' => $dias]);
    }
    case 'bloquear': {
      needAdmin();
      $d = $in['data'] ?? ''; $h = $in['hora'] ?? ''; $on = !empty($in['on']);
      if (!validDate($d)) fail('Data inválida.');
      $c = config(); $ag = $c['agenda'];
      if ($h === '' || $h === null) {
        $ag['bloqueios'] = array_values(array_diff($ag['bloqueios'], [$d]));
        if ($on) $ag['bloqueios'][] = $d;
        sort($ag['bloqueios']);
      } else {
        if (!validTime($h)) fail('Horário inválido.');
        $list = array_values(array_diff($ag['bloqHoras'][$d] ?? [], [$h]));
        if ($on) $list[] = $h;
        sort($list);
        if ($list) $ag['bloqHoras'][$d] = $list; else unset($ag['bloqHoras'][$d]);
      }
      // limpa bloqueios de datas passadas
      $today = date('Y-m-d');
      $ag['bloqueios'] = array_values(array_filter($ag['bloqueios'], fn($x) => $x >= $today));
      $ag['bloqHoras'] = array_filter($ag['bloqHoras'], fn($k) => $k >= $today, ARRAY_FILTER_USE_KEY);
      $c['agenda'] = $ag;
      saveConfig($c);
      out(['ok' => true]);
    }

    case 'ajustes': {
      needAdmin();
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $c = config(); $n = $in;
        if (array_key_exists('whatsapp', $n)) $c['whatsapp'] = clean($n['whatsapp'], 40);
        if (array_key_exists('valor', $n)) $c['valor'] = max(0, (float)$n['valor']);
        if (array_key_exists('pix', $n)) $c['pix'] = clean($n['pix'], 200);
        if (array_key_exists('endereco', $n)) $c['endereco'] = clean($n['endereco'], 300);
        if (array_key_exists('manutencao', $n)) $c['manutencao'] = !empty($n['manutencao']);
        if (array_key_exists('manutMsg', $n)) $c['manutMsg'] = str($n['manutMsg'], 400);
        if (array_key_exists('fotoPos', $n)) $c['fotoPos'] = min(100, max(0, (int)$n['fotoPos']));
        if (is_array($n['agenda'] ?? null)) {
          $g = $n['agenda']; $ag = $c['agenda'];
          foreach (range(0, 6) as $i) {
            $d = $g['dias'][(string)$i] ?? null; if (!is_array($d)) continue;
            $ini = validTime($d['ini'] ?? '') ? $d['ini'] : '09:00'; $fim = validTime($d['fim'] ?? '') ? $d['fim'] : '19:00';
            $ag['dias'][(string)$i] = ['on' => !empty($d['on']), 'ini' => $ini, 'fim' => $fim];
          }
          if (isset($g['duracao'])) $ag['duracao'] = min(240, max(15, (int)$g['duracao']));
          if (array_key_exists('pausaIni', $g)) $ag['pausaIni'] = validTime($g['pausaIni'] ?? '') ? $g['pausaIni'] : '';
          if (array_key_exists('pausaFim', $g)) $ag['pausaFim'] = validTime($g['pausaFim'] ?? '') ? $g['pausaFim'] : '';
          if (isset($g['antecedencia'])) $ag['antecedencia'] = min(168, max(0, (int)$g['antecedencia']));
          if (isset($g['janela'])) $ag['janela'] = min(180, max(7, (int)$g['janela']));
          $c['agenda'] = $ag;
        }
        $c = saveConfig($c);
      } else $c = config();
      out(['ok' => true, 'config' => $c]);
    }
    case 'perguntas': {
      needAdmin();
      $c = config();
      $c['perguntas'] = ($in['restaurar'] ?? false) ? null : cleanQuestions($in['perguntas'] ?? null);
      $c = saveConfig($c);
      out(['ok' => true, 'perguntas' => $c['perguntas']]);
    }
    case 'foto': {
      needAdmin();
      $c = config();
      $acao = $_POST['acao'] ?? ($in['acao'] ?? 'enviar');
      if ($acao === 'enviar') { $new = saveImage($_FILES['foto'] ?? []); dropOldPhoto($c['foto']); $c['foto'] = $new; $c['fotoPos'] = 20; }
      elseif ($acao === 'remover') { dropOldPhoto($c['foto']); $c['foto'] = 'none'; }
      elseif ($acao === 'original') { dropOldPhoto($c['foto']); $c['foto'] = ''; $c['fotoPos'] = 0; }
      else fail('Ação inválida.');
      $c = saveConfig($c);
      out(['ok' => true, 'foto' => $c['foto'], 'fotoPos' => $c['fotoPos']]);
    }
    case 'senha': {
      needAdmin();
      if (!password_verify((string)($in['atual'] ?? ''), (string)setting('senha'))) fail('Senha atual incorreta.', 401);
      $nova = (string)($in['nova'] ?? '');
      if (strlen($nova) < 4) fail('A nova senha precisa ter pelo menos 4 caracteres.');
      setting('senha', password_hash($nova, PASSWORD_DEFAULT));
      out(['ok' => true]);
    }
    default: fail('Ação desconhecida.', 404);
  }
} catch (Throwable $e) {
  error_log('api.php: ' . $e->getMessage());
  fail('Erro no servidor. Tente de novo.', 500);
}
