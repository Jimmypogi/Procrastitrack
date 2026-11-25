(() => {
    const iconByEmoji = new Map([
        ['⏸', 'pause'], ['⏯', 'circle-play'], ['⏰', 'alarm-clock'], ['⏳', 'hourglass'],
        ['↗', 'arrow-up-right'], ['↕', 'arrow-up-down'], ['✏', 'pencil'], ['✔', 'check'],
        ['❓', 'circle-help'], ['✅', 'circle-check'], ['❌', 'circle-x'], ['☕', 'coffee'],
        ['⚠', 'triangle-alert'], ['⚡', 'zap'], ['⚙', 'settings'], ['🌅', 'sunrise'],
        ['🌙', 'moon'], ['🌟', 'star'], ['🍅', 'timer'], ['🎉', 'party-popper'],
        ['🎨', 'palette'], ['🎯', 'target'], ['🏆', 'trophy'], ['🏠', 'house'],
        ['👁', 'eye'], ['👍', 'thumbs-up'], ['👤', 'user'], ['💜', 'heart'],
        ['💡', 'lightbulb'], ['💤', 'bed'], ['💪', 'dumbbell'], ['💭', 'messages-square'],
        ['📅', 'calendar-days'], ['📆', 'calendar'], ['📈', 'trending-up'],
        ['📉', 'trending-down'], ['📊', 'bar-chart-3'], ['📋', 'clipboard-list'],
        ['📌', 'pin'], ['📖', 'book-open'], ['📚', 'library'], ['📝', 'notebook-pen'],
        ['🔄', 'refresh-cw'], ['🔋', 'battery'], ['🔍', 'search'], ['🔒', 'lock-keyhole'],
        ['🔔', 'bell'], ['🔥', 'flame'], ['🔧', 'wrench'], ['🔴', 'circle'],
        ['🕒', 'clock-3'], ['🗑', 'trash-2'], ['🗓', 'calendar-range'], ['😊', 'smile'],
        ['😴', 'bed'], ['🚪', 'door-open'], ['🛑', 'octagon'], ['🤖', 'bot'],
        ['🟠', 'circle'], ['🟡', 'circle'], ['🟢', 'circle'],
        [String.fromCodePoint(0x1F4E8), 'mail-open'], [String.fromCodePoint(0x1F9EA), 'flask-conical'], [String.fromCodePoint(0x1F9EC), 'dna']
    ]);
    const pictograph = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27FF}\u{2190}-\u{21FF}](?:\uFE0F|\uFE0E)?/gu;

    function replaceEmojiIn(root) {
        let converted = false;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                const parent = node.parentElement;
                if (!parent || parent.closest('script,style,textarea,input,select,option,[data-icon-text]')) return NodeFilter.FILTER_REJECT;
                pictograph.lastIndex = 0;
                return pictograph.test(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
            }
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        for (const node of nodes) {
            pictograph.lastIndex = 0;
            const fragment = document.createDocumentFragment();
            let last = 0;
            for (const match of node.nodeValue.matchAll(pictograph)) {
                const emoji = match[0].replace(/[\uFE0E\uFE0F]/g, '');
                const iconName = iconByEmoji.get(emoji);
                if (!iconName) continue;
                fragment.append(document.createTextNode(node.nodeValue.slice(last, match.index)));
                const icon = document.createElement('i');
                icon.className = 'app-icon';
                icon.setAttribute('data-lucide', iconName);
                icon.setAttribute('aria-hidden', 'true');
                fragment.append(icon);
                last = match.index + match[0].length;
            }
            if (last) {
                fragment.append(document.createTextNode(node.nodeValue.slice(last)));
                node.replaceWith(fragment);
                converted = true;
            }
        }
        return converted;
    }

    function addIconStyles() {
        if (document.getElementById('app-icon-styles')) return;
        const style = document.createElement('style');
        style.id = 'app-icon-styles';
        style.textContent = '.app-icon, svg.lucide { width: 1.1em; height: 1.1em; display: inline-block; flex: 0 0 auto; vertical-align: -0.16em; stroke-width: 1.75 !important; } .search-result-item::before { content: none !important; } .sidebar .sidebar-nav > a > .app-icon { width: 1.1rem; height: 1.1rem; font-size: 1.08rem; }';
        document.head.append(style);
    }
    function renderIcons(root = document) {
        if (!window.lucide || !window.lucide.createIcons) return;
        window.lucide.createIcons({ attrs: { 'stroke-width': 1.75 } });
    }

    function start() {
        addIconStyles();
        replaceEmojiIn(document.body);
        renderIcons();
        const observer = new MutationObserver(records => {
            let changed = false;
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        const holder = document.createElement('span');
                        holder.append(node.cloneNode());
                        if (replaceEmojiIn(holder)) {
                            node.replaceWith(...holder.childNodes);
                            changed = true;
                        }
                    } else if (node.nodeType === Node.ELEMENT_NODE && !node.matches('svg')) {
                        if (replaceEmojiIn(node)) changed = true;
                    }
                }
            }
            if (changed) requestAnimationFrame(() => renderIcons());
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();