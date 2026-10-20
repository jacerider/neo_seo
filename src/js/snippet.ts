/**
 * Neo | SEO snippet input — a title or description with tokens as pills.
 *
 * Enhances a text field holding token text (`[node:title] [neo-seo:separator]
 * [site:name]`): an editable box shows each token as a named pill ("Title"),
 * the text between as text. The field itself stays in the form, visually
 * hidden and kept in sync, so saving, the SEO panel's hosts and no-JS work as
 * before; a write to it from elsewhere (the panel's "Use this") redraws.
 *
 * - Insert variable, or typing "[", opens a picker: the snippet variables (SEO
 *   settings) first, then a search over every token of the page's type.
 * - A per-page field carries the template it overrides: shown while the field
 *   is empty, and an edit that ends equal to it leaves the field empty. "Use
 *   template" clears an override.
 * - A pill's title gives its token and, after a draft render (neo-seo:render
 *   from the SEO panel), what it gives on this page. A token that does not
 *   resolve for the page's type is outlined in red.
 */

(function (Drupal, drupalSettings, once): void {

  interface SnippetConfig {
    types: string[];
    /** The template a per-page field overrides; null for a template field. */
    template: string | null;
  }

  interface Variable {
    label: string;
    token: string;
  }

  interface Settings {
    variables: Variable[];
    tokensUrl: string | null;
  }

  type Field = HTMLInputElement | HTMLTextAreaElement;

  const settings = ((drupalSettings as unknown as { neoSeoSnippet?: Settings }).neoSeoSnippet
    ?? { variables: [], tokensUrl: null });

  /** A token: [type:name], chained names allowed. */
  const TOKEN = /\[[^[\]\s:]+:[^[\]\s]+\]/g;

  /** Names and validity looked up so far, shared by every input. */
  const names = new Map<string, string>();
  const invalid = new Set<string>();
  /** What each token gave in the latest draft render. */
  const values = new Map<string, string>();
  const inputs = new Set<SnippetInput>();

  function h<K extends keyof HTMLElementTagNameMap>(
    tag: K,
    attributes: Record<string, string> = {},
    children: Array<Node | string | null> = [],
  ): HTMLElementTagNameMap[K] {
    const element = document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
    children.forEach((child) => child !== null && element.append(child));
    return element;
  }

  let uid = 0;
  function uniqueId(prefix: string): string {
    uid += 1;
    return `${prefix}-${uid}`;
  }

  /** Token text compared loosely: runs of space count as one. */
  function normalize(text: string): string {
    return text.replace(/\s+/g, ' ').trim();
  }

  /** A friendly variable's name, else a looked-up one, else the token. */
  function nameOf(token: string): string {
    return settings.variables.find((variable) => variable.token === token)?.label
      ?? names.get(token)
      ?? token;
  }

  /** Names and checks tokens not seen yet, in one request; then redraws. */
  let pendingTypes = new Set<string>();
  let pendingTokens = new Set<string>();
  let lookupTimer: number | undefined;
  function lookUp(tokens: string[], types: string[]): void {
    const unknown = tokens.filter((token) => !names.has(token) && !settings.variables.some((variable) => variable.token === token));
    if (!unknown.length || !settings.tokensUrl) {
      return;
    }
    unknown.forEach((token) => pendingTokens.add(token));
    types.forEach((type) => pendingTypes.add(type));
    window.clearTimeout(lookupTimer);
    lookupTimer = window.setTimeout(async () => {
      const url = new URL(settings.tokensUrl!, window.location.href);
      pendingTypes.forEach((type) => url.searchParams.append('types[]', type));
      pendingTokens.forEach((token) => url.searchParams.append('check[]', token));
      const asked = [...pendingTokens];
      pendingTypes = new Set();
      pendingTokens = new Set();
      try {
        const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const body = response.ok ? await response.json() as { names?: Record<string, string>; invalid?: string[] } : {};
        asked.forEach((token) => names.set(token, body.names?.[token] ?? token));
        (body.invalid ?? []).forEach((token) => invalid.add(token));
      }
      catch {
        asked.forEach((token) => names.set(token, token));
      }
      inputs.forEach((input) => input.refreshPills());
    }, 50);
  }

  /** Searches every token of some types. */
  async function search(q: string, types: string[]): Promise<Array<{ token: string; name: string }>> {
    if (!settings.tokensUrl || q.trim().length < 2) {
      return [];
    }
    const url = new URL(settings.tokensUrl, window.location.href);
    types.forEach((type) => url.searchParams.append('types[]', type));
    url.searchParams.set('q', q.trim());
    try {
      const response = await fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const body = response.ok ? await response.json() as { tokens?: Array<{ token: string; name: string }> } : {};
      return body.tokens ?? [];
    }
    catch {
      return [];
    }
  }

  /**
   * One field's snippet input.
   */
  class SnippetInput {

    readonly field: Field;
    readonly config: SnippetConfig;
    readonly editor: HTMLElement;
    protected readonly actions: HTMLElement;
    protected readonly reset: HTMLButtonElement | null = null;
    protected readonly state: HTMLElement | null = null;
    protected picker: Picker | null = null;
    /** Writing the field ourselves: its input event is not a write from elsewhere. */
    protected syncing = false;
    /** The caret when the picker opened, to insert at. */
    range: Range | null = null;

    constructor(field: Field, config: SnippetConfig) {
      this.field = field;
      this.config = config;
      const label = field.labels?.[0] ?? null;
      if (label && !label.id) {
        label.id = uniqueId('neo-seo-snippet-label');
      }
      this.editor = h('div', {
        class: `neo-seo-snippet__editor${field instanceof HTMLTextAreaElement ? ' neo-seo-snippet__editor--multi' : ''}`,
        contenteditable: 'true',
        role: 'textbox',
        spellcheck: 'true',
        'aria-multiline': 'false',
      });
      if (label) {
        this.editor.setAttribute('aria-labelledby', label.id);
      }
      const insert = h('button', { type: 'button', class: 'neo-seo-snippet__insert' }, [Drupal.t('Insert variable')]);
      insert.addEventListener('click', () => this.openPicker(''));
      this.actions = h('div', { class: 'neo-seo-snippet__actions' });
      if (config.template !== null) {
        this.state = h('span', { class: 'neo-seo-snippet__state' }, [Drupal.t('From template')]);
        this.reset = h('button', { type: 'button', class: 'neo-seo-snippet__reset' }, [Drupal.t('Use template')]);
        this.reset.addEventListener('click', () => {
          this.write('');
          this.draw('');
          this.editor.focus();
        });
        this.actions.append(this.state, this.reset);
      }
      this.actions.append(insert);
      field.classList.add('neo-seo-snippet__field');
      field.tabIndex = -1;
      field.setAttribute('aria-hidden', 'true');
      field.after(this.editor);
      (label ?? field).after(this.actions);
      field.closest<HTMLElement>('.form-item, .js-form-item')?.classList.add('neo-seo-snippet');

      // The field's label, and hosts that focus the field, land in the box.
      field.addEventListener('focus', () => this.editor.focus());
      // A write from elsewhere: an input event, or the SEO panel's quiet
      // neo-seo:written.
      const redraw = () => {
        if (!this.syncing) {
          this.draw(this.field.value);
        }
      };
      field.addEventListener('input', redraw);
      field.addEventListener('neo-seo:written', redraw);
      this.editor.addEventListener('focus', () => {
        const describedBy = this.field.getAttribute('aria-describedby');
        if (describedBy) {
          this.editor.setAttribute('aria-describedby', describedBy);
        }
      });
      this.editor.addEventListener('input', () => this.sync());
      this.editor.addEventListener('blur', () => {
        // Typed tokens become pills; an emptied field shows the template.
        window.setTimeout(() => {
          if (!this.picker?.isOpen() && document.activeElement !== this.editor) {
            this.draw(this.field.value);
          }
        }, 0);
      });
      this.editor.addEventListener('keydown', (event) => this.keydown(event));
      this.editor.addEventListener('paste', (event) => {
        event.preventDefault();
        const text = (event.clipboardData?.getData('text/plain') ?? '').replace(/\s+/g, ' ');
        this.insertNodes(this.parse(text));
      });
      this.draw(field.value);
      inputs.add(this);
    }

    /** Shows a value, or the template while the value is empty. */
    draw(value: string): void {
      const template = this.config.template;
      const fromTemplate = value.trim() === '' && !!template;
      this.editor.replaceChildren(...this.parse(fromTemplate ? template! : value));
      this.editor.classList.toggle('is-template', fromTemplate);
      this.updateState();
    }

    /** Text and pills from token text. */
    parse(text: string): Node[] {
      const nodes: Node[] = [];
      let last = 0;
      for (const match of text.matchAll(TOKEN)) {
        if (match.index! > last) {
          nodes.push(document.createTextNode(text.slice(last, match.index)));
        }
        nodes.push(this.pill(match[0]));
        last = match.index! + match[0].length;
      }
      if (last < text.length) {
        nodes.push(document.createTextNode(text.slice(last)));
      }
      lookUp([...text.matchAll(TOKEN)].map((match) => match[0]), this.config.types);
      return nodes;
    }

    pill(token: string): HTMLElement {
      const pill = h('span', { class: 'neo-seo-snippet__pill', contenteditable: 'false', 'data-token': token });
      this.dressPill(pill);
      return pill;
    }

    /** A pill's name, title and validity, as currently known. */
    dressPill(pill: HTMLElement): void {
      const token = pill.dataset.token ?? '';
      const name = nameOf(token);
      const bad = invalid.has(token);
      pill.textContent = name;
      pill.classList.toggle('is-invalid', bad);
      const value = values.get(token);
      pill.title = bad
        ? Drupal.t('@token does not resolve here and will print nothing.', { '@token': token })
        : value === undefined ? token : `${token} → ${value === '' ? Drupal.t('(empty)') : value}`;
      pill.setAttribute('aria-label', bad ? Drupal.t('@name (does not resolve)', { '@name': name }) : name);
    }

    refreshPills(): void {
      this.editor.querySelectorAll<HTMLElement>('.neo-seo-snippet__pill').forEach((pill) => this.dressPill(pill));
    }

    /** The box's content as token text. */
    serialize(): string {
      const walk = (node: Node): string => {
        if (node.nodeType === Node.TEXT_NODE) {
          return (node.textContent ?? '').replace(/[​ ]/g, (char) => (char === ' ' ? ' ' : ''));
        }
        if (node instanceof HTMLElement) {
          if (node.dataset.token) {
            return node.dataset.token;
          }
          if (node.tagName === 'BR') {
            return ' ';
          }
          const inner = Array.from(node.childNodes).map(walk).join('');
          return node.tagName === 'DIV' || node.tagName === 'P' ? ` ${inner}` : inner;
        }
        return '';
      };
      return Array.from(this.editor.childNodes).map(walk).join('').replace(/\s+/g, ' ').replace(/^ /, '');
    }

    /** The box changed: the field takes it, or nothing when it is the template. */
    sync(): void {
      const text = this.serialize();
      const template = this.config.template;
      const value = template && normalize(text) === normalize(template) ? '' : (normalize(text) === '' ? '' : text);
      this.editor.classList.toggle('is-template', !!template && value === '' && normalize(text) !== '');
      this.write(value);
    }

    write(value: string): void {
      if (this.field.value === value) {
        this.updateState();
        return;
      }
      this.syncing = true;
      this.field.value = value;
      this.field.dispatchEvent(new Event('input', { bubbles: true }));
      this.field.dispatchEvent(new Event('change', { bubbles: true }));
      this.syncing = false;
      this.updateState();
    }

    updateState(): void {
      if (this.config.template === null || !this.reset || !this.state) {
        return;
      }
      const overridden = this.field.value.trim() !== '';
      this.reset.hidden = !overridden || !this.config.template;
      this.state.hidden = overridden || !this.config.template;
    }

    keydown(event: KeyboardEvent): void {
      if (event.key === 'Enter') {
        // One line: a title or description never breaks.
        event.preventDefault();
        return;
      }
      if (event.key === '[' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        this.openPicker('');
      }
    }

    openPicker(query: string): void {
      const selection = window.getSelection();
      this.range = selection && selection.rangeCount && this.editor.contains(selection.anchorNode)
        ? selection.getRangeAt(0).cloneRange()
        : null;
      this.picker ??= new Picker(this);
      this.picker.open(query);
    }

    /** Inserts a token at the caret the picker opened on, spaced. */
    insertToken(token: string): void {
      const nodes: Node[] = [this.pill(token)];
      this.insertNodes(nodes, true);
    }

    insertNodes(nodes: Node[], spaced = false): void {
      this.editor.focus();
      const selection = window.getSelection();
      let range = this.range;
      if (!range || !this.editor.contains(range.startContainer)) {
        range = document.createRange();
        range.selectNodeContents(this.editor);
        range.collapse(false);
      }
      range.deleteContents();
      if (spaced) {
        const before = range.startContainer.nodeType === Node.TEXT_NODE
          ? (range.startContainer.textContent ?? '').slice(0, range.startOffset)
          : this.serialize();
        if (before !== '' && !/\s$/.test(before)) {
          nodes.unshift(document.createTextNode(' '));
        }
        nodes.push(document.createTextNode(' '));
      }
      let lastNode: Node | null = null;
      nodes.reverse().forEach((node) => {
        range!.insertNode(node);
        lastNode ??= node;
      });
      if (lastNode) {
        const after = document.createRange();
        after.setStartAfter(lastNode);
        after.collapse(true);
        selection?.removeAllRanges();
        selection?.addRange(after);
      }
      this.range = null;
      this.sync();
    }

  }

  /**
   * The variable picker: the snippet variables, then every token, searched.
   */
  class Picker {

    readonly input: SnippetInput;
    readonly element: HTMLElement;
    readonly search: HTMLInputElement;
    readonly list: HTMLElement;
    protected options: HTMLElement[] = [];
    protected active = -1;
    protected searchTimer: number | undefined;
    protected run = 0;

    constructor(input: SnippetInput) {
      this.input = input;
      const listId = uniqueId('neo-seo-snippet-list');
      this.search = h('input', {
        type: 'search',
        class: 'neo-seo-snippet__search',
        role: 'combobox',
        'aria-expanded': 'true',
        'aria-controls': listId,
        'aria-autocomplete': 'list',
        'aria-label': Drupal.t('Find a variable'),
        placeholder: Drupal.t('Find a variable…'),
        autocomplete: 'off',
      });
      this.list = h('ul', { class: 'neo-seo-snippet__options', id: listId, role: 'listbox', 'aria-label': Drupal.t('Variables') });
      this.element = h('div', { class: 'neo-seo-snippet__picker' }, [this.search, this.list]);
      this.element.hidden = true;
      input.editor.after(this.element);
      this.search.addEventListener('input', () => this.fill(this.search.value));
      this.search.addEventListener('keydown', (event) => this.keydown(event));
      document.addEventListener('mousedown', (event) => {
        if (this.isOpen() && !this.element.contains(event.target as Node) && !input.editor.contains(event.target as Node)) {
          this.close(false);
        }
      });
    }

    isOpen(): boolean {
      return !this.element.hidden;
    }

    open(query: string): void {
      this.element.hidden = false;
      this.search.value = query;
      this.fill(query);
      this.search.focus();
    }

    close(refocus: boolean): void {
      this.element.hidden = true;
      if (refocus) {
        this.input.editor.focus();
        const range = this.input.range;
        if (range) {
          const selection = window.getSelection();
          selection?.removeAllRanges();
          selection?.addRange(range);
        }
      }
    }

    /** The variables matching a query, then (two letters on) every token. */
    fill(query: string): void {
      const q = query.trim().toLowerCase();
      const variables = settings.variables.filter((variable) => q === ''
        || variable.label.toLowerCase().includes(q) || variable.token.toLowerCase().includes(q));
      const nodes: HTMLElement[] = [];
      if (variables.length) {
        nodes.push(h('li', { class: 'neo-seo-snippet__group', role: 'presentation' }, [Drupal.t('Variables')]));
        variables.forEach((variable) => nodes.push(this.option(variable.token, variable.label)));
      }
      this.list.replaceChildren(...nodes);
      this.collect();
      window.clearTimeout(this.searchTimer);
      if (q.length < 2) {
        if (!variables.length) {
          this.list.append(h('li', { class: 'neo-seo-snippet__empty', role: 'presentation' }, [Drupal.t('Type to search every token.')]));
        }
        return;
      }
      this.run += 1;
      const run = this.run;
      this.searchTimer = window.setTimeout(async () => {
        const found = (await search(q, this.input.config.types))
          .filter((token) => !variables.some((variable) => variable.token === token.token));
        if (run !== this.run) {
          return;
        }
        if (found.length) {
          this.list.append(h('li', { class: 'neo-seo-snippet__group', role: 'presentation' }, [Drupal.t('All tokens')]));
          found.forEach((token) => {
            names.set(token.token, token.name || token.token);
            this.list.append(this.option(token.token, token.name || token.token));
          });
        }
        else if (!variables.length) {
          this.list.append(h('li', { class: 'neo-seo-snippet__empty', role: 'presentation' }, [Drupal.t('No variable matches.')]));
        }
        this.collect();
      }, 200);
    }

    option(token: string, label: string): HTMLElement {
      const option = h('li', { class: 'neo-seo-snippet__option', role: 'option', id: uniqueId('neo-seo-snippet-option'), 'data-token': token }, [
        h('span', { class: 'neo-seo-snippet__option-name' }, [label]),
        h('span', { class: 'neo-seo-snippet__option-token' }, [token]),
      ]);
      option.addEventListener('mousedown', (event) => event.preventDefault());
      option.addEventListener('click', () => this.choose(token));
      return option;
    }

    collect(): void {
      this.options = Array.from(this.list.querySelectorAll<HTMLElement>('[role="option"]'));
      this.highlight(this.options.length ? 0 : -1);
    }

    highlight(index: number): void {
      this.options.forEach((option, i) => option.setAttribute('aria-selected', i === index ? 'true' : 'false'));
      this.active = index;
      const option = this.options[index];
      if (option) {
        this.search.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({ block: 'nearest' });
      }
      else {
        this.search.removeAttribute('aria-activedescendant');
      }
    }

    keydown(event: KeyboardEvent): void {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (this.options.length) {
          const step = event.key === 'ArrowDown' ? 1 : -1;
          this.highlight((this.active + step + this.options.length) % this.options.length);
        }
      }
      else if (event.key === 'Enter') {
        event.preventDefault();
        const token = this.options[this.active]?.dataset.token;
        if (token) {
          this.choose(token);
        }
      }
      else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close(true);
      }
    }

    choose(token: string): void {
      this.element.hidden = true;
      this.input.insertToken(token);
    }

  }

  // What each token gives on the page, from the SEO panel's draft renders.
  document.addEventListener('neo-seo:render', (event) => {
    const tokens = (event as CustomEvent<{ tokens?: Record<string, string> }>).detail?.tokens ?? {};
    Object.entries(tokens).forEach(([token, value]) => values.set(token, value));
    inputs.forEach((input) => input.refreshPills());
  });

  Drupal.behaviors.neoSeoSnippet = {
    attach(context: HTMLElement) {
      once('neo-seo-snippet', '[data-neo-seo-snippet]', context).forEach((element) => {
        if (!(element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement)) {
          return;
        }
        let config: SnippetConfig;
        try {
          config = JSON.parse(element.dataset.neoSeoSnippet ?? '{}') as SnippetConfig;
        }
        catch {
          return;
        }
        new SnippetInput(element, { types: config.types ?? [], template: config.template ?? null });
      });
    },
  };

})(Drupal, drupalSettings, once);
