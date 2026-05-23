<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profile - <?= htmlspecialchars($name ?? 'Guest') ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset('css/profile.css') ?>">
</head>
<body>
    <article class="card">
        <h1>Profile</h1>
        <p>Name: <span class="badge"><?= htmlspecialchars($name ?? 'Guest') ?></span></p>
        <p>Welcome back! This page is rendered by your controller and view system.</p>
        <p><a class="link" href="/">← Back to welcome</a></p>
    </article>
</body>
</html>
