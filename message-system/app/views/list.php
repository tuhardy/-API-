<header class="page-heading">
    <div>
        <p class="script-name"><?= e($definition['script']) ?></p>
        <h1><?= e($definition['label']) ?>：原始数据接收</h1>
        <p><?= e($definition['description']) ?> 先确认接收和保存状态，再点击原始记录查看解析。</p>
    </div>
    <div class="callback-address">
        <span>设备回调地址</span>
        <code>POST /<?= e($definition['script']) ?></code>
    </div>
</header>

<section class="receive-status <?= $latest && $latest['storage_status'] === 'failed' ? 'has-error' : '' ?>" aria-label="最近接收状态">
    <div class="receive-status-main">
        <span class="receive-indicator" aria-hidden="true"></span>
        <div>
            <?php if (!$latest): ?>
                <strong>尚未收到原始数据</strong>
                <span>等待设备向上述回调地址发送请求</span>
            <?php elseif ($latest['storage_status'] === 'saved'): ?>
                <strong>最近一条原始数据已成功保存</strong>
                <span><?= e(formatUtcDate($latest['received_at'])) ?> · <?= e(formatBytes((int) $latest['body_size'])) ?></span>
            <?php else: ?>
                <strong>最近一条原始数据保存失败</strong>
                <span><?= e(formatUtcDate($latest['received_at'])) ?> · <?= e($latest['error_message']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <dl class="receive-counts">
        <div><dt>全部记录</dt><dd><?= $counts['total'] ?></dd></div>
        <div><dt>已保存</dt><dd><?= $counts['saved'] ?></dd></div>
        <div><dt>保存失败</dt><dd><?= $counts['failed'] ?></dd></div>
    </dl>
</section>

<?php if (is_array($deletionResult)): ?>
    <?php $deletionFailed = (int) $deletionResult['failed'] > 0; ?>
    <section class="deletion-result <?= $deletionFailed ? 'has-error' : 'is-success' ?>" role="status">
        <strong>
            <?php if ((int) $deletionResult['deleted'] > 0): ?>
                已删除 <?= (int) $deletionResult['deleted'] ?> 条原始记录
            <?php else: ?>
                未删除任何记录
            <?php endif; ?>
        </strong>
        <?php if ($deletionFailed): ?>
            <span><?= (int) $deletionResult['failed'] ?> 条未删除，请检查文件权限或刷新列表。</span>
        <?php else: ?>
            <span>对应的数据库索引和原始文件已一并处理。</span>
        <?php endif; ?>
        <?php if ($deletionResult['errors']): ?>
            <ul>
                <?php foreach (array_slice($deletionResult['errors'], 0, 5) as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
                <?php if (count($deletionResult['errors']) > 5): ?>
                    <li>其余 <?= count($deletionResult['errors']) - 5 ?> 条错误已省略。</li>
                <?php endif; ?>
            </ul>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="records-section">
    <details class="filters-shell" open>
        <summary>筛选原始记录</summary>
        <form class="filters" method="get" action="index.php">
        <input type="hidden" name="channel" value="<?= e($activeChannel) ?>">
        <label>
            <span>接收状态</span>
            <select name="status">
                <option value="">全部状态</option>
                <option value="saved" <?= ($filters['status'] ?? '') === 'saved' ? 'selected' : '' ?>>已保存</option>
                <option value="failed" <?= ($filters['status'] ?? '') === 'failed' ? 'selected' : '' ?>>保存失败</option>
            </select>
        </label>
        <label class="filter-search">
            <span>记录 ID / IP / 类型</span>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="输入关键词">
        </label>
        <label>
            <span>开始时间</span>
            <input type="datetime-local" name="from" value="<?= e($from) ?>">
        </label>
        <label>
            <span>结束时间</span>
            <input type="datetime-local" name="to" value="<?= e($to) ?>">
        </label>
        <div class="filter-actions">
            <button class="button" type="submit">筛选记录</button>
            <a class="button-secondary" href="<?= e(queryUrl('index.php', ['channel' => $activeChannel])) ?>">清除</a>
        </div>
        </form>
    </details>

    <div class="records-heading">
        <div>
            <h2>原始数据接收列表</h2>
            <span>共 <?= $result['total'] ?> 条，按接收时间倒序；批量选择仅作用于本页</span>
        </div>
        <?php if ($result['items']): ?>
            <button class="button-danger" id="bulk-delete-button" type="submit" form="bulk-delete-form" disabled>
                删除选中
            </button>
        <?php endif; ?>
    </div>

    <form id="bulk-delete-form" method="post" action="delete.php">
        <input type="hidden" name="channel" value="<?= e($activeChannel) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="table-scroll">
            <table class="raw-table">
                <thead>
                <tr>
                    <th class="select-cell"><input type="checkbox" data-select-all aria-label="选择本页全部记录"></th>
                    <th>接收状态</th>
                    <th>原始记录 ID</th>
                    <th>接收时间</th>
                    <th>来源 / 方法</th>
                    <th>Content-Type</th>
                    <th>大小</th>
                    <th>原始数据摘要</th>
                    <th><span class="sr-only">操作</span></th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$result['items']): ?>
                    <tr><td colspan="9" class="empty-row">没有符合条件的原始记录。等待设备发送数据，或清除筛选后再试。</td></tr>
                <?php endif; ?>
                <?php foreach ($result['items'] as $item): ?>
                    <?php
                    $bodyPath = recordFilePath($item, 'body_path');
                    $viewUrl = queryUrl('view.php', ['id' => $item['id']]);
                    $saved = $item['storage_status'] === 'saved';
                    ?>
                    <tr>
                        <td class="select-cell"><input type="checkbox" name="ids[]" value="<?= e($item['id']) ?>" data-record-checkbox aria-label="选择原始记录 <?= e($item['id']) ?>"></td>
                        <td><span class="status-badge <?= $saved ? 'saved' : 'failed' ?>"><?= $saved ? '✓ 已保存' : '✕ 保存失败' ?></span></td>
                        <td><a class="record-id" href="<?= e($viewUrl) ?>" title="<?= e($item['id']) ?>"><?= e(substr($item['id'], 0, 12)) ?>…</a></td>
                        <td class="time-cell"><?= e(formatUtcDate($item['received_at'])) ?></td>
                        <td><span><?= e($item['remote_addr'] ?: '—') ?></span><small><?= e($item['method'] ?: '—') ?></small></td>
                        <td class="type-cell" title="<?= e($item['content_type']) ?>"><?= e($item['content_type'] ?: '—') ?></td>
                        <td class="size-cell"><?= e(formatBytes((int) $item['body_size'])) ?></td>
                        <td class="preview-cell">
                            <a href="<?= e($viewUrl) ?>"><?= e(rawPreview($bodyPath, (int) $item['body_size'])) ?></a>
                            <?php if ($item['error_message']): ?><small class="error-text"><?= e($item['error_message']) ?></small><?php endif; ?>
                        </td>
                        <td><a class="view-link" href="<?= e($viewUrl) ?>">查看解析</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($result['pages'] > 1): ?>
        <nav class="pagination" aria-label="原始记录分页">
            <?php
            $parameters = ['channel' => $activeChannel, 'status' => $filters['status'], 'q' => $filters['q'], 'from' => $from, 'to' => $to];
            if ($result['page'] > 1): ?>
                <a href="<?= e(queryUrl('index.php', $parameters + ['page' => $result['page'] - 1])) ?>">上一页</a>
            <?php endif; ?>
            <span>第 <?= $result['page'] ?> / <?= $result['pages'] ?> 页</span>
            <?php if ($result['page'] < $result['pages']): ?>
                <a href="<?= e(queryUrl('index.php', $parameters + ['page' => $result['page'] + 1])) ?>">下一页</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
