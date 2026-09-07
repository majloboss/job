<?php
// Nacita konfiguraciu OpenRoutera a overi, ze je v nej platny kluc.
// Vydeleny subor, aby sa kontrola neopakovala v kazdom endpointe.
$cfg = __DIR__ . '/../config/openrouter.php';
if (!file_exists($cfg)) {
    json_error('Chýba api/config/openrouter.php — skopíruj openrouter.example.php a doplň kľúč', 500);
}
require_once $cfg;
if (!defined('OPENROUTER_KEY') || !str_starts_with(OPENROUTER_KEY, 'sk-or-')) {
    json_error('V api/config/openrouter.php nie je platný kľúč OpenRoutera', 500);
}
