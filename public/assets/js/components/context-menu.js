/**
 * Context menu component.
 */
let currentMenu = null;

export function showContextMenu(actions, x, y) {
    hideContextMenu();

    const menu = document.createElement('div');
    menu.className = 'context-menu';

    // Position near click or center of viewport
    if (x !== undefined && y !== undefined) {
        menu.style.left = x + 'px';
        menu.style.top = y + 'px';
    } else {
        menu.style.left = '50%';
        menu.style.top = '50%';
        menu.style.transform = 'translate(-50%, -50%)';
    }

    const primary = actions.slice(0, 4);
    const more = actions.slice(4);

    primary.forEach(a => {
        const btn = document.createElement('button');
        btn.className = 'ctx-btn';
        btn.innerHTML = `<span class="ctx-icon">${a.icon || ''}</span><span>${a.label}</span>`;
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            hideContextMenu();
            a.action();
        });
        menu.appendChild(btn);
    });

    if (more.length) {
        const moreBtn = document.createElement('button');
        moreBtn.className = 'ctx-btn ctx-more';
        moreBtn.textContent = 'More...';
        moreBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            // Replace with extended menu
            menu.innerHTML = '';
            [...primary, ...more].forEach(a => {
                const btn = document.createElement('button');
                btn.className = 'ctx-btn';
                btn.innerHTML = `<span class="ctx-icon">${a.icon || ''}</span><span>${a.label}</span>`;
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    hideContextMenu();
                    a.action();
                });
                menu.appendChild(btn);
            });
        });
        menu.appendChild(moreBtn);
    }

    document.body.appendChild(menu);
    currentMenu = menu;

    // Click outside to close
    setTimeout(() => {
        document.addEventListener('click', hideContextMenu, { once: true });
    }, 10);
}

export function hideContextMenu() {
    if (currentMenu) {
        currentMenu.remove();
        currentMenu = null;
    }
}
