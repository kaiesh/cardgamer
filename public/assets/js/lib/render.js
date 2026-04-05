/**
 * DOM helper utilities.
 */
export function el(tag, attrs = {}, children = []) {
    const element = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
        if (key === 'className') element.className = value;
        else if (key === 'style' && typeof value === 'object') Object.assign(element.style, value);
        else if (key.startsWith('on')) element.addEventListener(key.slice(2).toLowerCase(), value);
        else element.setAttribute(key, value);
    });
    children.forEach(child => {
        if (typeof child === 'string') element.appendChild(document.createTextNode(child));
        else if (child) element.appendChild(child);
    });
    return element;
}

export function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

export function show(element) { element.style.display = ''; }
export function hide(element) { element.style.display = 'none'; }
