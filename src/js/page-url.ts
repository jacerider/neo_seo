/**
 * Neo | SEO — the page URL field's browser half (PageUrlField).
 *
 * As an editor types (or pastes) a slug or custom URL, spaces become the
 * separator and letters lowercase, as pathauto writes them, and a slug's
 * slashes become the separator too: a slug is one segment. Nothing typed is
 * refused; the rest (punctuation, accents, ignored words) is cleaned when the
 * editor leaves the input, by the site's own rules (the SEO panel).
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

  /**
   * An address as pathauto writes it, as far as typing can be followed.
   *
   * A space (or a slug's slash) right after a separator adds nothing, so
   * "water / plant" typed reads water-plant; one typed before a separator
   * still makes its own, so a word can go in front of a hyphen. Anything
   * left doubled is cleaned on leaving.
   */
  function written(value: string, input: HTMLInputElement): string {
    const separator = input.dataset.neoSeoUrlSeparator || '-';
    const spaces = input.dataset.neoSeoUrlInput === 'slug' ? '[\\s/]+' : '\\s+';
    const after = new RegExp(`(${separator.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})${spaces}`, 'g');
    const text = value.replace(after, '$1').replace(new RegExp(spaces, 'g'), separator);
    return input.dataset.neoSeoUrlLowercase === '1' ? text.toLowerCase() : text;
  }

  // First, so whatever listens for the edit (the SEO panel, Alchemist's
  // draft) reads the address as written.
  document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-neo-seo-url-separator') || (event as InputEvent).isComposing) {
      return;
    }
    const value = written(input.value, input);
    if (value === input.value) {
      return;
    }
    // The caret stays after what was typed: the text before it, written.
    const caret = input.selectionStart === null ? null : written(input.value.slice(0, input.selectionStart), input).length;
    input.value = value;
    if (caret !== null) {
      input.setSelectionRange(caret, caret);
    }
  }, true);

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
