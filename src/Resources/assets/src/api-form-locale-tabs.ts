document.addEventListener('DOMContentLoaded', () => {
  const tabList = document.getElementById('endpoint-form-locale-tabs');
  if (!tabList) {
    return;
  }

  const tabs = tabList.querySelectorAll<HTMLButtonElement>('[data-form-locale]');
  const panels = document.querySelectorAll<HTMLElement>('[data-form-locale-panel]');

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const locale = tab.getAttribute('data-form-locale');
      if (!locale) {
        return;
      }

      tabs.forEach((t) => {
        const isActive = t.getAttribute('data-form-locale') === locale;
        t.classList.toggle('is-active', isActive);
        t.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      panels.forEach((panel) => {
        const isActive = panel.getAttribute('data-form-locale-panel') === locale;
        panel.classList.toggle('is-active', isActive);
        panel.hidden = !isActive;
      });
    });
  });
});
