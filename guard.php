<?php
require_once __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header('Location: index.php'); exit; }
