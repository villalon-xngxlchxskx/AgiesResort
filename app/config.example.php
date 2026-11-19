<?php
// Copy to app/config.php and adjust. app/ is blocked from the web by app/.htaccess.
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'agies_resort_db',
        'user'    => 'root',   // XAMPP default. Create a dedicated user before deploying.
        'pass'    => '',
    ],
    // URL path where the project lives under htdocs, no trailing slash.
    // C:\xampp\htdocs\agies-resort  ->  '/agies-resort'
    'base_url' => '/agies-resort',
    'timezone' => 'Asia/Manila',
    // Allows install/setup.php (from this computer only) to set staff passwords.
    // Set to false once your accounts are set up.
    'setup_enabled' => true,
    // Max upload size for gallery/content images and payment proofs.
    'upload_max_bytes' => 10 * 1024 * 1024,   // per image; also raise upload_max_filesize/post_max_size in php.ini if needed
    // Set true only when the site is served over HTTPS.
    'cookie_secure' => false,
];
