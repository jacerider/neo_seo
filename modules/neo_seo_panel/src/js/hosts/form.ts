/**
 * The edit form host: the overrides are the form's own inputs.
 *
 * The real inputs stay where they are and save with the form; the panel only
 * reads them and writes the analysis scores into their hidden inputs. A render
 * presses neo_seo's hidden "Render draft" button, which builds the entity from
 * the form as it stands and answers with the neoSeoDraftRender command.
 */

import * as controls from '../controls';

/** The hidden button neo_seo adds to the form (DraftRenderHooks::BUTTON). */
const BUTTON = 'neo_seo_draft_render';

/** A render that has not answered in this long has failed. */
const TIMEOUT = 30000;

interface Waiter {
  resolve(render: NeoSeoDraftRender): void;
  reject(error: Error): void;
  timer: number;
}

interface AjaxInstance {
  element?: HTMLElement;
  ajaxing?: boolean;
}

const hosts = new WeakMap<HTMLFormElement, FormHost>();
let commandRegistered = false;

/**
 * The AJAX command answering the hidden button: hands the render to the host
 * of the form the button belongs to.
 */
function registerCommand(): void {
  if (commandRegistered || !Drupal.AjaxCommands) {
    return;
  }
  commandRegistered = true;
  Drupal.AjaxCommands.prototype.neoSeoDraftRender = function (ajax: AjaxInstance, response: { render: NeoSeoDraftRender }) {
    const form = ajax.element?.closest('form');
    if (form) {
      hosts.get(form)?.resolve(response.render);
    }
  } as unknown as drupal.Core.IAjaxCommand;

  // A response that carried no render (an error page, a validation message)
  // must not leave the panel waiting. Drupal runs a response's commands in a
  // promise chain, after this event, so look for the command in the response.
  jQuery(document).on('ajaxComplete', (_event, xhr, settings) => {
    if (
      typeof settings.data === 'string'
      && settings.data.includes(`_triggering_element_name=${BUTTON}`)
      && !(xhr.responseText ?? '').includes('"command":"neoSeoDraftRender"')
    ) {
      document.querySelectorAll('form').forEach((form) => {
        hosts.get(form)?.reject(new Error('The draft render returned no render.'));
      });
    }
  });
}

export class FormHost implements NeoSeoPanelHost {

  readonly kind = 'form';

  protected readonly form: HTMLFormElement;
  protected waiter: Waiter | null = null;

  protected readonly scope: HTMLElement;

  constructor(mount: HTMLElement, scope: HTMLElement) {
    this.scope = scope;
    const form = mount.closest('form');
    if (!form) {
      throw new Error('The SEO panel needs a form to mount in.');
    }
    this.form = form;
    hosts.set(form, this);
    registerCommand();
  }

  read(): NeoSeoOverrides {
    return controls.read(this.scope);
  }

  write(values: Partial<NeoSeoOverrides>, options: { quiet?: boolean } = {}): void {
    controls.write(this.scope, values, !options.quiet);
  }

  control(key: NeoSeoOverrideKey): HTMLElement | null {
    return controls.wrapper(this.scope, key);
  }

  render(): Promise<NeoSeoDraftRender> {
    const button = this.button();
    if (!button) {
      return Promise.reject(new Error('The form has no draft render button.'));
    }
    this.reject(new Error('Superseded by a newer render.'));
    return new Promise<NeoSeoDraftRender>((resolve, reject) => {
      this.waiter = {
        resolve,
        reject,
        timer: window.setTimeout(() => this.reject(new Error('The draft render timed out.')), TIMEOUT),
      };
      this.press(button);
    });
  }

  /**
   * The draft render button Drupal's AJAX is bound to.
   *
   * Found through the AJAX instance, not by name alone: neo_back mirrors the
   * form's actions into its sticky header, and the mirrored copy carries the
   * same name but no AJAX.
   */
  protected button(): HTMLElement | null {
    return this.instance()?.element
      ?? this.form.querySelector<HTMLElement>(`[name="${BUTTON}"][data-once~="drupal-ajax"]`);
  }

  protected instance(): AjaxInstance | undefined {
    return (Drupal.ajax.instances as unknown as Array<AjaxInstance | null>).find((candidate) => {
      const element = candidate?.element;
      return element?.getAttribute('name') === BUTTON && element.closest('form') === this.form;
    }) ?? undefined;
  }

  /**
   * Presses the hidden button once Drupal's AJAX for it is free: a press
   * while a request is in flight is silently dropped by Drupal.
   */
  protected press(button: HTMLElement, attempt = 0): void {
    const instance = this.instance();
    if (instance?.ajaxing && attempt < 100) {
      window.setTimeout(() => this.press(button, attempt + 1), 100);
      return;
    }
    jQuery(button).trigger('mousedown');
  }

  resolve(render: NeoSeoDraftRender): void {
    const waiter = this.waiter;
    this.waiter = null;
    if (waiter) {
      window.clearTimeout(waiter.timer);
      waiter.resolve(render);
    }
  }

  reject(error: Error): void {
    const waiter = this.waiter;
    this.waiter = null;
    if (waiter) {
      window.clearTimeout(waiter.timer);
      waiter.reject(error);
    }
  }

  onChange(callback: (change: NeoSeoHostChange) => void): void {
    const listener = (event: Event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      // An override control, wherever the panel or an extension placed it.
      const key = controls.keyOf(target);
      if (key) {
        callback({ source: 'override', key });
        return;
      }
      if (target.closest('.neo-seo-panel, .neo-seo-panel__sections')) {
        return;
      }
      // Inside the SEO element but not an override (an autocomplete's search
      // box): nothing the page shows has changed yet.
      if (this.scope.contains(target)) {
        return;
      }
      callback({ source: 'content' });
    };
    this.form.addEventListener('input', listener);
    this.form.addEventListener('change', listener);
  }

}
