<?php
// OpenRouter — kluc a predvoleny model na posudzovanie inzeratov.
// Skutocny subor openrouter.php nepatri do gitu (obsahuje kluc).
//
// Kluc:   https://openrouter.ai/keys
// Modely: https://openrouter.ai/models
//
// Ktore modely su bezplatne sa v case meni. V admin sekcii je obrazovka
// "Laboratórium modelov" — zadaj URL inzeratu, otestuje vsetky aktualne
// :free modely a ukaze, ktory posudil najlepsie. Vitaza zapis sem.

define('OPENROUTER_KEY',   'sk-or-v1-...');
define('OPENROUTER_MODEL', 'google/gemma-4-31b-it:free');
define('OPENROUTER_URL',   'https://openrouter.ai/api/v1/chat/completions');
