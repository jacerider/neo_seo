/**
 * Neo | SEO — the page URL field's browser half (PageUrl).
 *
 * Copy puts the page's address on the clipboard and says so to a screen
 * reader. Listened for on the document, so the field works wherever it is
 * rendered: the edit form, or the Alchemist panel loaded into the editor.
 */
(function (Drupal): void {

  /** How long the copy button shows that it copied. */
  const COPIED_FOR = 1500;

  document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-neo-seo-url-copy]');
    const url = button?.dataset.neoSeoUrlCopy;
    if (!button || !url) {
      return;
    }
    const status = button.parentElement?.querySelector<HTMLElement>('[data-neo-seo-url-copied]');
    navigator.clipboard.writeText(url).then(() => {
      button.classList.add('is-copied');
      if (status) {
        status.textContent = Drupal.t('Address copied.');
      }
      window.setTimeout(() => {
        button.classList.remove('is-copied');
        if (status) {
          status.textContent = '';
        }
      }, COPIED_FOR);
    }).catch((e: unknown) => {
      console.error(e);
      if (status) {
        status.textContent = Drupal.t('The address could not be copied.');
      }
    });
  });

})(Drupal);
