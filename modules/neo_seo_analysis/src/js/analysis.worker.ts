/**
 * The analysis engine in a Web Worker: one analyze() per message, answered
 * with the message's id so the panel can drop answers it no longer needs.
 *
 * Bundled by Vite into analysis.ts as an inline worker; not an entrypoint, so
 * not in the TypeScript include list (the engine's `.ts` import specifiers,
 * which node's test runner needs, are a TS5097 error under the site's
 * compiler options).
 */
import { analyze } from './engine/index.ts';
import type { AnalysisInput } from './engine/types.ts';

interface Request {
  id: number;
  input: AnalysisInput;
}

const scope = self as unknown as {
  onmessage: ((event: MessageEvent<Request>) => void) | null;
  postMessage(message: unknown): void;
};

scope.onmessage = (event) => {
  const { id, input } = event.data;
  try {
    scope.postMessage({ id, result: analyze(input) });
  }
  catch (error) {
    scope.postMessage({ id, error: String(error) });
  }
};
