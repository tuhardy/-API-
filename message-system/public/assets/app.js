const filterShell = document.querySelector('.filters-shell');
if (filterShell && window.matchMedia('(max-width: 520px)').matches) {
    filterShell.removeAttribute('open');
}

const bulkDeleteForm = document.getElementById('bulk-delete-form');
if (bulkDeleteForm) {
    const selectAll = bulkDeleteForm.querySelector('[data-select-all]');
    const checkboxes = Array.from(bulkDeleteForm.querySelectorAll('[data-record-checkbox]'));
    const deleteButton = document.getElementById('bulk-delete-button');

    const updateSelection = () => {
        const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
        if (deleteButton) {
            deleteButton.disabled = selected === 0;
            deleteButton.textContent = selected ? `删除选中 (${selected})` : '删除选中';
        }
        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
            selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
        }
    };

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
            updateSelection();
        });
    }
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelection));

    bulkDeleteForm.addEventListener('submit', (event) => {
        const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
        if (!selected) {
            event.preventDefault();
            return;
        }
        const message = `确定永久删除选中的 ${selected} 条原始记录吗？\n\n对应原始文件和数据库索引都会被删除，此操作无法恢复。`;
        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });

    updateSelection();
}

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget);
        if (!target) return;

        try {
            await navigator.clipboard.writeText(target.textContent || '');
            showCopyNotice('已复制到剪贴板');
        } catch (_) {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(target);
            selection.removeAllRanges();
            selection.addRange(range);
            showCopyNotice('已选中内容，请按 Ctrl+C 复制');
        }
    });
});

function showCopyNotice(message) {
    const notice = document.querySelector('.copy-notice');
    notice.textContent = message;
    notice.hidden = false;
    window.clearTimeout(showCopyNotice.timeout);
    showCopyNotice.timeout = window.setTimeout(() => {
        notice.hidden = true;
    }, 2200);
}
