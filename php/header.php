<?php
// php/header.php
declare(strict_types=1);

// Titel – kan sättas per sida via $PAGE_TITLE
if (empty($PAGE_TITLE)) {
    $PAGE_TITLE = 'My Football System';
}

// Ev. extra CSS/JS – kan sättas per sida via $EXTRA_HEAD
if (empty($EXTRA_HEAD)) {
    $EXTRA_HEAD = '';
}
?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($PAGE_TITLE, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Standard CSS -->
    <link rel="stylesheet" href="/css/main.css">

    <!-- Extra head-content -->
    <?= $EXTRA_HEAD ?>
</head>
<body>
