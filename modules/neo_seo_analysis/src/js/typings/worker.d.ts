/**
 * Vite's inline worker import: the module is bundled as a worker and inlined
 * into the importing chunk as a Blob URL. See analysis.ts for why inline.
 */
declare module '*?worker&inline' {
  const WorkerConstructor: {
    new (options?: { name?: string }): Worker;
  };
  export default WorkerConstructor;
}
