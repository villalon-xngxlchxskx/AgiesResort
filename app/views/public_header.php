<?php
/** @var string $pageTitle  @var string $active */
$resortName = (string) setting('resort_name', "Agie's Resort");
$nav = [
    'home' => ['', 'Home'], 'about' => ['about.php', 'About'], 'areas' => ['areas.php', 'Areas & packages'],
    'availability' => ['availability.php', 'Availability'], 'gallery' => ['gallery.php', 'Gallery'],
    'policies' => ['policies.php', 'Policies'], 'contact' => ['contact.php', 'Contact'], 'booking' => ['my-booking.php', 'My booking'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ?? '') ? $pageTitle . ' · ' . $resortName : $resortName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="site">
<a class="skip" href="#main">Skip to content</a>
<header class="site-head">
  <div class="wrap head-row">
    <a class="brand" href="<?= e(url('')) ?>"><span class="brand-mark" aria-hidden="true"></span><?= e($resortName) ?></a>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">Menu</button>
    <nav id="site-nav" class="site-nav" aria-label="Main">
      <?php foreach ($nav as $k => [$href, $label]): ?>
        <a href="<?= e(url($href)) ?>"<?= ($active ?? '') === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
      <a class="btn btn-mango" href="<?= e(url('reserve.php')) ?>">Book a date</a>
    </nav>
  </div>
</header>
<main id="main">
<?php $fl = render_flashes(); if ($fl): ?><div class="wrap flashes"><?= $fl ?></div><?php endif; ?>
