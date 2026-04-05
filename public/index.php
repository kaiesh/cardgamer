<?php
/**
 * SPA entry point.
 */
$config = require __DIR__ . '/../src/config.php';
$pusherKey = htmlspecialchars($config['pusher']['key']);
$pusherCluster = htmlspecialchars($config['pusher']['cluster']);
$appName = htmlspecialchars($config['app']['name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?= $appName ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/table.css">
    <link rel="stylesheet" href="/assets/css/zone-builder.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>&#x1F0CF;</text></svg>">
</head>
<body>
    <div id="app"></div>
    <script>
        window.PUSHER_KEY = '<?= $pusherKey ?>';
        window.PUSHER_CLUSTER = '<?= $pusherCluster ?>';
    </script>
    <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
