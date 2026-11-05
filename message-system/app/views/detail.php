<nav class="back-nav" aria-label="返回">
    <a href="<?= e(queryUrl('index.php', ['channel' => $activeChannel])) ?>">← 返回<?= e($definition['label']) ?>原始数据列表</a>
</nav>

<header class="detail-heading">
    <div>
        <p class="script-name"><?= e($definition['script']) ?></p>
        <h1>原始记录解析</h1>
        <p>此页面从已保存的原始文件读取数据；解析不会修改原始文件。</p>
    </div>
    <span class="status-badge <?= $record['storage_status'] === 'saved' ? 'saved' : 'failed' ?>">
        <?= $record['storage_status'] === 'saved' ? '✓ 原始数据已保存' : '✕ 原始数据保存失败' ?>
    </span>
</header>

<dl class="record-meta">
    <div><dt>原始记录 ID</dt><dd><?= e($record['id']) ?></dd></div>
    <div><dt>接收时间</dt><dd><?= e(formatUtcDate($record['received_at'])) ?></dd></div>
    <div><dt>数据大小</dt><dd><?= e(formatBytes((int) $record['body_size'])) ?></dd></div>
    <div><dt>SHA-256</dt><dd><?= e($record['body_sha256'] ?: '—') ?></dd></div>
</dl>

<section class="detail-section" id="raw-data">
    <div class="section-heading">
        <div><span class="section-number">1</span><h2>原始数据</h2></div>
        <div class="section-actions">
            <?php if (is_string($body) && $rawIsText && !$rawTruncated): ?>
                <button class="button-secondary" type="button" data-copy-target="raw-body">复制原始数据</button>
            <?php endif; ?>
            <?php if ($bodyPath): ?><a class="button-secondary" href="<?= e(queryUrl('raw.php', ['id' => $id, 'file' => 'body'])) ?>">下载原始文件</a><?php endif; ?>
        </div>
    </div>
    <?php if (is_string($body) && $rawIsText): ?>
        <?php if ($rawTruncated): ?><p class="notice">原始文本较大，此处只显示前 <?= e(formatBytes((int) config('raw_display_limit'))) ?>。完整内容请下载原始文件。</p><?php endif; ?>
        <pre class="raw-view" id="raw-body"><?= e($rawDisplay) ?></pre>
    <?php elseif ($hexPreview !== null): ?>
        <p class="notice">原始数据包含二进制内容，以下仅为十六进制预览。完整 MIME 和附件字节请下载原始文件。</p>
        <pre class="raw-view" id="raw-body"><?= e($hexPreview['text']) ?></pre>
        <?php if ($hexPreview['truncated']): ?><p class="notice">十六进制预览仅显示前 4 KB。</p><?php endif; ?>
    <?php else: ?>
        <div class="empty-state compact">没有可显示的原始请求体。<?= e($record['error_message'] ?: '') ?></div>
    <?php endif; ?>
</section>

<section class="detail-section" id="request-data">
    <div class="section-heading">
        <div><span class="section-number">2</span><h2>请求信息</h2></div>
        <div class="section-actions">
            <button class="button-secondary" type="button" data-copy-target="request-json">复制请求信息</button>
            <?php if ($requestPath): ?><a class="button-secondary" href="<?= e(queryUrl('raw.php', ['id' => $id, 'file' => 'request'])) ?>">下载请求信息</a><?php endif; ?>
        </div>
    </div>
    <pre class="raw-view request-view" id="request-json"><?= e($requestJson) ?></pre>
</section>

<section class="detail-section parsed-section" id="parsed-data">
    <div class="section-heading">
        <div><span class="section-number">3</span><h2>解析结果</h2></div>
        <span class="parse-status <?= e($parsed['status']) ?>"><?= e(parseStatusLabel($parsed['status'])) ?></span>
    </div>
    <p class="parse-message"><?= e($parsed['message']) ?></p>

    <?php if ($parsed['fields']): ?>
        <dl class="parsed-fields">
            <?php foreach ($parsed['fields'] as $field): ?>
                <div><dt><?= e($field['label']) ?></dt><dd><?= nl2br(e($field['value'])) ?></dd></div>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <?php foreach ($parsed['texts'] as $text): ?>
        <div class="parsed-text">
            <h3><?= e($text['title']) ?></h3>
            <pre><?= e($text['content']) ?></pre>
        </div>
    <?php endforeach; ?>

    <?php foreach ($parsed['tables'] as $table): ?>
        <div class="parsed-table">
            <h3><?= e($table['title']) ?></h3>
            <?php if (!$table['rows']): ?>
                <p class="muted">没有明细记录。</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead><tr><?php foreach ($table['columns'] as $column): ?><th><?= e($column) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($table['rows'] as $row): ?>
                            <tr><?php foreach ($row as $value): ?><td><?= nl2br(e(valueToString($value))) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($parsed['attachments']): ?>
        <div class="attachments">
            <h3>彩信附件</h3>
            <div class="attachment-list">
                <?php foreach ($parsed['attachments'] as $attachment): ?>
                    <?php $mediaUrl = queryUrl('media.php', ['id' => $id, 'part' => $attachment['part']]); ?>
                    <article class="attachment">
                        <?php if ($attachment['preview']): ?>
                            <a href="<?= e($mediaUrl) ?>" target="_blank" rel="noopener"><img src="<?= e($mediaUrl) ?>" alt="<?= e($attachment['filename']) ?>" loading="lazy"></a>
                        <?php endif; ?>
                        <div class="attachment-info">
                            <strong><?= e($attachment['filename']) ?></strong>
                            <span><?= e($attachment['mime']) ?> · <?= e(formatBytes($attachment['size'])) ?></span>
                            <a href="<?= e($mediaUrl . '&download=1') ?>">下载附件</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
