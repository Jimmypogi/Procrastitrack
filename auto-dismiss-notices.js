(() => {
    const selector = [
        '[role="alert"]',
        '[role="status"]:not(#preferenceStatus):not(#chartLoadStatus):not(.task-create-status):not(.task-live-status):not(.coach-tool-status):not(.coach-result):not(.delay-task-prefilled):not(.ci-locked-note):not(.report-empty-alert)',
        '.alert',
        '.notice',
        '.message-box',
        '.overdue-alert'
    ].join(',');

    const dismissNotices = () => {
        document.querySelectorAll(selector).forEach(notice => {
            if (notice.hidden || notice.dataset.autoDismiss === 'false' || notice.dataset.autoDismissInitialized) return;
            notice.dataset.autoDismissInitialized = 'true';

            window.setTimeout(() => {
                if (!notice.isConnected) return;
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    notice.remove();
                    return;
                }

                notice.style.transition = 'opacity 200ms ease, transform 200ms ease';
                notice.style.opacity = '0';
                notice.style.transform = 'translateY(-5px)';
                window.setTimeout(() => notice.remove(), 200);
            }, 4800);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', dismissNotices, { once: true });
    } else {
        dismissNotices();
    }
})();
