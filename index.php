<?php
declare(strict_types=1);

/*
 * Contador de visitantes únicos por IP.
 * O IP não é salvo em texto puro: apenas um hash SHA-256.
 * O arquivo de dados fica em ./data/visits.json.
 */
const VISITS_FILE = __DIR__ . '/data/visits.json';
const VISIT_SALT = 'pokemon-30c-daniel-2026-troque-esta-chave';

function visitorIp(): string
{
  // REMOTE_ADDR é a opção mais segura em hospedagem PHP tradicional.
  // Se houver proxy/CDN configurado por você, adapte aqui apenas para headers confiáveis.
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function readVisits($fp): array
{
  rewind($fp);
  $raw = stream_get_contents($fp);
  $data = $raw ? json_decode($raw, true) : null;
  return is_array($data) ? $data : ['total' => 0, 'ips' => []];
}

function visitsApi(): never
{
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $dir = dirname(VISITS_FILE);
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível criar a pasta data.']);
    exit;
  }

  $fp = fopen(VISITS_FILE, 'c+');
  if (!$fp || !flock($fp, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível acessar o contador.']);
    exit;
  }

  $data = readVisits($fp);
  $data['ips'] = is_array($data['ips'] ?? null) ? $data['ips'] : [];
  $hash = hash('sha256', VISIT_SALT . '|' . visitorIp());

  if (!isset($data['ips'][$hash])) {
    $data['ips'][$hash] = date('c');
    $data['total'] = count($data['ips']);

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    fflush($fp);
  } else {
    $data['total'] = count($data['ips']);
  }

  flock($fp, LOCK_UN);
  fclose($fp);

  echo json_encode(['count' => (int) $data['total']]);
  exit;
}

function pricesApi(): never
{
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $token = getenv('MYPCARDS_API_TOKEN') ?: '';
  if ($token === '') {
    echo json_encode([
      'prices' => [],
      'source' => null,
      'available' => false,
      'message' => 'Fonte MYP Cards indisponível sem chave de API.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
  $requested = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
  $wanted = [];
  foreach ($requested as $card) {
    if (!is_array($card) || !isset($card['n'], $card['collection'])) {
      continue;
    }
    $key = (string)$card['collection'] . ':' . (string)$card['n'];
    $wanted[$key] = ['n' => (string)$card['n'], 'collection' => (string)$card['collection']];
  }

  $prices = [];
  for ($page = 1; $page <= 20 && $wanted; $page++) {
    $ch = curl_init('https://mypcards.com/api/v1/pokemon/precos?page_number=' . $page . '&page_size=50');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Api-Token: ' . $token],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300 || !is_string($body)) {
      http_response_code(502);
      echo json_encode(['error' => 'Não foi possível consultar o MYP Cards.'], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $response = json_decode($body, true);
    $items = is_array($response['cards'] ?? null) ? $response['cards'] : [];
    if (!$items) {
      break;
    }
    foreach ($items as $item) {
      if (!is_array($item) || !in_array(strtoupper((string)($item['edition_code'] ?? '')), ['30C', '30C-C', '30C-R'], true)) {
        continue;
      }
      if (!preg_match('/(?:-|\s)(\d{1,3})$/', strtoupper((string)($item['card_code'] ?? '')), $match)) {
        continue;
      }
      $number = str_pad($match[1], 3, '0', STR_PAD_LEFT);
      foreach ($wanted as $key => $wantedCard) {
        if (str_pad($wantedCard['n'], 3, '0', STR_PAD_LEFT) !== $number) {
          continue;
        }
        $prices[$key] = [
          'min' => $item['min_price'] ?? null,
          'avg' => $item['avg_price'] ?? null,
          'max' => $item['max_price'] ?? null,
          'updated' => date('c'),
        ];
        unset($wanted[$key]);
      }
    }
  }

  echo json_encode(['source' => 'mypcards', 'prices' => $prices], JSON_UNESCAPED_UNICODE);
  exit;
}

function ligaPriceApi(): never
{
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
  $url = is_string($payload['url'] ?? null) ? $payload['url'] : '';
  $parts = $url !== '' ? parse_url($url) : false;
  if ($parts === false || ($parts['host'] ?? '') !== 'www.ligapokemon.com.br' || ($parts['path'] ?? '') !== '/') {
    http_response_code(400);
    echo json_encode(['error' => 'URL da Liga inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_ENCODING => '',
    CURLOPT_REFERER => 'https://www.ligapokemon.com.br/',
    CURLOPT_HTTPHEADER => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
      'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
      'Cache-Control: no-cache',
      'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36',
    ],
  ]);
  $html = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($status < 200 || $status >= 300 || !is_string($html)) {
    echo json_encode(['blocked' => true, 'advertised' => null, 'sold3m' => null], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (stripos($html, 'Just a moment') !== false || stripos($html, 'security verification') !== false || stripos($html, 'cf-chl-') !== false) {
    echo json_encode(['blocked' => true, 'advertised' => null, 'sold3m' => null], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $dom = new DOMDocument();
  @$dom->loadHTML($html);
  $xpath = new DOMXPath($dom);
  $priceBlocks = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' price-mkp ')]");

  $readPrices = static function ($block, DOMXPath $xpath): array {
    $values = [];
    foreach (['min', 'medium', 'max'] as $type) {
      $nodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$type} ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' price ')]", $block);
      $text = $nodes && $nodes->length ? trim($nodes->item(0)->textContent) : '';
      $values[$type] = preg_match('/R\\$\\s*[\\d.]+,\\d{2}/u', $text, $match) ? trim($match[0]) : null;
    }
    return $values;
  };

  $advertised = $priceBlocks && $priceBlocks->length > 0 ? $readPrices($priceBlocks->item(0), $xpath) : null;
  $sold3m = $priceBlocks && $priceBlocks->length > 1 ? $readPrices($priceBlocks->item(1), $xpath) : null;
  echo json_encode(['advertised' => $advertised, 'sold3m' => $sold3m], JSON_UNESCAPED_UNICODE);
  exit;
}

function marketPricesApi(): never
{
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');

  $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
  $requested = is_array($payload['cards'] ?? null) ? $payload['cards'] : [];
  $ch = curl_init('https://deckcerto.com/pokemon-tcg/todas-cartas-celebracao-30-anos/');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_ENCODING => '',
    CURLOPT_REFERER => 'https://deckcerto.com/',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36',
    // Alguns servidores compartilhados não têm a cadeia CA instalada no PHP.
    // O catálogo é público e somente leitura; sem isso o cURL retorna corpo vazio.
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
      'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
      'Cache-Control: no-cache',
    ],
  ]);
  $html = curl_exec($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  $catalog = [];
  if ($status >= 200 && $status < 300 && is_string($html)) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' g-card ') and @data-price]");
    foreach ($nodes as $node) {
      $number = trim($node->getAttribute('data-num'));
      $name = trim($node->getAttribute('data-name'));
      $price = trim($node->getAttribute('data-price'));
      $history = json_decode($node->getAttribute('data-sparkline'), true);
      if ($number === '' || $price === '') {
        continue;
      }
      $catalog[] = [
        'number' => $number,
        'name' => $name,
        'classic' => str_contains($node->getAttribute('data-pset'), 'classica'),
        'rgb' => str_contains(strtolower($node->getAttribute('data-pset')), 'rgb'),
        'price' => 'R$ ' . number_format((float)$price, 2, ',', '.'),
        'history' => is_array($history) ? $history : [],
      ];
    }
  }

  $prices = [];
  foreach ($requested as $card) {
    if (!is_array($card) || !isset($card['n'], $card['collection'])) {
      continue;
    }
    $number = (string)($card['ligaNum'] ?? $card['n']);
    $classic = ($card['collection'] ?? '') === 'classic';
    $rgb = ($card['collection'] ?? '') === 'rgb';
    foreach ($catalog as $item) {
      $itemNumber = preg_match('/^0*(\d+)\//', $item['number'], $numberMatch) ? (int)$numberMatch[1] : null;
      $sameNumber = $rgb
        ? strcasecmp($item['number'], $number) === 0
        : ($itemNumber !== null && $itemNumber === (int)$number);
      if ($item['classic'] !== $classic || ($item['rgb'] ?? false) !== $rgb || !$sameNumber) {
        continue;
      }
      $key = (string)$card['collection'] . ':' . (string)$card['n'];
      $prices[$key] = [
        'source' => 'deckcerto',
        'deckcerto' => [
          'advertised' => ['min' => $item['price'], 'medium' => null, 'max' => null],
          'sold3m' => null,
          'history' => $item['history'],
        ],
      ];
      break;
    }
  }

  echo json_encode(['prices' => $prices, 'source' => 'deckcerto'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (isset($_GET['visits'])) {
  visitsApi();
}
if (isset($_GET['prices'])) {
  pricesApi();
}
if (isset($_GET['liga_price'])) {
  ligaPriceApi();
}
if (isset($_GET['market_prices'])) {
  marketPricesApi();
}

// O HTML contém o catálogo e o código de preços; não deve ficar retido no
// cache do navegador/CDN depois de uma atualização do aplicativo.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$cacheFiles = [
  __FILE__,
  __DIR__ . '/logo.png',
  __DIR__ . '/pwa-icon.svg',
  __DIR__ . '/pattern1Ayellow-2x.webp',
  __DIR__ . '/pattern5A-2x.webp',
  __DIR__ . '/pattern5Ared-2x.webp',
  __DIR__ . '/pattern8B-2x.webp',
  __DIR__ . '/manifest.webmanifest',
  __DIR__ . '/sw.js'
];
$cacheVersion = (string)max(array_map(
  static fn(string $file): int => is_file($file) ? (int)filemtime($file) : 0,
  $cacheFiles
));
?><!doctype html>
<html lang="pt-BR">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="manifest" href="./manifest.php?v=<?= $cacheVersion ?>">
  <link rel="icon" type="image/svg+xml" href="./pwa-icon.svg?v=<?= $cacheVersion ?>">
  <link rel="apple-touch-icon" href="./pwa-icon.svg?v=<?= $cacheVersion ?>">
  <meta name="description" content="Guia 30 Anos Pokémon: cartas, preços atualizados e controle da sua coleção em português do Brasil.">
  <meta name="theme-color" content="#f5c400">
  <meta property="og:type" content="website">
  <meta property="og:locale" content="pt_BR">
  <meta property="og:title" content="Guia 30 Anos Pokémon">
  <meta property="og:description" content="Explore as cartas do Pokémon 30C, veja probabilidades estimadas e acompanhe sua coleção.">
  <meta property="og:image" content="og-image.svg">
  <meta property="og:image:alt" content="Guia 30 Anos Pokémon">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Guia 30 Anos Pokémon">
  <meta name="twitter:description" content="Catálogo, probabilidades estimadas e controle da coleção Pokémon 30C.">
  <meta name="twitter:image" content="og-image.svg">
  <title>Guia 30 Anos Pokémon</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,500,0,0" rel="stylesheet">
  <style>
    :root {
      --gold: #f5c400;
      --yellow: #ffe35b;
      --cream: #fffaf0;
      --ink: #29230f;
      --brown: #735a00;
      --white: #fff;
      --muted: #746c54;
      --line: #eadb9b;
      --shadow: 0 12px 30px rgba(83, 63, 0, .12);
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color: var(--ink);
      background-color: #ecebe3;
      background-image: linear-gradient(rgba(255, 253, 247, .76), rgba(255, 248, 220, .8)), url('./pattern1Ayellow-2x.webp?v=<?= $cacheVersion ?>');
      background-size: auto, 420px;
      background-attachment: fixed
    }

    header {
      background-color: #e52c25;
      background-image: linear-gradient(rgba(255, 249, 235, .88), rgba(255, 225, 205, .84)), url('./pattern5Ared-2x.webp?v=<?= $cacheVersion ?>');
      background-size: auto, 460px;
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 20;
      backdrop-filter: blur(12px)
    }

    .nav {
      max-width: 1280px;
      margin: auto;
      padding: 14px 22px;
      display: flex;
      gap: 14px;
      align-items: center;
      justify-content: space-between
    }

    .brand {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      font-weight: 900;
      letter-spacing: -.5px
    }

    .brand-logo {
      width: 58px;
      height: 42px;
      object-fit: contain;
      flex: 0 0 58px;
      filter: drop-shadow(0 3px 5px rgba(100, 70, 0, .18));
    }

    .pwa-install {
      display: none;
    }

    .pwa-install.visible {
      display: inline-flex;
    }

    .pwa-prompt {
      position: fixed;
      right: 16px;
      bottom: 16px;
      z-index: 80;
      display: none;
      width: min(calc(100vw - 32px), 360px);
      padding: 16px;
      border: 1px solid #e4b600;
      border-radius: 18px;
      background: rgba(255, 253, 242, .97);
      color: var(--ink);
      box-shadow: 0 16px 40px rgba(67, 45, 0, .24);
    }

    .pwa-prompt.visible {
      display: block;
      animation: pwa-prompt-in .22s ease-out both;
    }

    .pwa-prompt strong,
    .pwa-prompt p {
      display: block;
      margin: 0;
    }

    .pwa-prompt p {
      margin-top: 5px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.4;
    }

    .pwa-prompt-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
    }

    .pwa-prompt-actions .btn {
      flex: 1;
      padding: 8px 10px;
      font-size: 12px;
      text-align: center;
    }

    @keyframes pwa-prompt-in {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media(min-width:761px) {
      .pwa-prompt {
        display: none !important;
      }
    }

    .brand span {
      color: #b88b00
    }

    .links {
      display: flex;
      gap: 8px;
      flex-wrap: wrap
    }

    .btn {
      border: 1px solid #d6b52c;
      background: #fff;
      padding: 10px 14px;
      border-radius: 12px;
      color: #5e4900;
      text-decoration: none;
      font-weight: 750;
      cursor: pointer
    }

    .btn.primary {
      background: linear-gradient(135deg, #f7c900, #ffdf4e);
      color: #2b2300;
      border-color: #e4b600
    }

    main {
      max-width: 1280px;
      margin: auto;
      padding: 0 22px 60px
    }

    .hero {
      display: grid;
      grid-template-columns: minmax(260px, .8fr) 1.4fr;
      gap: 28px;
      align-items: center;
      background-color: #fffdf7;
      background-image: linear-gradient(rgba(255, 255, 255, .86), rgba(255, 250, 220, .88)), url('./pattern1Ayellow-2x.webp?v=<?= $cacheVersion ?>');
      background-size: auto, 420px;
      border: 1px solid var(--line);
      border-radius: 26px;
      padding: 28px;
      box-shadow: var(--shadow)
    }

    .hero img {
      width: 100%;
      max-height: 360px;
      object-fit: contain;
      filter: drop-shadow(0 18px 22px rgba(80, 60, 0, .14))
    }

    .kicker {
      display: inline-block;
      background: #fff0a7;
      border: 1px solid #e7c644;
      color: #6a5200;
      border-radius: 999px;
      padding: 6px 10px;
      font-weight: 800;
      font-size: 12px
    }

    h1 {
      font-size: clamp(30px, 5vw, 58px);
      line-height: .98;
      margin: 12px 0;
      letter-spacing: -2px
    }

    .lead {
      font-size: 17px;
      color: var(--muted);
      line-height: 1.55;
      max-width: 760px
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin: 24px 0;
      padding: 12px;
      border-radius: 22px;
      background-color: #e52c25;
      background-image: linear-gradient(rgba(213, 35, 29, .72), rgba(239, 77, 66, .72)), url('./pattern5Ared-2x.webp?v=<?= $cacheVersion ?>');
      background-size: cover, 520px;
    }

    .stat {
      background-color: #fff;
      background-image: linear-gradient(rgba(255, 255, 255, .9), rgba(255, 255, 255, .86)), url('./pattern8B-2x.webp?v=<?= $cacheVersion ?>');
      background-size: auto, 300px;
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 18px;
      box-shadow: var(--shadow)
    }

    .stat b {
      display: block;
      font-size: 25px;
      color: #9b7600
    }

    .stat small {
      color: var(--muted)
    }

    section {
      margin-top: 15px
    }

    h2 {
      font-size: 28px;
      margin: 0 0 14px
    }

    .rate-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px
    }

    .rate {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 18px;
      position: relative;
      overflow: hidden
    }

    .rate:before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 5px;
      background: var(--gold)
    }

    .rate strong {
      font-size: 17px
    }

    .rate .big {
      font-size: 27px;
      font-weight: 900;
      color: #9b7600;
      margin: 8px 0 2px
    }

    .rate small {
      color: var(--muted)
    }

    .note {
      margin-top: 12px;
      background: #fff9d9;
      border: 1px solid #ead06a;
      padding: 14px 16px;
      border-radius: 14px;
      color: #64520d;
      line-height: 1.45
    }

    .toolbar {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin: 14px 0 18px;
      align-items: center
    }

    .toolbar-field {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 800
    }

    .toolbar-field select {
      min-height: 38px
    }

    .category-nav {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 14px
    }

    .category-nav a {
      padding: 7px 11px;
      border: 1px solid var(--line);
      border-radius: 999px;
      color: var(--brown);
      background: var(--white);
      font-size: 12px;
      font-weight: 800;
      text-decoration: none
    }

    .category-nav a:hover,
    .category-nav a:focus-visible {
      border-color: var(--gold);
      background: #fff8d1
    }

    input,
    select {
      padding: 11px 12px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      font: inherit;
      min-width: 190px
    }

    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
      gap: 16px
    }

    .card {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 7px 18px rgba(88, 65, 0, .08);
      transition: .18s transform, .18s box-shadow
    }

    .card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow)
    }

    .card .pic {
      position: relative;
      aspect-ratio: 2.5/3.5;
      background: linear-gradient(135deg, #fff9d4, #f1d66a);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 9px
    }

    .card img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      border-radius: 8px
    }

    .card .body {
      padding: 13px;
      background-color: #fff;
      background-image: linear-gradient(rgba(255, 255, 255, .92), rgba(255, 255, 255, .9)), url('./pattern8B-2x.webp?v=<?= $cacheVersion ?>');
      background-size: auto, 280px
    }

    .num {
      font-size: 12px;
      font-weight: 900;
      color: #9a7500
    }

    .title {
      font-weight: 850;
      margin: 4px 0 8px
    }

    .badge {
      display: inline-block;
      font-size: 11px;
      background: #fff2ad;
      border: 1px solid #ead16a;
      padding: 4px 7px;
      border-radius: 999px;
      color: #665000
    }

    .prob {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      margin-top: 10px;
      font-size: 12px;
      color: var(--muted)
    }

    .card-actions {
      display: flex;
      gap: 7px;
      margin-top: 11px
    }

    .card-actions a {
      font-size: 12px;
      flex: 1;
      text-align: center;
    }
    .card-actions .btn { flex: 1; text-align: center; font-size: 12px; padding-left: 8px; padding-right: 8px; }
    .price-action { background: #fff3c4; color: #795400; }
    .material-symbols-rounded { font-family: 'Material Symbols Rounded'; font-size: 17px; line-height: 1; vertical-align: -4px; margin-right: 4px; }
    .card-actions .btn:first-child, .card-actions .price-action { font-size: 0; }
    .card-actions .btn:first-child::before, .card-actions .price-action::before { font-family: 'Material Symbols Rounded'; font-size: 17px; line-height: 1; vertical-align: -4px; margin-right: 4px; }
    .card-actions .btn:first-child::after { content: ''; font-size: 12px; }
    .card-actions .price-action::after { content: ''; font-size: 12px; }
    .card-actions .btn:first-child::before { content: 'image'; }
    .card-actions .price-action::before { content: 'monitoring'; }

    .market-prices {
      display: grid;
      gap: 6px;
      margin-top: 11px;
      padding-top: 10px;
      border-top: 1px solid var(--line);
    }

    .market-price {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      color: var(--muted);
      font-size: 11px;
    }

    .market-price strong {
      color: #8a6700;
      font-weight: 900;
      text-decoration: none;
      white-space: nowrap;
    }

    .card-loading {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: rgba(255, 249, 212, .82);
      color: #806400;
      font-size: 11px;
      font-weight: 900;
      z-index: 1;
    }

    .card-loading .pokeball-icon {
      animation: card-pokeball-spin 1s linear infinite;
    }

    @keyframes card-pokeball-spin {
      to { transform: rotate(360deg); }
    }

    footer {
      margin-top: 48px;
      border-top: 1px solid var(--line);
      padding-top: 18px;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
      padding: 24px;
      border-radius: 22px 22px 0 0;
      background-color: #e52c25;
      background-image: linear-gradient(rgba(255, 249, 235, .88), rgba(255, 225, 205, .84)), url('./pattern5Ared-2x.webp');
      background-size: auto, 460px;
    }

    .hidden {
      display: none !important
    }

    @media(max-width:760px) {
      header {
        background-color: #e52c25;
        background-image: linear-gradient(rgba(255, 249, 235, .88), rgba(255, 225, 205, .84)), url('./pattern5Ared-2x.webp?v=<?= $cacheVersion ?>');
        background-size: auto, 460px;
      }

      footer {
        background-color: #e52c25;
        background-image: linear-gradient(rgba(255, 249, 235, .88), rgba(255, 225, 205, .84)), url('./pattern5Ared-2x.webp?v=<?= $cacheVersion ?>');
        background-size: auto, 460px;
      }

      .hero {
        grid-template-columns: 1fr
      }

      .stats {
        grid-template-columns: 1fr 1fr
      }

      .cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
      }

      .nav {
        align-items: flex-start;
        flex-direction: column
      }
    }

    .modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background: rgba(20, 16, 2, .82);
      backdrop-filter: blur(12px)
    }

    .modal.open {
      display: flex
    }

    .modal-panel {
      position: relative;
      width: min(94vw, 760px);
      height: min(92vh, 900px);
      display: flex;
      flex-direction: column;
      gap: 12px;
      align-items: center;
      justify-content: flex-start;
      overflow-y: auto;
      padding: 28px 0 10px;
      perspective: 1400px;
      overflow: visible;
    }

    .modal-close {
      position: absolute;
      right: 0;
      top: 0;
      z-index: 4;
      width: 44px;
      height: 44px;
      border: 1px solid rgba(255, 255, 255, .35);
      border-radius: 50%;
      background: rgba(255, 255, 255, .14);
      color: #fff;
      font-size: 25px;
      cursor: pointer;
      backdrop-filter: blur(8px)
    }

    .modal-stage {
      width: min(72vw, 520px);
      transform-style: preserve-3d;
      transition: transform .12s ease-out;
      will-change: transform
    }

    .modal-card3d {
      position: relative;
      transform-style: preserve-3d;
      border-radius: 22px;
      filter: drop-shadow(0 35px 45px rgba(0, 0, 0, .45))
    }

    .modal-card3d img {
      display: block;
      width: 100%;
      height: auto;
      border-radius: 20px;
      box-shadow: 0 0 0 1px rgba(255, 255, 255, .3);
      transform: translateZ(25px);
      user-select: none;
      -webkit-user-drag: none;
      -webkit-touch-callout: none
    }

    .modal-shine {
      position: absolute;
      inset: 0;
      border-radius: 20px;
      pointer-events: none;
      transform: translateZ(30px);
      mix-blend-mode: screen;
      opacity: .5;
      background: radial-gradient(circle at var(--mx, 50%) var(--my, 50%), rgba(255, 255, 255, .85), rgba(255, 223, 80, .18) 18%, transparent 46%)
    }

    .modal-info {
      position: static;
      transform: none;
      width: max-content;
      max-width: 90%;
      padding: 9px 14px;
      border-radius: 999px;
      background: rgba(15, 12, 0, .72);
      border: 1px solid rgba(255, 255, 255, .2);
      color: #fff;
      text-align: center;
      font-size: 13px;
      backdrop-filter: blur(10px)
    }

    .modal-hint {
      opacity: .72;
      margin-left: 7px
    }

    .modal-download {
      position: static;
      z-index: 5;
      transform: none;
      white-space: nowrap;
    }

    .modal-history {
      width: min(94vw, 680px);
      padding: 14px 16px 12px;
      border-radius: 16px;
      background: rgba(255, 255, 255, .94);
      color: #332600;
      box-shadow: 0 10px 28px rgba(0, 0, 0, .22);
    }
    .modal-history-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .modal-history-head small { color: #a1a8b7; font-weight: 900; }
    .modal-history-change { font-weight: 900; }
    .modal-history-change.up { color: #148542; }
    .modal-history-change.down { color: #df3030; }
    .modal-history-tabs { display: flex; gap: 8px; margin: 10px 0 5px; }
    .modal-history-tabs button { border: 0; border-radius: 9px; padding: 6px 12px; background: #eef0f5; color: #657085; font-weight: 900; cursor: pointer; }
    .modal-history-tabs button.active { background: #7a00c8; color: #fff; }
    .modal-history-plot { position: relative; width: 100%; }
    .modal-history-chart { width: 100%; height: 170px; display: block; overflow: visible; }
    .modal-history-tooltip { display: none; position: absolute; z-index: 3; min-width: 96px; padding: 7px 9px; border-radius: 8px; background: #11101d; color: #c783ff; font-size: 12px; font-weight: 900; box-shadow: 0 8px 18px rgba(0,0,0,.22); pointer-events: none; }
    .modal-history-tooltip small { display: block; color: #c8c9d2; font-weight: 600; }
    .modal-history-empty { margin: 8px 0 2px; color: #777; font-size: 13px; }
    .modal-history-meta { display: flex; justify-content: space-between; color: #9aa1af; font-size: 11px; }

    @media(max-width:650px) {
      .modal-stage {
        width: min(82vw, 440px)
      }

      .modal-info {
        font-size: 11px
      }

      .modal-hint {
        display: none
      }
    }


    .owned-toggle {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-top: 11px;
      padding: 9px 10px;
      border: 1px solid var(--line);
      border-radius: 11px;
      background: #fffdf4;
      font-size: 12px;
      font-weight: 800;
      color: #675000;
      cursor: pointer
    }

    .owned-toggle input {
      min-width: auto;
      width: 17px;
      height: 17px;
      accent-color: #d8a900
    }

    .card.owned {
      border-color: #2a9d55;
      outline: 3px solid rgba(42, 157, 85, .28);
      background: linear-gradient(180deg, #fff, #fffbea)
    }

    .card.owned .pic {
      position: relative
    }

    .card.owned .pic:after {
      content: "✓ NA COLEÇÃO";
      position: absolute;
      right: 8px;
      top: 8px;
      background: #f5c400;
      color: #302600;
      border-radius: 999px;
      padding: 6px 8px;
      font-size: 10px;
      font-weight: 950;
      box-shadow: 0 4px 12px rgba(70, 50, 0, .2)
    }

    .collection-progress {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin: 0 0 14px
    }

    .progress-track {
      height: 10px;
      min-width: 180px;
      flex: 1;
      max-width: 360px;
      background: #f2e8bb;
      border-radius: 999px;
      overflow: hidden
    }

    .progress-fill {
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, #e7b800, #ffe36a);
      transition: width .25s ease
    }

    .progress-text {
      font-size: 13px;
      font-weight: 850;
      color: #755b00
    }


    /* Tema escuro */
    body.dark {
      --cream: #11100b;
      --ink: #f7f0cf;
      --brown: #e5c758;
      --white: #1b1912;
      --muted: #c0b792;
      --line: #4b4221;
      --shadow: 0 12px 30px rgba(0, 0, 0, .32);
      background: radial-gradient(circle at 85% 0, #4b3d08 0, transparent 28%), linear-gradient(180deg, #0d0c09, #17140b 55%, #0d0c09);
      color: var(--ink)
    }

    body.dark header {
      background: linear-gradient(135deg, rgba(22, 20, 13, .96), rgba(45, 38, 11, .94));
      border-color: var(--line)
    }

    body.dark .hero,
    body.dark .stat,
    body.dark .rate,
    body.dark .card {
      background: rgba(28, 26, 18, .96);
      border-color: var(--line)
    }

    body.dark .btn {
      background: #242117;
      color: #f5d85e;
      border-color: #75621d
    }

    body.dark .btn.primary {
      background: linear-gradient(135deg, #d5a900, #f4d24b);
      color: #211b00;
      border-color: #e4b600
    }

    body.dark input,
    body.dark select {
      background: #211f17;
      color: #f7f0cf;
      border-color: #514823
    }

    body.dark .note {
      background: #28230f;
      border-color: #6d5a18;
      color: #e9dc9d
    }

    body.dark .owned-toggle {
      background: #242117;
      border-color: #514823;
      color: #f3d85d
    }

    body.dark .category-nav a {
      background: #211f17;
      border-color: #514823;
      color: #f3d85d
    }

    body.dark .card.owned {
      border-color: #72d68a;
      outline: 3px solid rgba(114, 214, 138, .25);
      background: linear-gradient(180deg, #252218, #30280d)
    }

    body.dark .progress-track {
      background: #39331b
    }

    body.dark .pic {
      background: linear-gradient(135deg, #2a2616, #5a4910)
    }

    body.dark footer {
      border-color: var(--line)
    }

    .theme-toggle {
      display: inline-flex;
      align-items: center;
      gap: 7px
    }


    .copyright {
      margin-top: 18px;
      padding-top: 14px;
      border-top: 1px solid var(--line);
      text-align: center;
      font-weight: 700
    }

    .copyright a {
      color: #9b7600;
      text-decoration: none
    }

    .copyright a:hover {
      text-decoration: underline
    }

    body.dark .copyright a {
      color: #f5d85e
    }


    .card .pic[role="button"] {
      cursor: pointer;
      transition: transform .18s ease, filter .18s ease
    }

    .card .pic[role="button"]:hover {
      transform: scale(1.018);
      filter: brightness(1.04)
    }

    .card .pic[role="button"]:focus-visible {
      outline: 3px solid var(--gold);
      outline-offset: -3px
    }


    .export-modal {
      position: fixed;
      inset: 0;
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background: rgba(20, 16, 2, .82);
      backdrop-filter: blur(12px)
    }

    .export-modal.open {
      display: flex
    }

    .export-panel {
      width: min(94vw, 560px);
      background: var(--white);
      color: var(--ink);
      border: 1px solid var(--line);
      border-radius: 24px;
      padding: 24px;
      box-shadow: 0 28px 70px rgba(0, 0, 0, .38)
    }

    .export-head {
      display: flex;
      align-items: center;
      justify-content: space-between
    }

    .export-head h3 {
      margin: 0;
      font-size: 25px
    }

    .export-close {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: 1px solid var(--line);
      background: transparent;
      color: var(--ink);
      font-size: 23px;
      cursor: pointer
    }

    .export-options {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 11px;
      margin-top: 18px
    }

    .export-option {
      padding: 16px 13px;
      border: 1px solid var(--line);
      border-radius: 15px;
      background: var(--white);
      color: var(--ink);
      font: inherit;
      font-weight: 850;
      cursor: pointer;
      text-align: left
    }

    .export-option span {
      display: block;
      font-size: 11px;
      color: var(--muted);
      font-weight: 600;
      margin-top: 5px
    }

    .export-summary {
      margin-top: 16px;
      padding: 12px;
      border-radius: 12px;
      background: #fff8d8;
      border: 1px solid var(--line);
      font-size: 13px;
      color: #675000
    }

    body.dark .export-summary {
      background: #28230f;
      color: #e9dc9d
    }

    @media(max-width:520px) {
      .export-options {
        grid-template-columns: 1fr
      }
    }

    @media print {
      body>* {
        display: none !important
      }

      #printArea {
        display: block !important;
        font-family: Arial;
        color: #111;
        padding: 18px
      }

      #printArea * {
        display: revert !important
      }

      #printArea table {
        width: 100%;
        border-collapse: collapse
      }

      #printArea th,
      #printArea td {
        border: 1px solid #bbb;
        padding: 7px;
        font-size: 11px
      }
    }

    #proxyPrintArea {
      display: none
    }

    @media print {
      body.proxy-print>* {
        display: none !important
      }

      body.proxy-print #proxyPrintArea {
        display: block !important
      }

      body.proxy-print #printArea {
        display: none !important
      }

      #proxyPrintArea {
        padding: 0;
        margin: 0;
        color: #111;
        background: #fff
      }

      .proxy-page {
        width: 210mm;
        min-height: 297mm;
        padding: 10mm 7.5mm;
        display: grid !important;
        grid-template-columns: repeat(3, 63mm);
        grid-auto-rows: 88mm;
        gap: 4mm 4mm;
        align-content: start;
        page-break-after: always
      }

      .proxy-page:last-child {
        page-break-after: auto
      }

      .proxy-card {
        width: 63mm;
        height: 88mm;
        border: .25mm dashed #777;
        position: relative;
        overflow: hidden;
        background: #fff;
        display: block !important;
        break-inside: avoid
      }

      .proxy-card img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block !important
      }

      .proxy-label {
        position: absolute;
        left: 1.5mm;
        right: 1.5mm;
        bottom: 1.5mm;
        padding: 1mm 1.4mm;
        background: rgba(255, 255, 255, .88);
        border-radius: 1mm;
        font: 700 7pt Arial;
        text-align: center;
        display: block !important
      }

      .proxy-fallback {
        height: 100%;
        display: flex !important;
        align-items: center;
        justify-content: center;
        text-align: center;
        font: 700 10pt Arial;
        padding: 5mm
      }

      @page {
        size: A4 portrait;
        margin: 0
      }
    }


    .display-options {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin: 0 0 14px
    }

    .display-options>span {
      font-size: 13px;
      font-weight: 850;
      color: var(--muted);
      margin-right: 2px
    }

    .display-check {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 10px;
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--white);
      font-size: 12px;
      font-weight: 800;
      cursor: pointer
    }

    .display-check input {
      width: 15px;
      height: 15px;
      min-width: auto;
      accent-color: #d8a900
    }


    .prob-accordion {
      margin: 18px 0;
      border: 1px solid var(--line);
      border-radius: 16px;
      background: var(--white);
      overflow: hidden
    }

    .prob-accordion summary {
      list-style: none;
      cursor: pointer;
      padding: 16px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 900;
      user-select: none
    }

    .prob-accordion summary::-webkit-details-marker {
      display: none
    }

    .prob-accordion summary::after {
      content: "⌄";
      font-size: 22px;
      line-height: 1;
      margin-left: 4px;
      transition: transform .2s ease
    }

    .prob-accordion[open] summary::after {
      transform: rotate(180deg)
    }

    .prob-accordion .accordion-hint {
      margin-left: auto;
      color: var(--muted);
      font-size: 11px;
      font-weight: 700
    }

    .prob-accordion[open] .accordion-hint {
      font-size: 0
    }

    .prob-accordion[open] .accordion-hint::after {
      content: "Ocultar detalhes";
      font-size: 11px
    }

    .prob-accordion-content {
      padding: 0 18px 18px;
      border-top: 1px solid var(--line)
    }

    .prob-accordion-content>section {
      margin: 0;
      padding-top: 16px;
      border: 0;
      box-shadow: none;
      background: transparent
    }


    .export-display-settings {
      margin: 14px 0 16px;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: var(--soft);
    }

    .export-display-title {
      font-size: 14px;
      font-weight: 900;
      margin-bottom: 3px
    }

    .export-display-note {
      font-size: 11px;
      color: var(--muted);
      margin: 0 0 10px
    }

    .modal-display-options {
      margin: 0
    }


    .visit-counter {
      visibility: visible !important;
      opacity: 1 !important;
      position: fixed;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      z-index: 9999;
      display: flex;
      align-items: center;
      gap: 9px;
      padding: 10px 13px 10px 10px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--white);
      box-shadow: 0 10px 30px rgba(0, 0, 0, .16)
    }

    .visit-counter.hidden {
      display: none
    }

    .visit-copy {
      display: flex;
      flex-direction: column
    }

    .visit-copy small {
      font-size: 9px;
      color: var(--muted);
      font-weight: 800;
      text-transform: uppercase
    }

    .visit-copy strong {
      font-size: 16px
    }

    .visit-hide {
      position: absolute;
      right: -7px;
      top: -8px;
      width: 21px;
      height: 21px;
      border: 1px solid var(--line);
      border-radius: 50%;
      background: var(--white);
      cursor: pointer
    }

    .visit-show {
      position: fixed;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      z-index: 9998;
      width: 34px;
      height: 48px;
      border: 1px solid var(--line);
      border-left: 0;
      border-radius: 0 16px 16px 0;
      background: var(--white);
      cursor: pointer;
      display: none;
      align-items: center;
      justify-content: center
    }

    .visit-show.visible {
      display: flex
    }

    .pokeball-icon {
      width: 32px;
      height: 32px;
      border: 2px solid #171717;
      border-radius: 50%;
      position: relative;
      background: linear-gradient(to bottom, #e53935 0 43%, #171717 43% 57%, #fff 57% 100%)
    }

    .pokeball-icon:before {
      content: "";
      position: absolute;
      width: 10px;
      height: 10px;
      background: #fff;
      border: 2px solid #171717;
      border-radius: 50%;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%)
    }

    .pokeball-icon.mini {
      width: 24px;
      height: 24px
    }

    .pokeball-icon.mini:before {
      width: 7px;
      height: 7px
    }


    #visitCounter {
      display: flex !important
    }

    #visitCounter.hidden {
      display: none !important
    }

    #visitShow {
      visibility: visible
    }


    /* contador lateral v3 */
    #visitCounter.visit-counter {
      position: fixed !important;
      left: 14px !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      z-index: 2147483000 !important;
      display: flex !important;
      visibility: visible !important;
      opacity: 1 !important;
      align-items: center !important;
      gap: 10px !important;
      min-width: 118px !important;
      height: auto !important;
      padding: 10px 14px 10px 10px !important;
      background: #fff !important;
      color: #1c1809 !important;
      border: 2px solid #e2b400 !important;
      border-radius: 18px !important;
      box-shadow: 0 8px 28px rgba(0, 0, 0, .22) !important;
    }

    #visitCounter.visit-counter.hidden {
      display: none !important
    }

    #visitCounter .visit-copy {
      display: flex !important;
      flex-direction: column !important;
      min-width: 54px !important
    }

    #visitCounter .visit-copy small {
      display: block !important;
      color: #806300 !important;
      font: 800 9px/1.1 Arial, sans-serif !important;
      letter-spacing: .07em !important
    }

    #visitCounter .visit-copy strong {
      display: block !important;
      color: #17130a !important;
      font: 900 19px/1.15 Arial, sans-serif !important;
      margin-top: 3px !important
    }

    #visitCounter .visit-hide {
      display: block !important;
      position: absolute !important;
      right: -8px !important;
      top: -9px !important;
      width: 23px !important;
      height: 23px !important;
      padding: 0 !important;
      background: #fff !important;
      color: #333 !important;
      border: 1px solid #d8c36d !important;
      border-radius: 50% !important;
      cursor: pointer !important
    }

    #visitCounter .pokeball-icon,
    #visitShow .pokeball-icon {
      display: block !important;
      flex: 0 0 auto !important;
      width: 34px !important;
      height: 34px !important;
      border: 2px solid #161616 !important;
      border-radius: 50% !important;
      position: relative !important;
      background: linear-gradient(to bottom, #e53935 0 43%, #161616 43% 57%, #fff 57% 100%) !important;
    }

    #visitCounter .pokeball-icon:before,
    #visitShow .pokeball-icon:before {
      content: "" !important;
      position: absolute !important;
      width: 10px !important;
      height: 10px !important;
      left: 50% !important;
      top: 50% !important;
      transform: translate(-50%, -50%) !important;
      background: #fff !important;
      border: 2px solid #161616 !important;
      border-radius: 50% !important;
    }

    #visitShow.visit-show {
      position: fixed !important;
      left: 0 !important;
      top: 50% !important;
      transform: translateY(-50%) !important;
      z-index: 2147482999 !important;
      width: 42px !important;
      height: 54px !important;
      padding: 0 !important;
      background: #fff !important;
      border: 2px solid #e2b400 !important;
      border-left: 0 !important;
      border-radius: 0 18px 18px 0 !important;
      align-items: center !important;
      justify-content: center !important;
      cursor: pointer !important;
    }

    #visitShow.visit-show:not(.visible) {
      display: none !important
    }

    #visitShow.visit-show.visible {
      display: flex !important
    }

    #visitShow .pokeball-icon {
      width: 27px !important;
      height: 27px !important
    }

    @media(max-width:700px) {
      #visitCounter.visit-counter {
        left: 8px !important;
        min-width: 108px !important
      }
    }


    /* contador entre o primeiro e o segundo bloco */
    #visitCounter.visit-counter {
      position: relative !important;
      left: auto !important;
      top: auto !important;
      transform: none !important;
      z-index: 10 !important;
      display: flex !important;
      visibility: visible !important;
      opacity: 1 !important;
      width: max-content !important;
      min-width: 150px !important;
      margin: 14px auto !important;
      padding: 9px 16px 9px 10px !important;
      align-items: center !important;
      gap: 10px !important;
      background: var(--white) !important;
      color: var(--text) !important;
      border: 1px solid var(--line) !important;
      border-radius: 999px !important;
      box-shadow: 0 7px 22px rgba(0, 0, 0, .08) !important;
    }

    #visitCounter.visit-counter.hidden {
      display: none !important
    }

    #visitCounter .visit-hide {
      right: -7px !important;
      top: -7px !important
    }

    #visitShow {
      display: none !important
    }

    /* Contadores no layout: um no topo e outro no rodapé. */
    .visit-counter--top,
    .visit-counter--footer {
      position: relative !important;
      left: auto !important;
      top: auto !important;
      transform: none !important;
      z-index: 10 !important;
      display: flex !important;
      visibility: visible !important;
      opacity: 1 !important;
      width: max-content !important;
      min-width: 150px !important;
      margin: 14px auto !important;
      padding: 9px 16px 9px 10px !important;
      align-items: center !important;
      gap: 10px !important;
      background: var(--white) !important;
      color: var(--ink) !important;
      border: 1px solid var(--line) !important;
      border-radius: 999px !important;
      box-shadow: 0 7px 22px rgba(0, 0, 0, .08) !important;
    }

    .visit-counter--footer {
      margin: 18px auto 4px !important;
    }

    .visit-counter--top .visit-hide,
    .visit-counter--footer .visit-hide {
      display: none !important;
    }

    @media(max-width:700px) {
      .visit-counter--top,
      .visit-counter--footer {
        min-width: 132px !important;
      }
    }
  </style>
</head>

<body>
  <header>
    <div class="nav">
      <img class="brand-logo" src="./logo2.png?v=20260924-2" alt="Pokémon 30 anos">
      <div class="brand">⚡ Guia 30 Anos <span>Pokémon</span> · PT-BR</div>
      <div class="links">
        <button class="btn pwa-install" id="installApp" type="button">Instalar app</button>
        <a class="btn primary" href="https://www.ligapokemon.com.br/?view=cards/search&card=edid=804%20ed=30C"
          target="_blank" rel="noopener">Liga Pokémon</a>
        <a class="btn" href="#cartas">Explorar cartas</a>
        <button class="btn theme-toggle" id="themeToggle" type="button" aria-label="Alternar tema">🌙</button>
      </div>
    </div>
  </header>

  <aside class="pwa-prompt" id="pwaPrompt" aria-live="polite" aria-label="Instalar aplicativo">
    <strong>Instale o Guia 30 Anos Pokémon</strong>
    <p>Acesse sua coleção rapidamente pelo celular, como um aplicativo.</p>
    <div class="pwa-prompt-actions">
      <button class="btn" id="dismissPwaPrompt" type="button">Agora não</button>
      <button class="btn primary" id="installPwaPrompt" type="button">Instalar agora</button>
    </div>
  </aside>

  <aside class="visit-counter visit-counter--top" aria-label="Contador de visitantes no topo">
    <div class="pokeball-icon" aria-hidden="true"></div>
    <div class="visit-copy">
      <small>VISITANTES</small>
      <strong data-visit-count>...</strong>
    </div>
  </aside>

  <main>
    <section class="hero">
      <div><img
          src="logo.png?v=<?= $cacheVersion ?>"
          alt="Coleção Treinador Avançado Celebração de 30 Anos"></div>
      <div>
        <span class="kicker">CELEBRAÇÃO DE 30 ANOS · 30C</span>
        <h1>Chances de tirar cada tipo de carta</h1>
        <p class="lead">Painel visual para acompanhar a coleção PT-BR, comparar probabilidades estimadas e abrir
          rapidamente a carta na Liga Pokémon ou no banco de imagens.</p>
        <div class="links"><a class="btn primary" href="#chances">Ver probabilidades</a><a class="btn"
            href="#cartas">Catálogo 001–161</a></div>
      </div>
    </section>


    <div class="stats">
      <div class="stat"><b>5 + 1</b><small>5 cartas foil + 1 Energia foil por booster</small></div>
      <div class="stat"><b>100%</b><small>1 dos 30 Pikachu em cada booster</small></div>
      <div class="stat"><b>30</b><small>Pikachus comemorativos diferentes</small></div>
      <div class="stat"><b>199</b><small>cartas no catálogo ampliado*</small></div>
    </div>

    <details class="prob-accordion" id="chances">
      <summary><span>🎲 Probabilidades estimadas</span><small class="accordion-hint">Mostrar detalhes</small></summary>
      <div class="prob-accordion-content">
        <section>
          <div class="rate-grid">
            <div class="rate"><strong>⚡ Pikachu 30 anos</strong>
              <div class="big">100%</div><small>qualquer Pikachu · 1 por booster</small>
              <p><b>Específico:</b> ≈ 3,33% · ≈ 1 em 30</p>
            </div>
            <div class="rate"><strong>◆ Double Rare / Pokémon ex</strong>
              <div class="big">≈ 25%</div><small>≈ 1 em 4 boosters</small>
              <p><b>Específica (12):</b> ≈ 2,08% · ≈ 1 em 48</p>
            </div>
            <div class="rate"><strong>🎨 Illustration Rare</strong>
              <div class="big">≈ 19,2%</div><small>≈ 1 em 5,2 boosters</small>
              <p><b>Específica (18):</b> ≈ 1,07% · ≈ 1 em 94</p>
            </div>
            <div class="rate"><strong>🏛️ Classic Collection</strong>
              <div class="big">≈ 10,2%</div><small>≈ 1 em 9,8 boosters</small>
              <p><b>Específica (30):</b> ≈ 0,34% · ≈ 1 em 294</p>
            </div>
            <div class="rate"><strong>🌟 Special Illustration Rare</strong>
              <div class="big">≈ 5,6%</div><small>≈ 1 em 18 boosters</small>
              <p><b>Específica (10):</b> ≈ 0,56% · ≈ 1 em 180</p>
            </div>
            <div class="rate"><strong>🔮 Futuristic Rare</strong>
              <div class="big">≈ 1,0%</div><small>≈ 1 em 99 boosters</small>
              <p><b>Específica (2):</b> ≈ 0,51% · ≈ 1 em 198</p>
            </div>
            <div class="rate"><strong>🌈 RGB Secret Rare</strong>
              <div class="big">?</div><small>raríssima; amostra ainda insuficiente</small>
              <p>Não há taxa oficial confiável para cravar uma chance individual.</p>
            </div>
          </div>
          <div class="note"><b>Importante:</b> as taxas acima são estimativas de aberturas comunitárias, não odds
            oficiais da Pokémon. O que é oficial: cada booster traz cinco cartas laminadas, incluindo um dos 30 Pikachu,
            mais uma Energia Básica laminada. Para os 30 Pikachu, 3,33% por arte pressupõe distribuição uniforme.</div>
        </section>
      </div>
    </details>

    <section id="cartas" aria-labelledby="cartas-title">
      <h2 id="cartas-title">Galeria do set numerado</h2>

      <nav class="category-nav" aria-label="Categorias de cartas">
        <a href="#categoria-todas" data-category="all">Todas</a>
        <a href="#categoria-pikachu" data-category="pikachu">Pikachu</a>
        <a href="#categoria-colecao" data-category="owned">Na coleção</a>
        <a href="#categoria-faltantes" data-category="missing">Faltantes</a>
      </nav>

      <div class="collection-progress">
        <span class="progress-text" id="collectionProgress">0/191 na coleção</span>
        <span class="progress-text" id="collectionValue">Valor da coleção: R$ 0,00</span>
        <div class="progress-track">
          <div class="progress-fill" id="progressFill"></div>
        </div>
        <button class="btn" id="clearOwned" type="button">Limpar seleção</button>
        <span class="progress-text" id="ligaPriceStatus" role="status" aria-live="polite"></span>
        <span class="progress-text" id="languageStatus" role="status" aria-live="polite"></span>
        <button class="btn" id="exportCollection" type="button">⇩ Exportar coleção</button>
        <button class="btn" id="importCollection" type="button">⇧ Importar coleção</button>
        <input id="collectionFile" type="file" accept="application/json,.json" hidden>
        <button class="btn primary" id="openExport" type="button">⇩ Exportar faltantes</button>
      </div>
      <div class="toolbar">
        <input id="q" placeholder="Buscar nº ou Pikachu…" autocomplete="off">
        <label class="toolbar-field">Idioma das cartas
          <select id="cardLanguage" aria-label="Idioma das imagens das cartas">
            <option value="pt">Português</option>
            <option value="en">English</option>
            <option value="jp">日本語 (Japonês)</option>
          </select>
        </label>
        <select id="filter">
          <option value="all">Todas as cartas</option>
          <option value="owned">Somente cartas que já tirei</option>
          <option value="missing">Somente cartas que faltam</option>
          <option value="pikachu">Somente Pikachu 023–052</option>
          <option value="owned-pikachu">Pikachu que já tirei</option>
          <option value="ex">Somente Pokémon ex</option>
          <option value="classic">Somente Classic Collection</option>
          <option value="rgb">Somente RGB Cards</option>
        </select>
        <select id="sortOrder" aria-label="Ordenar cartas por preço">
          <option value="default">Ordenação padrão</option>
          <option value="price-desc">Mais caras primeiro</option>
          <option value="price-asc">Mais baratas primeiro</option>
        </select>
        <span id="count" style="color:var(--muted);font-size:13px"></span>
      </div>
      <div class="cards" id="grid"></div>
    </section>

    <footer>
      <p><b>Fontes e escopo:</b> composição do booster e 30 Pikachu: Pokémon oficial. Estrutura de 199 cartas e lista
        ampliada: referências públicas do set 30C. Imagens do catálogo numerado são carregadas do CDN público do
        Limitless TCG; se alguma imagem PT-BR ainda não estiver disponível, o card exibe um fallback. O botão da Liga
        aponta para a busca 30C fornecida por você.</p>
      <p>* O set completo tem 199 cartas oficiais: 128 do set base, 33 secretas, 30 da Classic Collection e 8 Energias.
        A galeria abaixo cobre as 158 cartas numeradas disponíveis e também inclui as 3 cartas RGB descobertas posteriormente; as Energias ainda ficam fora do
        catálogo.</p>

      <p class="copyright">© 2026 Daniel Neitzel · <a href="https://danielneitzel.dev" target="_blank"
          rel="noopener noreferrer">danielneitzel.dev</a></p>

      <div class="visit-counter visit-counter--footer" aria-label="Contador de visitantes no rodapé">
        <div class="pokeball-icon" aria-hidden="true"></div>
        <div class="visit-copy">
          <small>VISITANTES</small>
          <strong data-visit-count>...</strong>
        </div>
      </div>

    </footer>

    <div class="export-modal" id="exportModal">
      <div class="export-panel">
        <div class="export-head">
          <h3>Exportar cartas faltantes</h3><button class="export-close" id="exportClose">×</button>
        </div>
        <p>Escolha o formato para exportar apenas as cartas que ainda faltam.</p>
        <div class="export-options"><button class="export-option" onclick="exportMissing('pdf')">📕
            PDF<span>Salvar/imprimir como PDF</span></button><button class="export-option"
            onclick="exportMissing('excel')">📗 Excel<span>Planilha .csv compatível com Excel</span></button><button
            class="export-option" onclick="exportMissing('word')">📘 Word<span>Documento .doc compatível com
              Word</span></button><button class="export-option" onclick="exportMissing('txt')">📄 Texto
            puro<span>Arquivo .txt</span></button><button class="export-option" onclick="openProxyOptions()">🖨️ Proxies
            para fichário<span>Cartinhas 63 × 88 mm em A4, prontas para recortar</span></button></div>
        <div class="export-summary" id="exportSummary"></div>
        <div class="export-display-settings">
          <div class="export-display-title">👁️ Mostrar nos cards</div>
          <p class="export-display-note">Escolha quais informações aparecem nos cards da coleção.</p>
          <div class="display-options modal-display-options">
            <label class="display-check"><input id="showRarity" type="checkbox" checked> Base / holo</label>
            <label class="display-check"><input id="showChance" type="checkbox" checked> Chance</label>
            <label class="display-check"><input id="showAverage" type="checkbox" checked> Média</label>
          </div>
        </div>

        <div id="proxyOptions" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--line)">
          <b>Quais proxies deseja imprimir?</b>
          <div style="display:grid;gap:8px;margin-top:10px">
            <button class="btn" type="button" onclick="generateProxySheet('missing')">Somente cartas faltantes</button>
            <button class="btn" type="button" onclick="generateProxySheet('all')">Todas as cartas</button>
            <button class="btn" type="button" onclick="generateProxySheet('visible')">Cartas exibidas no filtro
              atual</button>
          </div>
          <p style="font-size:11px;margin:10px 0 0">Imprima em <b>100% / tamanho real</b>. Cada proxy mede 63 × 88 mm.
            Uso como marcador temporário de coleção.</p>
        </div>
      </div>
    </div>
    <div id="proxyPrintArea"></div>
    <div id="printArea" style="display:none"></div>
    <div class="modal" id="cardModal" role="dialog" aria-modal="true" aria-label="Visualização ampliada da carta">
      <div class="modal-panel">
        <button class="modal-close" id="modalClose" aria-label="Fechar">×</button>
        <div class="modal-stage" id="modalStage">
          <div class="modal-card3d">
            <img id="modalImg" alt="Carta ampliada">
            <div class="modal-shine" id="modalShine"></div>
          </div>
        </div>
        <div class="modal-info"><b id="modalTitle"></b><span class="modal-hint"> · mova o mouse para efeito 3D</span>
        </div>
        <section class="modal-history" id="modalHistory" aria-label="Histórico de preços">
          <div class="modal-history-head"><span id="modalHistoryChange" class="modal-history-change">—</span><small id="modalHistoryDays">—</small></div>
          <div class="modal-history-tabs" role="tablist" aria-label="Período do histórico">
            <button type="button" data-history-range="7" class="active">7 dias</button>
            <button type="button" data-history-range="90">3 meses</button>
            <button type="button" data-history-range="365">1 ano</button>
          </div>
          <div class="modal-history-plot"><svg class="modal-history-chart" id="modalHistoryChart" viewBox="0 0 680 170" preserveAspectRatio="none" role="img" aria-label="Gráfico de flutuação de preço"></svg><div class="modal-history-tooltip" id="modalHistoryTooltip"></div></div>
          <div class="modal-history-meta"><span id="modalHistoryFrom">—</span><span id="modalHistoryTo">—</span></div>
          <p class="modal-history-empty" id="modalHistoryEmpty" hidden>Histórico de preços indisponível para esta carta.</p>
        </section>
        <button class="btn primary modal-download" id="modalDownload" type="button">⬇ Baixar imagem</button>
      </div>
    </div>

  </main>

  <script>
    const cards = [{ "n": "001", "name": "Carta 30C #001", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_001_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/1" }, { "n": "002", "name": "Carta 30C #002", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_002_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/2" }, { "n": "003", "name": "Carta 30C #003", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_003_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/3" }, { "n": "004", "name": "Carta 30C #004", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_004_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/4" }, { "n": "005", "name": "Carta 30C #005", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_005_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/5" }, { "n": "006", "name": "Carta 30C #006", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_006_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/6" }, { "n": "007", "name": "Carta 30C #007", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_007_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/7" }, { "n": "008", "name": "Carta 30C #008", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_008_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/8" }, { "n": "009", "name": "Carta 30C #009", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_009_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/9" }, { "n": "010", "name": "Carta 30C #010", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_010_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/10" }, { "n": "011", "name": "Carta 30C #011", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_011_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/11" }, { "n": "012", "name": "Carta 30C #012", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_012_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/12" }, { "n": "013", "name": "Carta 30C #013", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_013_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/13" }, { "n": "014", "name": "Carta 30C #014", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_014_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/14" }, { "n": "015", "name": "Carta 30C #015", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_015_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/15" }, { "n": "016", "name": "Carta 30C #016", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_016_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/16" }, { "n": "017", "name": "Carta 30C #017", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_017_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/17" }, { "n": "018", "name": "Carta 30C #018", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_018_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/18" }, { "n": "019", "name": "Carta 30C #019", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_019_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/19" }, { "n": "020", "name": "Carta 30C #020", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_020_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/20" }, { "n": "021", "name": "Carta 30C #021", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_021_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/21" }, { "n": "022", "name": "Carta 30C #022", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_022_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/22" }, { "n": "023", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_023_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/23" }, { "n": "024", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_024_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/24" }, { "n": "025", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_025_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/25" }, { "n": "026", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_026_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/26" }, { "n": "027", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_027_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/27" }, { "n": "028", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_028_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/28" }, { "n": "029", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_029_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/29" }, { "n": "030", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_030_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/30" }, { "n": "031", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_031_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/31" }, { "n": "032", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_032_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/32" }, { "n": "033", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_033_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/33" }, { "n": "034", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_034_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/34" }, { "n": "035", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_035_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/35" }, { "n": "036", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_036_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/36" }, { "n": "037", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_037_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/37" }, { "n": "038", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_038_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/38" }, { "n": "039", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_039_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/39" }, { "n": "040", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_040_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/40" }, { "n": "041", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_041_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/41" }, { "n": "042", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_042_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/42" }, { "n": "043", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_043_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/43" }, { "n": "044", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_044_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/44" }, { "n": "045", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_045_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/45" }, { "n": "046", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_046_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/46" }, { "n": "047", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_047_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/47" }, { "n": "048", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_048_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/48" }, { "n": "049", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_049_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/49" }, { "n": "050", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_050_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/50" }, { "n": "051", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_051_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/51" }, { "n": "052", "name": "Pikachu", "rarity": "Pikachu 30 anos", "chance": "≈ 3,33%", "one_in": "≈ 1 em 30", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_052_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/52" }, { "n": "053", "name": "Pikachu", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_053_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/53" }, { "n": "054", "name": "Pikachu", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_054_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/54" }, { "n": "055", "name": "Carta 30C #055", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_055_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/55" }, { "n": "056", "name": "Carta 30C #056", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_056_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/56" }, { "n": "057", "name": "Carta 30C #057", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_057_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/57" }, { "n": "058", "name": "Carta 30C #058", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_058_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/58" }, { "n": "059", "name": "Carta 30C #059", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_059_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/59" }, { "n": "060", "name": "Carta 30C #060", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_060_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/60" }, { "n": "061", "name": "Carta 30C #061", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_061_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/61" }, { "n": "062", "name": "Carta 30C #062", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_062_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/62" }, { "n": "063", "name": "Carta 30C #063", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_063_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/63" }, { "n": "064", "name": "Carta 30C #064", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_064_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/64" }, { "n": "065", "name": "Carta 30C #065", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_065_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/65" }, { "n": "066", "name": "Carta 30C #066", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_066_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/66" }, { "n": "067", "name": "Carta 30C #067", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_067_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/67" }, { "n": "068", "name": "Carta 30C #068", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_068_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/68" }, { "n": "069", "name": "Carta 30C #069", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_069_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/69" }, { "n": "070", "name": "Carta 30C #070", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_070_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/70" }, { "n": "071", "name": "Carta 30C #071", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_071_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/71" }, { "n": "072", "name": "Carta 30C #072", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_072_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/72" }, { "n": "073", "name": "Carta 30C #073", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_073_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/73" }, { "n": "074", "name": "Carta 30C #074", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_074_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/74" }, { "n": "075", "name": "Carta 30C #075", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_075_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/75" }, { "n": "076", "name": "Carta 30C #076", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_076_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/76" }, { "n": "077", "name": "Carta 30C #077", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_077_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/77" }, { "n": "078", "name": "Carta 30C #078", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_078_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/78" }, { "n": "079", "name": "Carta 30C #079", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_079_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/79" }, { "n": "080", "name": "Carta 30C #080", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_080_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/80" }, { "n": "081", "name": "Carta 30C #081", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_081_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/81" }, { "n": "082", "name": "Carta 30C #082", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_082_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/82" }, { "n": "083", "name": "Carta 30C #083", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_083_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/83" }, { "n": "084", "name": "Carta 30C #084", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_084_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/84" }, { "n": "085", "name": "Carta 30C #085", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_085_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/85" }, { "n": "086", "name": "Carta 30C #086", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_086_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/86" }, { "n": "087", "name": "Carta 30C #087", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_087_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/87" }, { "n": "088", "name": "Carta 30C #088", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_088_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/88" }, { "n": "089", "name": "Carta 30C #089", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_089_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/89" }, { "n": "090", "name": "Carta 30C #090", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_090_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/90" }, { "n": "091", "name": "Carta 30C #091", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_091_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/91" }, { "n": "092", "name": "Carta 30C #092", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_092_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/92" }, { "n": "093", "name": "Carta 30C #093", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_093_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/93" }, { "n": "094", "name": "Carta 30C #094", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_094_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/94" }, { "n": "095", "name": "Carta 30C #095", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_095_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/95" }, { "n": "096", "name": "Carta 30C #096", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_096_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/96" }, { "n": "097", "name": "Carta 30C #097", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_097_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/97" }, { "n": "098", "name": "Carta 30C #098", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_098_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/98" }, { "n": "099", "name": "Carta 30C #099", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_099_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/99" }, { "n": "100", "name": "Carta 30C #100", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_100_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/100" }, { "n": "101", "name": "Carta 30C #101", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_101_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/101" }, { "n": "102", "name": "Carta 30C #102", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_102_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/102" }, { "n": "103", "name": "Carta 30C #103", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_103_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/103" }, { "n": "104", "name": "Carta 30C #104", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_104_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/104" }, { "n": "105", "name": "Carta 30C #105", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_105_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/105" }, { "n": "106", "name": "Carta 30C #106", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_106_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/106" }, { "n": "107", "name": "Carta 30C #107", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_107_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/107" }, { "n": "108", "name": "Carta 30C #108", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_108_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/108" }, { "n": "109", "name": "Carta 30C #109", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_109_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/109" }, { "n": "110", "name": "Carta 30C #110", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_110_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/110" }, { "n": "111", "name": "Carta 30C #111", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_111_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/111" }, { "n": "112", "name": "Carta 30C #112", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_112_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/112" }, { "n": "113", "name": "Carta 30C #113", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_113_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/113" }, { "n": "114", "name": "Carta 30C #114", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_114_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/114" }, { "n": "115", "name": "Carta 30C #115", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_115_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/115" }, { "n": "116", "name": "Carta 30C #116", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_116_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/116" }, { "n": "117", "name": "Carta 30C #117", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_117_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/117" }, { "n": "118", "name": "Carta 30C #118", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_118_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/118" }, { "n": "119", "name": "Carta 30C #119", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_119_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/119" }, { "n": "120", "name": "Carta 30C #120", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_120_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/120" }, { "n": "121", "name": "Carta 30C #121", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_121_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/121" }, { "n": "122", "name": "Carta 30C #122", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_122_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/122" }, { "n": "123", "name": "Carta 30C #123", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_123_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/123" }, { "n": "124", "name": "Carta 30C #124", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_124_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/124" }, { "n": "125", "name": "Carta 30C #125", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_125_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/125" }, { "n": "126", "name": "Carta 30C #126", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_126_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/126" }, { "n": "127", "name": "Carta 30C #127", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_127_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/127" }, { "n": "128", "name": "Carta 30C #128", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_128_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/128" }, { "n": "129", "name": "Carta 30C #129", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_129_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/129" }, { "n": "130", "name": "Carta 30C #130", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_130_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/130" }, { "n": "131", "name": "Carta 30C #131", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_131_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/131" }, { "n": "132", "name": "Carta 30C #132", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_132_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/132" }, { "n": "133", "name": "Carta 30C #133", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_133_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/133" }, { "n": "134", "name": "Carta 30C #134", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_134_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/134" }, { "n": "135", "name": "Carta 30C #135", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_135_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/135" }, { "n": "136", "name": "Carta 30C #136", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_136_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/136" }, { "n": "137", "name": "Carta 30C #137", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_137_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/137" }, { "n": "138", "name": "Carta 30C #138", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_138_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/138" }, { "n": "139", "name": "Carta 30C #139", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_139_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/139" }, { "n": "140", "name": "Carta 30C #140", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_140_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/140" }, { "n": "141", "name": "Carta 30C #141", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_141_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/141" }, { "n": "142", "name": "Carta 30C #142", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_142_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/142" }, { "n": "143", "name": "Carta 30C #143", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_143_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/143" }, { "n": "144", "name": "Carta 30C #144", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_144_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/144" }, { "n": "145", "name": "Carta 30C #145", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_145_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/145" }, { "n": "146", "name": "Carta 30C #146", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_146_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/146" }, { "n": "147", "name": "Carta 30C #147", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_147_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/147" }, { "n": "148", "name": "Carta 30C #148", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_148_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/148" }, { "n": "149", "name": "Carta 30C #149", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_149_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/149" }, { "n": "150", "name": "Carta 30C #150", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_150_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/150" }, { "n": "151", "name": "Carta 30C #151", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_151_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/151" }, { "n": "152", "name": "Carta 30C #152", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_152_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/152" }, { "n": "153", "name": "Carta 30C #153", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_153_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/153" }, { "n": "154", "name": "Carta 30C #154", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_154_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/154" }, { "n": "155", "name": "Carta 30C #155", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_155_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/155" }, { "n": "156", "name": "Carta 30C #156", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_156_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/156" }, { "n": "157", "name": "Carta 30C #157", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_157_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/157" }, { "n": "158", "name": "Carta 30C #158", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_158_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/158" }, { "n": "159", "name": "Carta 30C #159", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_159_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/159" }, { "n": "160", "name": "Carta 30C #160", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_160_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/160" }, { "n": "161", "name": "Carta 30C #161", "rarity": "Base / holo", "chance": "—", "one_in": "—", "img": "https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_161_R_PT.png", "limitless": "https://www.limitlesstcg.com/cards/pt/30C/161" }];
    const classicNames = [
      'Pikachu', 'Charizard', 'Misty', "Erika's Jigglypuff", 'Sneasel', 'Shining Celebi', 'Lugia',
      'Delcatty', 'Dark Tyranitar', 'Scizor ex', 'Metagross δ', 'Palkia LV.X', 'Uxie', 'Crobat G',
      'Gengar (Prime)', 'Darkrai & Cresselia LEGEND · Top', 'Darkrai & Cresselia LEGEND · Bottom', 'N',
      'Rayquaza-EX', 'Genesect-EX', 'M Gardevoir-EX', 'Greninja BREAK', 'Solgaleo-GX', 'Buzzwole-GX',
      'Pikachu & Zekrom-GX', 'Zacian V', 'Raikou', 'Mew VMAX', 'Arceus VSTAR', 'Magikarp'
    ];
    const classicLigaNumbers = [58, 4, 18, 69, 25, 106, 149, 5, 19, 108, 11, 18, 43, 47, 94, 99, 100, 100, 47, 11, 106, 41, '089', '069', '033', 18, 50, 114, 123, '203'];
    const classicLigaUrls = {
      CC23: 'https://www.ligapokemon.com.br/?view=cards/card&card=Solgaleo%2DGX%20%28089%2F30%29&ed=30C-C&num=089',
      CC25: 'https://www.ligapokemon.com.br/?view=cards/card&card=Pikachu%20%26amp%3B%20Zekrom-GX%20%28033%2F30%29&ed=30C-C&num=033',
      CC30: 'https://www.ligapokemon.com.br/?view=cards/card&card=Magikarp%20%28203%2F30%29&ed=30C-C&num=203'
    };
    const classicCards = classicNames.map((name, index) => {
      const code = `CC${index + 1}`;
      return {
        n: code,
        ligaNum: classicLigaNumbers[index],
        ligaUrl: classicLigaUrls[code] || null,
        name,
        rarity: 'Classic Collection',
        chance: '—',
        one_in: '—',
        img: `https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_${code}_R_PT.png`,
        limitless: `https://www.limitlesstcg.com/cards/pt/30C/${code}`,
        collection: 'classic'
      };
    });
    cards.push(...classicCards);

    const rgbCards = [
      ['R/RGB', 'Mew · Vermelho', 'R'],
      ['G/RGB', 'Mew · Verde', 'G'],
      ['B/RGB', 'Mew · Azul', 'B']
    ].map(([n, name, code]) => ({
      n,
      name,
      rarity: 'RGB Secret Rare',
      chance: '—',
      one_in: '—',
      img: `https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpci/30C/30C_${code}_R_PT.png`,
      limitless: `https://www.limitlesstcg.com/cards/pt/30C/${code}`,
      collection: 'rgb'
    }));
    cards.push(...rgbCards);

    ['159', '160', '161'].forEach(number => {
      const index = cards.findIndex(card => card.n === number);
      if (index !== -1) cards.splice(index, 1);
    });

    const exCards = {
      '015': 'Fuecoco ex', '021': 'Greninja ex', '053': 'Pikachu ex · Dia', '054': 'Pikachu ex · Noite',
      '064': 'Mewtwo ex', '066': 'Mew ex', '070': 'Espeon ex', '071': 'Sylveon ex',
      '090': 'Gengar ex', '092': 'Umbreon ex', '102': 'Jirachi ex', '109': 'Salamence ex',
      '147': 'Fuecoco ex', '148': 'Greninja ex', '149': 'Pikachu ex · Dia', '150': 'Pikachu ex · Noite',
      '153': 'Sylveon ex', '155': 'Jirachi ex', '156': 'Salamence ex', '157': 'Mewtwo ex', '158': 'Mew ex'
    };
    cards.forEach(card => {
      if (exCards[card.n]) {
        card.name = exCards[card.n];
        card.rarity = 'Pokémon ex';
      }
    });

    const grid = document.getElementById('grid'), q = document.getElementById('q'), filter = document.getElementById('filter'), sortOrder = document.getElementById('sortOrder'), count = document.getElementById('count');
    const cardLanguage = document.getElementById('cardLanguage');
    const LANGUAGE_KEY = 'pokemon30c-language-v1';
    const OWNED_KEY = 'pokemon30c-owned-v1';
    let owned = new Set(JSON.parse(localStorage.getItem(OWNED_KEY) || '[]'));
    const priceData = {};
    const IMAGE_CACHE_VERSION = '20260927-1';
    let marketPricesLoaded = false;
    let ligaPricesUpdating = false;

    function cacheBustImage(src) {
      if (!src || src.startsWith('data:')) return src;
      return `${src}${src.includes('?') ? '&' : '?'}v=${IMAGE_CACHE_VERSION}`;
    }

    const savedLanguage = localStorage.getItem(LANGUAGE_KEY);
    cardLanguage.value = ['pt', 'en', 'jp'].includes(savedLanguage) ? savedLanguage : 'pt';

    cards.forEach(card => { card.baseImg = card.img; });
    function applyCardLanguage() {
      cards.forEach(card => {
        if (cardLanguage.value === 'en') {
          card.img = card.baseImg.replace('_PT.png', '_EN.png');
        } else if (cardLanguage.value === 'jp' && card.collection !== 'classic' && card.collection !== 'rgb' && Number(card.n) <= 111) {
          card.img = `https://limitlesstcg.nyc3.cdn.digitaloceanspaces.com/tpc/M6a/M6a_${Number(card.n)}_R_JP.png`;
        } else {
          card.img = card.baseImg;
        }
      });
    }
    applyCardLanguage();

    function currentCardPrice(card) {
      const raw = advertisedPrice(card);
      if (!raw) return null;
      const normalized = String(raw).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
      const amount = Number(normalized);
      return Number.isFinite(amount) ? amount : null;
    }

    function advertisedPrice(card) {
      const data = priceData[priceKey(card)] || {};
      return data.liga?.advertised?.min || data.deckcerto?.advertised?.min || data.min || null;
    }

    function getLigaUrl(card) {
      if (card.ligaUrl) return card.ligaUrl;
      const isClassic = card.collection === 'classic';
      const isRgb = card.collection === 'rgb';
      const edition = isClassic ? '30C-C' : isRgb ? '30C-R' : '30C';
      const number = String(card.ligaNum || card.n);
      const total = isClassic ? '30' : isRgb ? '3' : '128';
      const ligaName = isRgb ? 'Mew' : isClassic ? card.name.replace(/\s+(VSTAR|VMAX|GX|EX|BREAK|LV\.X)$/i, '-$1') : card.name;
      const cardNumber = isRgb ? number : `${number}/${total}`;
      const ligaNumber = isRgb ? number.split('/')[0] : number;
      const query = `view=cards/card&card=${encodeURIComponent(`${ligaName} (${cardNumber})`)}&ed=${encodeURIComponent(edition)}&num=${encodeURIComponent(ligaNumber)}`;
      return `https://www.ligapokemon.com.br/?${query}`;
    }

    function getCardSourceUrl(card) {
      if (cardLanguage.value === 'jp') {
        return 'https://www.ligapokemon.com.br/?view=cards/search&card=edid=822%20ed=M6a';
      }
      return getLigaUrl(card);
    }

    function priceKey(card) {
      return `${card.collection || 'base'}:${card.n}`;
    }

    function formatPrice(value) {
      if (value === null || value === undefined || value === '') return 'Indisponível';
      const number = Number(String(value).replace(',', '.'));
      return Number.isFinite(number) ? number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : 'Indisponível';
    }

    async function loadPrices() {
      try {
        const response = await fetch(window.location.pathname + '?prices=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ cards: cards.map(card => ({ n: card.n, collection: card.collection || 'base' })) }),
          cache: 'no-store'
        });
        if (!response.ok) return;
        const data = await response.json();
        Object.assign(priceData, data.prices || {});
        render();
      } catch (error) {
        console.warn('Preços indisponíveis:', error);
      }
    }

    async function loadMarketPrices() {
      try {
        const response = await fetch(window.location.pathname + '?market_prices=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ cards: cards.map(card => ({ n: card.n, ligaNum: card.ligaNum, name: card.name, collection: card.collection || 'base' })) }),
          cache: 'no-store'
        });
        if (response.ok) {
          const data = await response.json();
          Object.assign(priceData, data.prices || {});
        }

        // Exibe imediatamente os preços do catálogo; as consultas da Liga
        // para RGB e cards especiais não devem atrasar essa atualização.
        marketPricesLoaded = true;
        render();

        // Estas cartas têm páginas próprias na Liga e precisam de uma
        // consulta individual, pois não aparecem corretamente no catálogo
        // geral do DeckCerto.
        await Promise.all(cards.filter(card => card.collection === 'rgb' || card.ligaUrl).map(async card => {
          try {
            const ligaResponse = await fetch(window.location.pathname + '?liga_price=1', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify({ url: getLigaUrl(card) }),
              cache: 'no-store'
            });
            if (!ligaResponse.ok) return;
            priceData[priceKey(card)] = {
              ...(priceData[priceKey(card)] || {}),
              liga: await ligaResponse.json()
            };
          } catch (error) {
            console.warn(`Preço da Liga indisponível para RGB ${card.n}:`, error);
          }
        }));

        render();
      } catch (error) {
        console.warn('Preços de mercado indisponíveis:', error);
        marketPricesLoaded = true;
        render();
      }
    }

    async function refreshLigaPrices() {
      if (ligaPricesUpdating) return;

      const status = document.getElementById('ligaPriceStatus');
      const batchSize = 5;
      let completed = 0;
      let failed = 0;
      ligaPricesUpdating = true;
      button.disabled = true;
      status.textContent = `Atualizando 0/${cards.length}...`;

      const updateCardPrice = async card => {
        try {
          const response = await fetch(window.location.pathname + '?liga_price=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ url: getLigaUrl(card) }),
            cache: 'no-store'
          });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const data = await response.json();
          priceData[priceKey(card)] = {
            ...(priceData[priceKey(card)] || {}),
            liga: data
          };
        } catch (error) {
          failed += 1;
          console.warn(`Preço da Liga indisponível para ${card.n}:`, error);
        } finally {
          completed += 1;
          status.textContent = `Atualizando ${completed}/${cards.length}...`;
        }
      };

      try {
        for (let index = 0; index < cards.length; index += batchSize) {
          await Promise.all(cards.slice(index, index + batchSize).map(updateCardPrice));
        }
        render();
        status.textContent = failed
          ? `Atualização concluída: ${cards.length - failed}/${cards.length} cartas.`
          : `Preços atualizados: ${cards.length} cartas.`;
      } finally {
        ligaPricesUpdating = false;
        button.disabled = false;
      }
    }

    function saveOwned() {
      localStorage.setItem(OWNED_KEY, JSON.stringify([...owned]));
    }

    function exportCollectionJSON() {
      const exportedCards = cards
        .filter(card => owned.has(card.n))
        .map(card => ({
          numero: card.n,
          nome: card.name,
          colecao: card.collection || 'base',
          precoAtualizado: advertisedPrice(card)
        }));
      const total = exportedCards.reduce((sum, card) => {
        if (!card.precoAtualizado) return sum;
        const normalized = String(card.precoAtualizado).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const amount = Number(normalized);
        return Number.isFinite(amount) ? sum + amount : sum;
      }, 0);
      const payload = {
        app: 'pokemon30c',
        version: 2,
        exportedAt: new Date().toISOString(),
        owned: [...owned].sort(),
        cards: exportedCards,
        valorTotal: total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
      };
      dl(JSON.stringify(payload, null, 2), 'application/json;charset=utf-8', 'pokemon-30c-colecao.json');
    }

    function importCollectionJSON(file) {
      if (!file) return;
      const reader = new FileReader();
      reader.onload = () => {
        try {
          const data = JSON.parse(reader.result);
          const imported = Array.isArray(data) ? data : data.owned;
          if (!Array.isArray(imported)) throw new Error('Formato não reconhecido.');

          const validCards = new Set(cards.map(card => card.n));
          const nextOwned = new Set(imported.map(String).filter(n => validCards.has(n)));
          if (!confirm(`Substituir sua coleção atual por ${nextOwned.size} carta(s) importada(s)?`)) return;

          owned = nextOwned;
          saveOwned();
          render();
          alert(`Coleção importada: ${owned.size} carta(s).`);
        } catch (error) {
          alert(`Não foi possível importar a coleção: ${error.message}`);
        }
      };
      reader.readAsText(file);
    }

    document.getElementById('exportCollection').addEventListener('click', exportCollectionJSON);
    cardLanguage.addEventListener('change', () => {
      localStorage.setItem(LANGUAGE_KEY, cardLanguage.value);
      applyCardLanguage();
      document.getElementById('languageStatus').textContent = `Imagens: ${cardLanguage.options[cardLanguage.selectedIndex].text}`;
      render();
    });
    document.getElementById('importCollection').addEventListener('click', () => document.getElementById('collectionFile').click());
    document.getElementById('collectionFile').addEventListener('change', event => {
      importCollectionJSON(event.target.files[0]);
      event.target.value = '';
    });

    function toggleOwned(n, checked) {
      if (checked) owned.add(n); else owned.delete(n);
      saveOwned();
      render();
    }
    function updateProgress() {
      const total = cards.length;
      const have = cards.filter(c => owned.has(c.n)).length;
      const pct = total ? have / total * 100 : 0;
      const value = cards.filter(c => owned.has(c.n)).reduce((sum, card) => {
        const data = priceData[priceKey(card)] || {};
        const raw = data.liga?.advertised?.min ?? data.deckcerto?.advertised?.min ?? data.min;
        if (raw === null || raw === undefined || raw === '') return sum;
        const normalized = String(raw).replace(/[^\d,.-]/g, '').replace(/\./g, '').replace(',', '.');
        const amount = Number(normalized);
        return Number.isFinite(amount) ? sum + amount : sum;
      }, 0);
      document.getElementById('collectionProgress').textContent = `${have}/${total} na coleção · ${pct.toFixed(1).replace('.', ',')}%`;
      document.getElementById('collectionValue').textContent = `Valor da coleção: ${value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}`;
      document.getElementById('progressFill').style.width = `${pct}%`;
    }

    const DISPLAY_KEY = 'pokemon30c-display-v1';
    let displayPrefs = Object.assign({ rarity: true, chance: true, average: true }, JSON.parse(localStorage.getItem(DISPLAY_KEY) || '{}'));
    const showRarity = document.getElementById('showRarity'), showChance = document.getElementById('showChance'), showAverage = document.getElementById('showAverage');
    showRarity.checked = displayPrefs.rarity; showChance.checked = displayPrefs.chance; showAverage.checked = displayPrefs.average;
    function saveDisplayPrefs() {
      displayPrefs = { rarity: showRarity.checked, chance: showChance.checked, average: showAverage.checked };
      localStorage.setItem(DISPLAY_KEY, JSON.stringify(displayPrefs));
      render();
    }
    showRarity.addEventListener('change', saveDisplayPrefs);
    showChance.addEventListener('change', saveDisplayPrefs);
    showAverage.addEventListener('change', saveDisplayPrefs);

    function render() {
      updateProgress();
      const term = q.value.trim().toLowerCase();
      const mode = filter.value;
      const list = cards.filter(c => {
        const n = +c.n;
        if (mode === 'pikachu' && !(n >= 23 && n <= 52)) return false;
        if (mode === 'owned' && !owned.has(c.n)) return false;
        if (mode === 'missing' && owned.has(c.n)) return false;
        if (mode === 'ex' && !c.name.toLowerCase().includes(' ex')) return false;
        if (mode === 'classic' && c.collection !== 'classic') return false;
        if (mode === 'rgb' && c.collection !== 'rgb') return false;
        if (mode === 'owned-pikachu' && (!(n >= 23 && n <= 52) || !owned.has(c.n))) return false;
        return !term || c.n.includes(term) || c.name.toLowerCase().includes(term) || c.rarity.toLowerCase().includes(term);
      });
      if (sortOrder.value !== 'default') {
        list.sort((a, b) => {
          const priceA = currentCardPrice(a);
          const priceB = currentCardPrice(b);
          if (priceA === null && priceB === null) return 0;
          if (priceA === null) return 1;
          if (priceB === null) return -1;
          return sortOrder.value === 'price-desc' ? priceB - priceA : priceA - priceB;
        });
      }
      count.textContent = `${list.length} carta(s) exibida(s)`;
      grid.innerHTML = list.map(c => `
  <article class="card ${owned.has(c.n) ? 'owned' : ''}">
    <div class="pic" role="button" tabindex="0" title="Clique para ampliar em 3D"
      onclick="openCardModal('${c.img}','${c.n}','${c.name.replace(/'/g, "\\'")}')"
      onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openCardModal('${c.img}','${c.n}','${c.name.replace(/'/g, "\\'")}')}">
      <div class="card-loading" aria-live="polite"><div class="pokeball-icon mini" aria-hidden="true"></div><span>Carregando imagem…</span></div>
      <img loading="lazy" src="${c.img}" alt="${c.name} — carta Pokémon 30C nº ${c.n}" onload="this.parentElement.querySelector('.card-loading')?.remove()" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=&quot;text-align:center;color:#806400;font-weight:900&quot;>30C<br>#${c.n}<br><small>imagem indisponível</small></div>'">
    </div>
    <div class="body">
<div class="num">${c.collection === 'classic' ? '30C-C' : c.collection === 'rgb' ? '30C-R' : '30C'} · #${c.n}</div>
      <div class="title">${c.name}</div>
      <span class="badge card-rarity" ${displayPrefs.rarity ? '' : 'style="display:none"'}>${c.rarity}</span>
      <div class="prob card-chance" ${displayPrefs.chance ? '' : 'style="display:none"'}><span>Chance específica</span><b>${c.chance}</b></div>
      <div class="prob card-average" ${displayPrefs.average ? '' : 'style="display:none"'}><span>Média</span><b>${c.one_in}</b></div>
      <div class="market-prices" aria-label="Referências de preço">
      <div class="market-price"><span>Preço atualizado</span><strong>${marketPricesLoaded ? (advertisedPrice(c) || 'Indisponível') : 'Carregando…'}</strong></div>
      </div>
      <label class="owned-toggle">
        <input type="checkbox" ${owned.has(c.n) ? 'checked' : ''} onchange="toggleOwned('${c.n}',this.checked)">
        <span>Já tirei esta carta</span>
      </label>
      <div class="card-actions">
        <button class="btn" type="button" onclick="openCardModal('${c.img}','${c.n}','${c.name.replace(/'/g, "\\'")}')">⛶</button>
        <button class="btn price-action" type="button" onclick="openPriceModal('${c.n}')">📈</button>
        <a class="btn primary" target="_blank" rel="noopener" href="${getCardSourceUrl(c)}">Liga</a>
      </div>
    </div>
  </article>`).join('');
      grid.querySelectorAll('img[src]').forEach(img => {
        img.src = cacheBustImage(img.getAttribute('src'));
      });
    }
    q.addEventListener('input', render);
    filter.addEventListener('change', render);
    sortOrder.addEventListener('change', render);

    const categoryFilters = {
      '#categoria-todas': 'all',
      '#categoria-pikachu': 'pikachu',
      '#categoria-colecao': 'owned',
      '#categoria-faltantes': 'missing'
    };
    function applyCategoryFromHash() {
      const selected = categoryFilters[window.location.hash];
      if (!selected) return;
      filter.value = selected;
      render();
      document.getElementById('cartas').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    window.addEventListener('hashchange', applyCategoryFromHash);
    applyCategoryFromHash();

    document.getElementById('clearOwned').addEventListener('click', () => {
      if (confirm('Deseja remover todas as marcações de cartas da coleção?')) {
        owned.clear(); saveOwned(); render();
      }
    });
    render();
    loadMarketPrices();

    const modal = document.getElementById('cardModal');
    const modalStage = document.getElementById('modalStage');
    const modalImg = document.getElementById('modalImg');
    const modalTitle = document.getElementById('modalTitle');
    const modalShine = document.getElementById('modalShine');
    const modalDownload = document.getElementById('modalDownload');
    const modalHint = document.querySelector('.modal-hint');
    const modalHistory = document.getElementById('modalHistory');
    const modalHistoryChange = document.getElementById('modalHistoryChange');
    const modalHistoryDays = document.getElementById('modalHistoryDays');
    const modalHistoryChart = document.getElementById('modalHistoryChart');
    const modalHistoryTooltip = document.getElementById('modalHistoryTooltip');
    const modalHistoryFrom = document.getElementById('modalHistoryFrom');
    const modalHistoryTo = document.getElementById('modalHistoryTo');
    const modalHistoryEmpty = document.getElementById('modalHistoryEmpty');
    let modalImageUrl = '';
    let modalCardNumber = '';

    let modalHistoryData = [];
    let modalHistoryRange = 7;
    function historyMoney(value) {
      return Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }
    function renderModalHistory() {
      const points = modalHistoryData.map(item => ({ date: new Date(`${item[0]}T00:00:00`), value: Number(item[1]) }))
        .filter(item => Number.isFinite(item.date.getTime()) && Number.isFinite(item.value)).sort((a, b) => a.date - b.date);
      const lastDate = points.at(-1)?.date;
      const cutoff = lastDate ? new Date(lastDate.getTime() - modalHistoryRange * 86400000) : null;
      const filtered = cutoff ? points.filter(item => item.date >= cutoff) : points;
      const series = filtered.length ? filtered : points.slice(-1);
      modalHistoryEmpty.hidden = series.length > 0;
      modalHistoryChart.style.display = series.length ? 'block' : 'none';
      modalHistoryDays.textContent = series.length > 1 ? `${Math.max(1, Math.round((series.at(-1).date - series[0].date) / 86400000))} dias` : '—';
      if (!series.length) {
        modalHistoryChange.textContent = '—';
        modalHistoryChange.className = 'modal-history-change';
        modalHistoryFrom.textContent = modalHistoryTo.textContent = '—';
        modalHistoryChart.innerHTML = '';
        return;
      }
      const min = Math.min(...series.map(item => item.value)), max = Math.max(...series.map(item => item.value));
      const spread = max - min || Math.max(max * .05, 1), width = 680, height = 170, pad = 8;
      const coords = series.map((item, index) => {
        const x = series.length === 1 ? width / 2 : pad + index * (width - pad * 2) / (series.length - 1);
        const y = height - 18 - ((item.value - min) / spread) * (height - 36);
        return `${x.toFixed(1)},${y.toFixed(1)}`;
      }).join(' ');
      const first = series[0].value, last = series.at(-1).value, change = first ? (last - first) / first * 100 : 0;
      const lastPoint = coords.split(' ').at(-1).split(',');
      modalHistoryChange.textContent = `${change >= 0 ? '+' : ''}${change.toFixed(1).replace('.', ',')}% · ${historyMoney(last)}`;
      modalHistoryChange.className = `modal-history-change ${change >= 0 ? 'up' : 'down'}`;
      modalHistoryFrom.textContent = series[0].date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
      modalHistoryTo.textContent = series.at(-1).date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
      const coordinatePairs = coords.split(' ');
      const area = `${coordinatePairs[0].split(',')[0]},${height - 18} ${coords} ${coordinatePairs.at(-1).split(',')[0]},${height - 18}`;
      const grid = [35, 78, 121].map(y => `<line x1="8" y1="${y}" x2="672" y2="${y}" stroke="#e2e4eb" stroke-dasharray="3 4"/>`).join('');
      const hits = series.map((item, index) => {
        const [x, y] = coordinatePairs[index].split(',');
        return `<circle class="history-hit" data-index="${index}" cx="${x}" cy="${y}" r="10" fill="transparent"/>`;
      }).join('');
      modalHistoryChart.innerHTML = `<defs><linearGradient id="historyFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#9b28d1" stop-opacity=".25"/><stop offset="1" stop-color="#9b28d1" stop-opacity=".03"/></linearGradient></defs>${grid}<polygon points="${area}" fill="url(#historyFill)"/><polyline points="${coords}" fill="none" stroke="#8700c8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="${lastPoint[0]}" cy="${lastPoint[1]}" r="3.5" fill="#8700c8"/>${hits}`;
      modalHistoryChart.querySelectorAll('.history-hit').forEach(hit => {
        hit.addEventListener('mouseenter', () => {
          const item = series[Number(hit.dataset.index)];
          modalHistoryTooltip.innerHTML = `${historyMoney(item.value)}<small>${item.date.toLocaleDateString('pt-BR')}</small>`;
          const x = Number(hit.getAttribute('cx')) / width * 100;
          modalHistoryTooltip.style.left = `${Math.min(82, Math.max(2, x))}%`;
          modalHistoryTooltip.style.top = '2px';
          modalHistoryTooltip.style.display = 'block';
        });
        hit.addEventListener('mouseleave', () => { modalHistoryTooltip.style.display = 'none'; });
      });
    }

    function openCardModal(src, n, name, showImage = true) {
      modalImageUrl = cacheBustImage(src);
      modalCardNumber = n;
      modalStage.style.display = showImage ? 'block' : 'none';
      modalDownload.style.display = showImage ? 'inline-flex' : 'none';
      modalHint.style.display = showImage ? 'inline' : 'none';
      modalHistory.style.display = showImage ? 'none' : 'block';
      modalImg.src = modalImageUrl;
      modalImg.alt = `${name} `;
      modalTitle.textContent = `${name} `;
      const card = cards.find(item => item.n === n);
      modalHistoryData = priceData[priceKey(card || { n, collection: 'base' })]?.deckcerto?.history || [];
      modalHistoryRange = 7;
      modalHistory.querySelectorAll('[data-history-range]').forEach(button => button.classList.toggle('active', button.dataset.historyRange === '7'));
      renderModalHistory();
      modal.classList.add('open');
      document.body.style.overflow = 'hidden';
      reset3d();
    }
    function openPriceModal(n) {
      const card = cards.find(item => item.n === n);
      if (!card) return;
      openCardModal(card.img, card.n, card.name, false);
    }
    async function downloadModalImage() {
      if (!modalImageUrl) return;
      const filename = `pokemon-30c-${modalCardNumber || 'carta'}.png`;
      try {
        const response = await fetch(modalImageUrl, { mode: 'cors' });
        if (!response.ok) throw new Error('Imagem indisponível');
        const blob = await response.blob();
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (error) {
        window.open(modalImageUrl, '_blank', 'noopener');
      }
    }
    function closeCardModal() {
      modal.classList.remove('open');
      modalStage.style.display = 'block';
      modalDownload.style.display = 'inline-flex';
      modalHint.style.display = 'inline';
      modalHistory.style.display = 'none';
      document.body.style.overflow = '';
      reset3d();
    }
    function reset3d() {
      modalStage.style.transform = 'rotateX(0deg) rotateY(0deg) scale(1)';
      modalShine.style.setProperty('--mx', '50%');
      modalShine.style.setProperty('--my', '50%');
    }
    document.getElementById('modalClose').addEventListener('click', closeCardModal);
    modalDownload.addEventListener('click', downloadModalImage);
    modalHistory.querySelectorAll('[data-history-range]').forEach(button => button.addEventListener('click', () => {
      modalHistoryRange = Number(button.dataset.historyRange);
      modalHistory.querySelectorAll('[data-history-range]').forEach(item => item.classList.toggle('active', item === button));
      renderModalHistory();
    }));
    modalImg.addEventListener('contextmenu', event => event.preventDefault());
    modalImg.addEventListener('touchstart', event => {
      if (event.touches.length > 1) event.preventDefault();
    }, { passive: false });
    modal.addEventListener('click', e => { if (e.target === modal) closeCardModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal.classList.contains('open')) closeCardModal(); });

    modalStage.addEventListener('pointermove', e => {
      const r = modalStage.getBoundingClientRect();
      const x = (e.clientX - r.left) / r.width;
      const y = (e.clientY - r.top) / r.height;
      const ry = (x - .5) * 22;
      const rx = (.5 - y) * 22;
      modalStage.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg) scale(1.035)`;
      modalShine.style.setProperty('--mx', `${x * 100}%`);
      modalShine.style.setProperty('--my', `${y * 100}%`);
    });
    modalStage.addEventListener('pointerleave', reset3d);


    const THEME_KEY = 'pokemon30c-theme-v1';
    const themeToggle = document.getElementById('themeToggle');

    function applyTheme(theme) {
      const dark = theme === 'dark';
      document.body.classList.toggle('dark', dark);
      themeToggle.textContent = dark ? '☀️' : '🌙';
      themeToggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    const savedTheme = localStorage.getItem(THEME_KEY);
    const initialTheme = savedTheme || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    applyTheme(initialTheme);

    themeToggle.addEventListener('click', () => {
      const next = document.body.classList.contains('dark') ? 'light' : 'dark';
      localStorage.setItem(THEME_KEY, next);
      applyTheme(next);
    });

    const exportModal = document.getElementById('exportModal'); function missing() { return cards.filter(c => !owned.has(c.n)) } document.getElementById('openExport').onclick = () => { let m = missing(); document.getElementById('exportSummary').innerHTML = '<b>' + m.length + '</b> carta(s) faltando de <b>' + cards.length + '</b>'; exportModal.classList.add('open') }; document.getElementById('exportClose').onclick = () => exportModal.classList.remove('open'); function dl(x, t, n) { let b = new Blob([x], { type: t }), u = URL.createObjectURL(b), a = document.createElement('a'); a.href = u; a.download = n; a.click(); setTimeout(() => URL.revokeObjectURL(u), 500) } function csvCell(v) {
      return '"' + String(v ?? '').replace(/"/g, '""') + '"';
    }
    function exportMissing(f) {
      const r = cards.filter(c => !owned.has(c.n));
      const d = new Date().toLocaleDateString('pt-BR');

      if (f === 'txt') {
        const txt = ['Pokémon 30C — Cartas faltantes', 'Gerado em: ' + d, 'Faltantes: ' + r.length + '/' + cards.length, '',
          ...r.map(c => '#' + c.n + ' | ' + c.name + ' | ' + c.rarity + ' | Chance: ' + c.chance + ' | Média: ' + c.one_in),
          '', '© 2026 Daniel Neitzel · danielneitzel.dev'].join('\r\n');
        dl('\ufeff' + txt, 'text/plain;charset=utf-8', 'pokemon-30c-cartas-faltantes.txt');
        closeExportModal ? closeExportModal() : exportModal.classList.remove('open');
        return;
      }

      if (f === 'excel') {
        const csv = [
          ['Número', 'Nome', 'Raridade', 'Chance', 'Média'].map(csvCell).join(';'),
          ...r.map(c => [c.n, c.name, c.rarity, c.chance, c.one_in].map(csvCell).join(';'))
        ].join('\r\n');
        dl('\ufeff' + csv, 'text/csv;charset=utf-8', 'pokemon-30c-cartas-faltantes.csv');
        closeExportModal ? closeExportModal() : exportModal.classList.remove('open');
        return;
      }

      const rows = r.map(c => `<tr><td>#${c.n}</td><td>${c.name}</td><td>${c.rarity}</td><td>${c.chance}</td><td>${c.one_in}</td></tr>`).join('');
      const table = `<table><thead><tr><th>Nº</th><th>Nome</th><th>Raridade</th><th>Chance</th><th>Média</th></tr></thead><tbody>${rows}</tbody></table>`;

      if (f === 'word') {
        const doc = `<!doctype html><html><head><meta charset="utf-8"><style>
      body{font-family:Arial,sans-serif;margin:28px;color:#111}h1{font-size:24px}
      table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #aaa;padding:7px;font-size:11pt}th{background:#eee}
      </style></head><body><h1>Pokémon 30C — Cartas faltantes</h1><p>Gerado em ${d} · ${r.length}/${cards.length} cartas faltando.</p>${table}<p>© 2026 Daniel Neitzel · danielneitzel.dev</p><div class="pokeball-icon"></div><div class="visit-copy"><small>Acessos</small><strong id="visitCount">—</strong></div></aside>

</body></html>`;
        dl('\ufeff' + doc, 'application/msword;charset=utf-8', 'pokemon-30c-cartas-faltantes.doc');
        closeExportModal ? closeExportModal() : exportModal.classList.remove('open');
        return;
      }

      if (f === 'pdf') {
        const w = window.open('', '_blank');
        if (!w) { alert('O navegador bloqueou a janela do PDF. Permita pop-ups para este site e tente novamente.'); return; }
        w.document.open();
        w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Pokémon 30C — Cartas faltantes</title><style>
      @page{size:A4;margin:12mm}body{font-family:Arial,sans-serif;color:#111}h1{font-size:22px}
      table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #aaa;padding:6px;font-size:9pt}th{background:#eee}
      .toolbar{position:sticky;top:0;background:#17140b;color:white;padding:12px;text-align:center}.toolbar button{margin-left:12px;padding:9px 16px;background:#f5c400;border:0;border-radius:8px;font-weight:bold}
      @media print{.toolbar{display:none}}
      </style></head><body><div class="toolbar">Prévia do PDF · ${r.length} cartas faltantes <button onclick="window.print()">Salvar como PDF</button></div>
      <h1>Pokémon 30C — Cartas faltantes</h1><p>Gerado em ${d} · ${r.length}/${cards.length} cartas faltando.</p>${table}<p>© 2026 Daniel Neitzel · danielneitzel.dev</p></body></html>`);
        w.document.close();
        closeExportModal ? closeExportModal() : exportModal.classList.remove('open');
        setTimeout(() => { w.focus(); w.print() }, 350);
        return;
      }
    }
    function openProxyOptions() {
      document.getElementById('proxyOptions').style.display = 'block';
    }
    function getVisibleCardsForProxy() {
      const term = q.value.trim().toLowerCase(), mode = filter.value;
      return cards.filter(c => {
        const n = +c.n;
        if (mode === 'pikachu' && !(n >= 23 && n <= 52)) return false;
        if (mode === 'owned' && !owned.has(c.n)) return false;
        if (mode === 'missing' && owned.has(c.n)) return false;
        if (mode === 'owned-pikachu' && (!(n >= 23 && n <= 52) || !owned.has(c.n))) return false;
        return !term || c.n.includes(term) || c.name.toLowerCase().includes(term) || c.rarity.toLowerCase().includes(term);
      });
    }
    function generateProxySheet(mode) {
      let list = mode === 'missing' ? cards.filter(c => !owned.has(c.n)) : mode === 'all' ? cards : getVisibleCardsForProxy();
      if (!list.length) { alert('Nenhuma carta encontrada para gerar os proxies.'); return; }

      const pages = [];
      for (let i = 0; i < list.length; i += 9) pages.push(list.slice(i, i + 9));

      const cardsHtml = pages.map(page => `<section class="proxy-page">${page.map(c => `
    <div class="proxy-card">
      <img src="${c.img}" alt="30C #${c.n}">
      <div class="proxy-label">30C #${c.n} · ${c.name}</div>
    </div>`).join('')}</section>`).join('');

      const w = window.open('', '_blank');
      if (!w) { alert('O navegador bloqueou a janela de impressão. Permita pop-ups para este site e tente novamente.'); return; }

      w.document.open();
      w.document.write(`<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">
  <title>Pokémon 30C — Proxies para fichário</title>
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:#ddd;font-family:Arial,sans-serif}
    .toolbar{position:sticky;top:0;z-index:10;background:#17140b;color:#fff;padding:12px 16px;text-align:center}
    .toolbar button{background:#f5c400;border:0;border-radius:9px;padding:10px 18px;font-weight:800;cursor:pointer;margin-left:10px}
    .info{font-size:12px;opacity:.85}
    .proxy-page{width:210mm;height:297mm;margin:10px auto;background:#fff;padding:10mm 6.5mm;display:grid;grid-template-columns:repeat(3,63mm);grid-template-rows:repeat(3,88mm);gap:4mm 4mm;align-content:start;justify-content:center;page-break-after:always}
    .proxy-page:last-of-type{page-break-after:auto}
    .proxy-card{width:63mm;height:88mm;border:.25mm dashed #777;position:relative;overflow:hidden;background:#fff}
    .proxy-card img{width:63mm;height:88mm;display:block;object-fit:contain}
    .proxy-label{position:absolute;left:1.5mm;right:1.5mm;bottom:1.5mm;background:rgba(255,255,255,.88);padding:1mm;border-radius:1mm;text-align:center;font-size:7pt;font-weight:bold}
    @page{size:A4 portrait;margin:0}
    @media print{
      html,body{background:#fff}
      .toolbar{display:none!important}
      .proxy-page{margin:0;width:210mm;height:297mm;break-after:page;page-break-after:always}
      .proxy-page:last-child{break-after:auto;page-break-after:auto}
    }
  </style></head><body>
  <div class="toolbar"><b>Proxies 30C — ${list.length} carta(s)</b>
    <span class="info"> · escolha “Salvar como PDF”, papel A4, escala 100% e margens Nenhuma</span>
    <button onclick="window.print()">Gerar / Salvar PDF</button>
  </div>
  ${cardsHtml}
  </body></html>`);
      w.document.close();

      exportModal.classList.remove('open');
      document.body.style.overflow = '';

      // Wait for card images before opening print dialog.
      const imgs = [...w.document.images];
      Promise.all(imgs.map(img => img.complete ? Promise.resolve() : new Promise(resolve => {
        img.addEventListener('load', resolve, { once: true });
        img.addEventListener('error', resolve, { once: true });
      }))).then(() => setTimeout(() => { w.focus(); w.print(); }, 500));
    }


    document.addEventListener('DOMContentLoaded', function () {
      const counts = Array.from(document.querySelectorAll('[data-visit-count]'));
      if (!counts.length) return;

      fetch(window.location.pathname + '?visits=1&t=' + Date.now(), {
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      }).then(function (d) {
        if (typeof d.count !== 'number') throw new Error('JSON inválido');
        counts.forEach(function (count) {
          count.textContent = d.count.toLocaleString('pt-BR');
          count.title = 'Visitantes únicos por IP';
        });
      }).catch(function (e) {
        counts.forEach(function (count) {
          count.textContent = 'ERRO';
          count.title = e.message;
        });
      });
    });
    let deferredInstallPrompt;
    const installApp = document.getElementById('installApp');
    const pwaPrompt = document.getElementById('pwaPrompt');
    const installPwaPrompt = document.getElementById('installPwaPrompt');
    const dismissPwaPrompt = document.getElementById('dismissPwaPrompt');
    const isMobileDevice = window.matchMedia('(max-width: 760px)').matches;
    const isStandaloneApp = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    function showPwaPrompt() {
      if (isMobileDevice && !isStandaloneApp && !sessionStorage.getItem('pwa-install-dismissed')) {
        pwaPrompt?.classList.add('visible');
      }
    }

    function hidePwaPrompt(remember = false) {
      pwaPrompt?.classList.remove('visible');
      if (remember) sessionStorage.setItem('pwa-install-dismissed', '1');
    }

    window.addEventListener('beforeinstallprompt', event => {
      event.preventDefault();
      deferredInstallPrompt = event;
      installApp?.classList.add('visible');
      setTimeout(showPwaPrompt, 900);
    });
    async function requestPwaInstall() {
      if (!deferredInstallPrompt) return;
      hidePwaPrompt();
      deferredInstallPrompt.prompt();
      await deferredInstallPrompt.userChoice;
      deferredInstallPrompt = null;
      installApp?.classList.remove('visible');
    }
    installApp?.addEventListener('click', requestPwaInstall);
    installPwaPrompt?.addEventListener('click', requestPwaInstall);
    dismissPwaPrompt?.addEventListener('click', () => hidePwaPrompt(true));
    window.addEventListener('appinstalled', () => {
      hidePwaPrompt();
      installApp?.classList.remove('visible');
    });
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => navigator.serviceWorker.register('./sw.js?v=<?= $cacheVersion ?>').catch(error => {
        console.warn('PWA indisponível:', error);
      }));
    }
  </script>

</body>

</html>
