<?php
$title = 'Profile - ' . htmlspecialchars($name ?? 'Guest');
$styles = ['css/app.css', 'css/profile.css'];
$toast = empty($errors)
    ? [
        'type' => 'success',
        'title' => 'Profile loaded',
        'message' => 'Your profile details are ready.',
    ]
    : null;
?>

<?= layout('layouts.app', function () use ($title, $styles, $toast, $name, $errors) { ?>
    <article class="card">
        <h1>Profile</h1>
        <p>Name: <span class="badge"><?= htmlspecialchars($name ?? 'Guest') ?></span></p>
        <p>Welcome back! This page is rendered by your controller and view system.</p>
        <p><a class="link" href="/">← Back to welcome</a></p>
    </article>
<?php }, compact('title', 'styles', 'toast', 'name', 'errors')) ?>