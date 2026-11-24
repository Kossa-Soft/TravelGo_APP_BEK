const sidebar = document.querySelector('.rail');
const toggle = sidebar.querySelector('.rail-toggle');
const compactScreen = window.matchMedia('(max-width: 52rem)');

function setSidebarExpanded(expanded) {
    sidebar.classList.toggle('is-collapsed', !expanded);
    toggle.setAttribute('aria-expanded', String(expanded));
    toggle.setAttribute('aria-label', expanded ? 'Свернуть боковую панель' : 'Развернуть боковую панель');
}

setSidebarExpanded(!compactScreen.matches);
toggle.hidden = false;

toggle.addEventListener('click', () => {
    setSidebarExpanded(toggle.getAttribute('aria-expanded') === 'false');
});

compactScreen.addEventListener('change', (event) => {
    setSidebarExpanded(!event.matches);
});
