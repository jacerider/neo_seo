/**
 * English word lists used by the engine.
 *
 * Compiled for this package from general English grammar; all entries are
 * lowercase and diacritic-free so they compare against normalizeWord() output.
 */

/**
 * Function words: ignored when counting a keyphrase's length and when checking
 * that "all keyphrase words" appear somewhere.
 */
export const FUNCTION_WORDS: ReadonlySet<string> = new Set([
  'a', 'an', 'the', 'and', 'or', 'but', 'nor', 'of', 'in', 'on', 'at', 'to', 'for', 'with',
  'by', 'from', 'as', 'into', 'onto', 'about', 'over', 'under', 'than', 'then', 'so', 'if',
  'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'do', 'does', 'did', 'has', 'have',
  'had', 'it', 'its', "it's", 'this', 'that', 'these', 'those', 'i', 'me', 'my', 'we', 'us',
  'our', 'you', 'your', 'he', 'him', 'his', 'she', 'her', 'they', 'them', 'their', 'who',
  'whom', 'whose', 'which', 'what', 'when', 'where', 'why', 'how', 'can', 'could', 'will',
  'would', 'shall', 'should', 'may', 'might', 'must', 'not', 'no', 'yes', 'very', 'just',
  'also', 'too', 'some', 'any', 'all', 'each', 'every', 'more', 'most', 'much', 'many',
  'such', 'own', 'same', 'other', 'there', 'here', 'up', 'out', 'off', 'via', 'per',
]);

/** Forms of "to be" that start a passive construction. */
export const BE_FORMS: ReadonlySet<string> = new Set([
  'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being', "isn't", "aren't", "wasn't",
  "weren't", "ain't",
]);

/** Contracted forms that end in a "to be" auxiliary ("they're", "we're", "you're"). */
export const BE_SUFFIXES: readonly string[] = ["'re"];

/**
 * Words that may sit between the auxiliary and the participle without breaking
 * the passive ("was not built", "is often used", "has been quickly approved").
 * Any word ending in -ly also qualifies.
 */
export const PASSIVE_INTERVENERS: ReadonlySet<string> = new Set([
  'not', 'never', 'also', 'always', 'often', 'still', 'already', 'just', 'now', 'then', 'even',
  'ever', 'soon', 'well', 'all', 'both', 'too', 'almost', 'seldom', 'sometimes', 'once',
  'first', 'being', 'been', 'further', 'thus', 'therefore', 'currently', 'only',
]);

/** Irregular past participles. */
export const IRREGULAR_PARTICIPLES: ReadonlySet<string> = new Set([
  'arisen', 'awoken', 'beaten', 'become', 'begun', 'bent', 'bet', 'bid', 'bitten', 'bled',
  'blown', 'born', 'borne', 'bought', 'bound', 'bred', 'broadcast', 'broken', 'brought',
  'built', 'burnt', 'burst', 'cast', 'caught', 'chosen', 'clung', 'come', 'cost', 'crept',
  'cut', 'dealt', 'done', 'drawn', 'dreamt', 'driven', 'drunk', 'dug', 'eaten', 'fallen',
  'fed', 'felt', 'fled', 'flung', 'flown', 'forbidden', 'forecast', 'foreseen', 'forgiven',
  'forgotten', 'forsaken', 'found', 'frozen', 'given', 'gone', 'gotten', 'ground', 'grown',
  'held', 'hidden', 'hit', 'hung', 'heard', 'hurt', 'kept', 'knelt', 'knit', 'known', 'laid',
  'lain', 'led', 'left', 'lent', 'let', 'lit', 'lost', 'made', 'meant', 'met', 'mislaid',
  'misled', 'mistaken', 'misunderstood', 'mown', 'overcome', 'overdone', 'overheard',
  'overrun', 'overseen', 'overtaken', 'overthrown', 'paid', 'proven', 'put', 'quit', 'read',
  'rebuilt', 'redone', 'remade', 'rewritten', 'rid', 'ridden', 'risen', 'rung', 'run', 'said',
  'sawn', 'seen', 'sent', 'set', 'sewn', 'shaken', 'shaven', 'shed', 'shod', 'shone', 'shorn',
  'shot', 'shown', 'shrunk', 'shut', 'slain', 'slept', 'slid', 'slung', 'slit', 'sold',
  'sought', 'sown', 'sped', 'spent', 'spilt', 'spoken', 'spread', 'sprung', 'spun', 'stolen',
  'stood', 'struck', 'strung', 'stuck', 'stung', 'stunk', 'sung', 'sunk', 'sworn', 'swept',
  'swollen', 'swum', 'swung', 'taken', 'taught', 'thought', 'thrown', 'thrust', 'told', 'torn',
  'trodden', 'understood', 'undergone', 'undertaken', 'undone', 'upheld', 'upset', 'withdrawn',
  'withheld', 'woken', 'won', 'worn', 'woven', 'wound', 'written', 'wrung',
]);

/** Words ending in -ed that are not past participles. */
export const NOT_PARTICIPLES: ReadonlySet<string> = new Set([
  'bed', 'red', 'need', 'seed', 'feed', 'speed', 'deed', 'weed', 'reed', 'creed', 'greed',
  'steed', 'breed', 'bleed', 'heed', 'indeed', 'proceed', 'succeed', 'exceed', 'embed',
  'hundred', 'kindred', 'hatred', 'sacred', 'naked', 'wicked', 'rugged', 'ragged', 'shred',
  'sled', 'ted', 'med', 'ed',
]);

/** Single transition words: a sentence containing one counts as a transition sentence. */
export const TRANSITION_WORDS: ReadonlySet<string> = new Set([
  'accordingly', 'additionally', 'afterward', 'afterwards', 'also', 'alternatively',
  'although', 'altogether', 'because', 'besides', 'consequently', 'conversely',
  'correspondingly', 'eventually', 'finally', 'first', 'firstly', 'further', 'furthermore',
  'hence', 'however', 'indeed', 'initially', 'instead', 'lastly', 'later', 'likewise',
  'meanwhile', 'moreover', 'namely', 'nevertheless', 'next', 'nonetheless', 'notably',
  'otherwise', 'overall', 'particularly', 'previously', 'second', 'secondly', 'similarly',
  'since', 'specifically', 'subsequently', 'then', 'therefore', 'third', 'thirdly', 'though',
  'thus', 'ultimately', 'unless', 'whereas', 'while', 'yet', 'especially', 'certainly',
  'clearly', 'undoubtedly', 'obviously', 'surely', 'admittedly', 'equally', 'importantly',
  'briefly', 'conclusively', 'thereby', 'whenever', 'until',
]);

/** Multi-word transition phrases, matched as whole-word sequences. */
export const TRANSITION_PHRASES: readonly string[] = [
  'above all', 'after all', 'all in all', 'as a consequence', 'as a result', 'as long as',
  'as soon as', 'as well as', 'at the same time', 'because of', 'by contrast', 'by the same token',
  'due to', 'even if', 'even so', 'even though', 'first of all', 'for example',
  'for instance', 'for this reason', 'in addition', 'in conclusion', 'in contrast', 'in fact',
  'in general', 'in other words', 'in particular', 'in short', 'in spite of', 'in summary',
  'in the same way', 'in the meantime', 'in turn', 'not only', 'on the contrary',
  'on the other hand', 'rather than', 'so that', 'such as', 'to begin with', 'to conclude',
  'to illustrate', 'to summarize', 'to sum up', 'with this in mind', 'in the end',
  'in other cases', 'as shown', 'as noted', 'in order to', 'in the first place',
];

/**
 * Sentence-start words too common to judge alone: when a sentence starts with
 * one, the next word is included ("The dog …" vs "The cat …" differ).
 */
export const BEGINNING_EXTENDERS: ReadonlySet<string> = new Set([
  'the', 'a', 'an', 'this', 'that', 'these', 'those', 'one', 'two', 'three', 'four', 'five',
  'six', 'seven', 'eight', 'nine', 'ten',
]);

/** Abbreviations whose trailing period does not end a sentence. */
export const ABBREVIATIONS: ReadonlySet<string> = new Set([
  'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr', 'st', 'vs', 'etc', 'e.g', 'i.e', 'inc', 'ltd',
  'co', 'corp', 'fig', 'approx', 'dept', 'est', 'mt', 'ft', 'jan', 'feb', 'mar', 'apr',
  'jun', 'jul', 'aug', 'sep', 'sept', 'oct', 'nov', 'dec', 'ca', 'cf', 'al', 'gen', 'gov',
  'rev', 'sgt', 'capt', 'lt', 'col', 'vol', 'ed', 'eds', 'p', 'pp', 'u.s', 'a.m', 'p.m',
]);
