<?php
require __DIR__ . '/../../config/config.php';
$_SESSION = [];
session_destroy();
respond(['ok' => true]);
