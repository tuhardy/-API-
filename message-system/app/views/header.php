<?php $channels = channelDefinitions(); ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle) ?> · <?= e(config('app_name')) ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php" aria-label="网关数据测试台首页">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span><strong>网关数据测试台</strong><small>原始数据优先</small></span>
        </a>
        <nav class="channel-nav" aria-label="四个回调脚本">
            <?php foreach ($channels as $key => $channel): ?>
                <a href="<?= e(queryUrl('index.php', ['channel' => $key])) ?>"
                   class="channel-link<?= $activeChannel === $key ? ' active' : '' ?>"
                   <?= $activeChannel === $key ? 'aria-current="page"' : '' ?>>
                    <span><?= e($channel['label']) ?></span>
                    <code><?= e($channel['script']) ?></code>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-note">
            <span class="storage-dot" aria-hidden="true"></span>
            <span>原始请求先保存<br>点击记录后解析</span>
        </div>
    </aside>
    <main class="main-content" id="main-content">
