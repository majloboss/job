<?php
// Skopiruj ako db.php a dopln skutocne hodnoty.
// POZOR: db.php je vynechany z FTP deployu (.gitignore) — GitHub Actions ho
//        generuje automaticky z GitHub Secrets (MAIN_* / DEV_* podla vetvy).

// --- MAIN (produkcia) ---
// define('DB_NAME',    'DB-JOB');          // GitHub Secret: MAIN_DB_NAME
// define('APP_URL',    'https://job.fellow.sk');

// --- DEV (develop vetva) ---
// define('DB_NAME',    'DB-JOB-DEV');      // GitHub Secret: DEV_DB_NAME
// define('APP_URL',    'https://devjob.fellow.sk');

define('DB_HOST',    'db.r5.websupport.sk');
define('DB_PORT',    '5432');                 // DSN musi obsahovat port
define('DB_NAME',    'YOUR_DB_NAME');
define('DB_USER',    'YOUR_DB_USER');
define('DB_PASS',    'YOUR_DB_PASS');
define('JWT_SECRET', 'your_random_secret_min_32_chars');
define('APP_URL',    'https://job.fellow.sk');
define('CRON_SECRET','your_cron_secret_token');
