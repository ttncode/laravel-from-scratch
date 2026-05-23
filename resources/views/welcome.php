<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Welcome to <?= config('app.name') ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/welcome.css') ?>">
</head>
<body>
    <main class="page">
        <h1>Welcome to <?= config('app.name') ?></h1>
        <p>This minimal framework demonstrates routing, controllers, and views in a simple PHP app inspired by Laravel.</p>
        <p>A small home page and profile view are now available.</p>

        <a class="button" href="/profile">View profile</a>

        <footer class="meta">Built with ❤️ — tiny Laravel clone</footer>
    </main>
</body>
</html>
