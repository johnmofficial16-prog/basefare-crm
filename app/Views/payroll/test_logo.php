<?php
$path = __DIR__ . '/../../../salary slip logo.jpeg';
echo "Path: $path\n";
echo "is_file: " . (is_file($path) ? 'Yes' : 'No') . "\n";
echo "base64 length: " . strlen(base64_encode(file_get_contents($path))) . "\n";
