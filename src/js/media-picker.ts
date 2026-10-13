/**
 * Neo | SEO — the media picker's browser half (the neo_seo_media element).
 *
 * The picker's value is a hidden input. A choice made in the media library
 * comes back as the neoSeoMediaPicked command; Remove clears it. Either way
 * the input fires real input and change events, so whatever listens for
 * edits (the SEO panel's hosts) hears it like any other field.
 */
(function (Drupal, once): void {

  function input(key: string): HTMLInputElement | null {
    return document.querySelector<HTMLInputElement>(`input[data-neo-seo-media="${CSS.escape(key)}"]`);
  }

  function set(key: string, value: string): void {
    const control = input(key);
    if (control) {
      control.value = value;
      control.dispatchEvent(new Event('input', { bubbles: true }));
      control.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const remove = document.querySelector<HTMLElement>(`[data-neo-seo-media-remove="${CSS.escape(key)}"]`);
    if (remove) {
      remove.hidden = value === '';
    }
  }

  if (Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.neoSeoMediaPicked = function (_ajax: unknown, response: { picker: string; media: string }) {
      set(response.picker, response.media);
    };
  }

  Drupal.behaviors.neoSeoMediaPicker = {
    attach(context: HTMLElement) {
      once('neo-seo-media-remove', '[data-neo-seo-media-remove]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const key = button.dataset.neoSeoMediaRemove ?? '';
          const preview = document.querySelector<HTMLElement>(`[data-neo-seo-media-preview="${CSS.escape(key)}"]`);
          if (preview) {
            preview.innerHTML = `<span class="neo-seo-media__empty">${Drupal.checkPlain(Drupal.t('No image chosen.'))}</span>`;
          }
          set(key, '');
        });
      });
    },
  };

})(Drupal, once);
