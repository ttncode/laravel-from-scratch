<?php
$title = $title ?? config('app.name');
$styles = $styles ?? ['css/app.css'];
$toast = $toast ?? null;
$errors = $errors ?? [];
$environment = config('app.env');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <?php foreach ($styles as $style): ?>
        <link rel="stylesheet" href="<?= asset($style) ?>">
    <?php endforeach; ?>
</head>
<body>
    <div class="toast-container">
        <?php if (!empty($toast) && is_array($toast)): ?>
            <?php $toastType = htmlspecialchars($toast['type'] ?? 'info'); ?>
            <div class="toast toast-<?= $toastType ?>">
                <?php if (!empty($toast['title'])): ?>
                    <div class="toast-heading"><?= htmlspecialchars($toast['title']) ?></div>
                <?php endif; ?>
                <div class="toast-body"><?= htmlspecialchars($toast['message'] ?? '') ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors) && is_array($errors)): ?>
            <div class="toast toast-error">
                <div class="toast-heading">Validation error</div>
                <div class="toast-body">There were problems with your submission.</div>
                <ul class="toast-error-list">
                    <?php foreach ($errors as $field => $messages): ?>
                        <?php foreach ((array) $messages as $message): ?>
                            <li><?= htmlspecialchars($message) ?></li>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>

    <div class="app-shell">
        <?php if (isset($slot) && is_callable($slot)): ?>
            <?= $slot() ?>
        <?php endif; ?>
    </div>

    <div class="app-env"><?= htmlspecialchars($environment) ?></div>
</body>
</html>
