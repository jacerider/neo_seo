/**
 * Public types of the neo_seo content-analysis engine.
 *
 * Everything here is plain data so a result can cross a Web Worker boundary
 * (structured clone) unchanged.
 */

/** How an assessment rates the page. */
export type Band = 'good' | 'ok' | 'problem';

/**
 * Something the editor can locate in the page later. Plain data only: the
 * panel decides how to find and mark it.
 */
export type Highlight =
  | { kind: 'sentence'; text: string }
  | { kind: 'paragraph'; text: string }
  | { kind: 'heading'; level: number; text: string }
  | { kind: 'image'; src: string; alt: string | null }
  | { kind: 'link'; href: string; text: string };

export interface Assessment {
  /** Stable machine id, e.g. 'keyphraseInTitle'. */
  id: string;
  band: Band;
  /** English, editor-facing explanation of the result. */
  message: string;
  highlights?: Highlight[];
}

export interface AnalysisInput {
  /** Rendered page body HTML (the draft render). */
  html: string;
  /** Resolved SEO title. */
  title: string;
  /** Resolved meta description. */
  description: string;
  /** Absolute or path URL; the slug is its last path segment. */
  url: string;
  /** Focus keyphrase; '' means none is set. */
  keyphrase: string;
  /** Cornerstone content gets stricter thresholds. */
  cornerstone: boolean;
  /** Language code: 'en', 'en-US', 'fr' … */
  language: string;
  /** Host used to tell internal from outbound links, e.g. 'example.com'. */
  siteHost?: string;
  /** Focus keyphrases used on OTHER pages; omit to skip that assessment. */
  usedKeyphrases?: string[];
}

export interface SeoResult {
  /** 0–100, see score.ts. */
  score: number;
  assessments: Assessment[];
}

export interface ReadabilityResult {
  /** False when the language is not supported; then assessments is empty. */
  available: boolean;
  /** 0–100, see score.ts; 0 when not available. */
  score: number;
  assessments: Assessment[];
  /** Why readability is unavailable, when it is. */
  message?: string;
}

export interface AnalysisResult {
  seo: SeoResult;
  readability: ReadabilityResult;
}
