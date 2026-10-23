/**
 * Neo | SEO — the page URL field's browser half (PageUrlField).
 *
 * Copy puts the page's address on the clipboard and says so to a screen
 * reader. "Keep current address" puts the URL controls back to the values
 * that keep the page where it is, as if typed, so whatever listens for edits
 * (the SEO panel, Alchemist's draft) hears it. Both are listened for on the
 * document, so the field works wherever it is rendered: the edit form, or the
 * Alchemist panel loaded into the editor.
 */
(function (Drupal): void {

  interface Keep {
    url_custom: boolean;
    slug: string;
    url_alias: string;
  }

  /** Sets a control as if the editor had. */
  function set(control: HTMLInputElement | null, apply: (control: HTMLInputElement) => void): void {
    if (control) {
      apply(control);
      control.dispatchEvent(new Event('input', { bubbles: true }));
      control.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  document.addEventListener('click', (event) => {
    const button = (event.target as Element | null)?.closest<HTMLButtonElement>('[data-neo-seo-url-keep]');
    const field = button?.closest('form');
    if (!button || !field) {
      return;
    }
    const keep = JSON.parse(button.dataset.neoSeoUrlKeep ?? '{}') as Keep;
    set(field.querySelector<HTMLInputElement>(`input[type="radio"][name$="[url_custom]"][value="${keep.url_custom ? '1' : '0'}"]`), (radio) => {
      radio.checked = true;
    });
    set(field.querySelector<HTMLInputElement>('input[data-neo-seo-url-input="slug"]'), (input) => {
      input.value = keep.slug;
    });
    set(field.querySelector<HTMLInputElement>('input[data-neo-seo-url-input="alias"]:not([disabled])'), (input) => {
      input.value = keep.url_alias;
    });
    // Gone until a render says otherwise.
    button.closest<HTMLElement>('.neo-seo-url-callout')?.setAttribute('hidden', '');
  });

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
