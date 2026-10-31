<?php
declare(strict_types=1);

$iconPath = __DIR__ . '/pwa-icon.svg';
$iconVersion = is_file($iconPath) ? (string)filemtime($iconPath) : '1';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode([
  'name' => 'Guia 30 Anos Pokémon · PT-BR',
  'short_name' => 'Guia 30 Anos',
  'description' => 'Guia de cartas, preços e coleção Pokémon 30 Anos em português do Brasil.',
  'lang' => 'pt-BR',
  'start_url' => './index.php',
  'scope' => './',
  'display' => 'standalone',
  'orientation' => 'portrait-primary',
  'background_color' => '#fffaf0',
  'theme_color' => '#f5c400',
  'icons' => [
    [
      'src' => './pwa-icon.svg?v=' . $iconVersion,
      'sizes' => '192x192',
      'type' => 'image/svg+xml',
      'purpose' => 'any maskable',
    ],
    [
      'src' => './pwa-icon.svg?v=' . $iconVersion,
      'sizes' => '512x512',
      'type' => 'image/svg+xml',
      'purpose' => 'any maskable',
    ],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
