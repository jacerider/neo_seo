/**
 * The SEO state of one Alchemist editor: its host and its latest draft render.
 *
 * Started at editor load — by the panel's top bar badge when there is one,
 * else when the panel first opens — and kept current after every layout write
 * and every SEO draft change, whether or not the panel was ever opened. The
 * panel's previews and the badge read the same render, so there is one
 * request per change rather than one per reader.
 */

import { debounce } from './dom';
import { AlchemistHost } from './hosts/alchemist';

/** Overrides that change nothing the draft render shows. */
const RENDERLESS: NeoSeoOverrideKey[] = ['keyphrase', 'cornerstone', 'seo_score', 'readability_score'];

/** Pause after typing in an override before rendering again. */
const OVERRIDE_WAIT = 600;
/** Pause after a layout write; the previews have already reloaded. */
const CONTENT_WAIT = 300;

export class AlchemistEditor implements NeoSeoEditor {

  readonly host: AlchemistHost;
  readonly config: NeoSeoPanelConfig;
  protected render: NeoSeoDraftRender | null = null;
  protected rendering = false;
  protected again = false;
  protected readonly renderListeners: Array<(render: NeoSeoDraftRender) => void> = [];
  protected readonly stateListeners: Array<(state: NeoSeoEditorState) => void> = [];
  protected readonly renderSoon = debounce(() => this.refresh(), OVERRIDE_WAIT);
  protected readonly renderLater = debounce(() => this.refresh(), CONTENT_WAIT);

  constructor(form: HTMLElement, api: NeoAlchemistPanelApi, config: NeoSeoPanelConfig) {
    this.config = config;
    this.host = new AlchemistHost(form, api, config);
    this.host.onChange((change) => {
      if (change.source === 'content') {
        this.notifyState('updating');
        this.renderLater();
      }
      else if (!change.key || !RENDERLESS.includes(change.key)) {
        this.notifyState('updating');
        this.renderLater.cancel();
        this.renderSoon();
      }
    });
    this.refresh();
  }

  latest(): NeoSeoDraftRender | null {
    return this.render;
  }

  onRender(callback: (render: NeoSeoDraftRender) => void): void {
    this.renderListeners.push(callback);
  }

  onState(callback: (state: NeoSeoEditorState) => void): void {
    this.stateListeners.push(callback);
  }

  addRoot(root: HTMLElement): void {
    this.host.addRoot(root);
  }

  /**
   * Renders now, or again as soon as the render in flight returns.
   */
  async refresh(): Promise<void> {
    if (this.rendering) {
      this.again = true;
      return;
    }
    this.rendering = true;
    this.again = false;
    this.notifyState('updating');
    try {
      const render = await this.host.render();
      this.render = render;
      this.renderListeners.forEach((listener) => {
        try {
          listener(render);
        }
        catch (e) {
          console.error(e);
        }
      });
      if (!this.again) {
        this.notifyState('ready');
      }
    }
    catch (e) {
      console.error(e);
      this.notifyState('error');
    }
    finally {
      this.rendering = false;
      if (this.again) {
        this.refresh();
      }
    }
  }

  protected notifyState(state: NeoSeoEditorState): void {
    this.stateListeners.forEach((listener) => {
      try {
        listener(state);
      }
      catch (e) {
        console.error(e);
      }
    });
  }

}
