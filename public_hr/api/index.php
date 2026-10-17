<?php
// Trio HR API — the one entry point. Everything else lives in app/Hr.
require __DIR__ . '/../../vendor/autoload.php';
\App\Hr\Api::run();
