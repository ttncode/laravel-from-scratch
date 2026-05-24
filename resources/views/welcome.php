<?php
$title = 'Welcome to ' . config('app.name');
$styles = ['css/app.css', 'css/welcome.css'];
$toast = [
    'type' => 'info',
    'title' => 'Welcome!',
    'message' => 'This app now uses a shared layout with toast support.',
];
?>

<?= layout('layouts.app', function () use ($title, $styles, $toast) { ?>
    <main class="page">
        <h1>Welcome to <?= config('app.name') ?></h1>
        <p>This minimal framework demonstrates routing, controllers, and views in a simple PHP app inspired by Laravel.</p>
        <p>A small home page and profile view are now available.</p>

        <a class="button" href="/profile">View profile</a>

        <footer class="meta">Built with ❤️ — tiny Laravel clone</footer>
    </main>
<?php }, compact('title', 'styles', 'toast')) ?>
