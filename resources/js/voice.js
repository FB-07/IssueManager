/**
 * Clean-up of what the browser's speech recognition returns, so dictated text reads like typed text:
 *  - spoken punctuation and line breaks ("vírgula", "ponto final", "nova linha", "question mark"…)
 *  - spoken issue keys: "dev doze", "D E V traço 12", "dev 12", "2010043 traço 1989" become DEV-12 / 2010043-1989
 *  - spacing around punctuation and capital letters at the start of sentences
 * Pure functions (no DOM), so they are easy to test.
 */

const UNITS = {
    zero: 0, um: 1, uma: 1, dois: 2, duas: 2, três: 3, tres: 3, quatro: 4, cinco: 5, seis: 6, sete: 7, oito: 8, nove: 9, dez: 10,
    onze: 11, doze: 12, treze: 13, catorze: 14, quatorze: 14, quinze: 15, dezasseis: 16, dezesseis: 16, dezassete: 17, dezessete: 17, dezoito: 18, dezanove: 19, dezenove: 19,
    one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, seven: 7, eight: 8, nine: 9, ten: 10, eleven: 11, twelve: 12, thirteen: 13, fourteen: 14, fifteen: 15,
    sixteen: 16, seventeen: 17, eighteen: 18, nineteen: 19,
};
const TENS = {
    vinte: 20, trinta: 30, quarenta: 40, cinquenta: 50, sessenta: 60, setenta: 70, oitenta: 80, noventa: 90,
    twenty: 20, thirty: 30, forty: 40, fifty: 50, sixty: 60, seventy: 70, eighty: 80, ninety: 90,
};
const NUMBER_WORDS = [...Object.keys(TENS), ...Object.keys(UNITS)].sort((a, b) => b.length - a.length);
// "vinte e cinco", "twenty five", "doze", "12"
const NUMBER = `(?:\\d{1,6}|(?:${Object.keys(TENS).join('|')})(?:\\s+(?:e\\s+)?(?:${Object.keys(UNITS).join('|')}))?|(?:${NUMBER_WORDS.join('|')}))`;
const DASH = '(?:-|–|—|traço|hífen|hifen|menos|dash|hyphen)';

/** "vinte e cinco" -> 25, "12" -> 12 (null when it is not a number) */
export function spokenNumber(text) {
    const t = text.trim().toLowerCase();
    if (/^\d+$/.test(t)) return Number(t);
    const parts = t.split(/\s+(?:e\s+)?/);
    if (parts.length === 1) return parts[0] in UNITS ? UNITS[parts[0]] : parts[0] in TENS ? TENS[parts[0]] : null;
    if (parts.length === 2 && parts[0] in TENS && parts[1] in UNITS && UNITS[parts[1]] < 10) return TENS[parts[0]] + UNITS[parts[1]];

    return null;
}

const escRe = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Issue ids said aloud, for the known project keys. */
export function fixSpokenKeys(text, keys = []) {
    let out = text;
    for (const key of [...keys].sort((a, b) => b.length - a.length)) {
        // the key as a word, or its letters spelled one by one ("d e v")
        const spelled = /^[A-Za-z]+$/.test(key) ? key.split('').map(escRe).join('\\s*') : escRe(key);
        const word = escRe(key);
        const re = new RegExp(`(?<![\\w-])(?:${word}|${spelled})\\s*(?:${DASH}\\s*)?(${NUMBER})(?![\\w-])`, 'gi');
        out = out.replace(re, (m, n) => {
            const num = spokenNumber(n);

            return num === null ? m : `${key.toUpperCase()}-${num}`;
        });
    }

    return out;
}

// longest first, so "ponto de interrogação" is not read as "ponto"
const SPOKEN = [
    // [pattern, replacement]: replacement is attached to the previous word (no space before) unless it starts a line
    ['ponto\\s+de\\s+interrogação', '?'], ['ponto\\s+de\\s+exclamação', '!'], ['ponto\\s+e\\s+vírgula', ';'], ['ponto\\s+final', '.'], ['dois\\s+pontos', ':'],
    ['reticências', '…'], ['vírgula', ','],
    ['question\\s+mark', '?'], ['exclamation\\s+(?:mark|point)', '!'], ['full\\s+stop', '.'], ['semi-?colon', ';'], ['colon', ':'], ['comma', ','], ['ellipsis', '…'],
];
const BREAKS = [['novo\\s+parágrafo|new\\s+paragraph', '\n\n'], ['nova\\s+linha|new\\s+line', '\n']];
const OPEN = ['abre\\s+parênteses|abrir\\s+parênteses|open\\s+(?:parenthesis|bracket)'];
const CLOSE = ['fecha\\s+parênteses|fechar\\s+parênteses|close\\s+(?:parenthesis|bracket)'];

function spokenPunctuation(text) {
    let out = ` ${text} `;
    for (const [p, r] of BREAKS) out = out.replace(new RegExp(`\\s+(?:${p})(?=\\s|$)`, 'gi'), r);
    for (const [p, r] of SPOKEN) out = out.replace(new RegExp(`\\s+${p}(?=\\s|$)`, 'gi'), r);
    out = out.replace(new RegExp(`\\s+(?:${OPEN[0]})(?=\\s|$)\\s*`, 'gi'), ' (').replace(new RegExp(`\\s*(?:${CLOSE[0]})(?=\\s|$)`, 'gi'), ')');

    return out.trim();
}

/** Spaces: none before , . ; : ? ! … ) and none after (; one space after punctuation that is followed by a word. */
function tidy(text) {
    return text
        .replace(/[ \t]+/g, ' ')
        .replace(/ +([,.;:?!…)])/g, '$1')
        .replace(/\( +/g, '(')
        .replace(/([,;:?!…)])(?=[^\s\d,.;:?!…)\n])/g, '$1 ')
        .replace(/([.])(?=[A-Za-zÀ-ÿ])/g, '$1 ') // "fim.Depois" -> "fim. Depois", but not "1.5" or "DEV-12.json"
        .replace(/ *\n */g, '\n')
        .trim();
}

function capitalise(text, atStart) {
    let out = text.replace(/([.?!…]\s+|\n)(\p{Ll})/gu, (m, sep, c) => sep + c.toUpperCase());
    if (atStart) out = out.replace(/^(\p{Ll})/u, (c) => c.toUpperCase());

    return out;
}

/**
 * @param {string} raw what the recognizer returned for the whole dictation
 * @param {{keys?: string[], names?: string[], atStart?: boolean}} [opts] locale: 'pt' or 'en' (the Portuguese corrections only apply to 'pt'); keys: project keys of the user (to recognise issue ids);
 *        names: people of the app (capitals, and a first name alone when it is unique);
 *        atStart: the dictation begins a sentence (empty box, or after . ? ! or a line break) so its first letter is capitalised
 */
export function polishDictation(raw, opts = {}) {
    const text = String(raw ?? '').trim();
    if (!text) return '';

    return capitalise(tidy(fixSpokenKeys(spokenPunctuation(fixVocabulary(text, opts.names ?? [], opts.locale ?? 'pt')), opts.keys ?? [])), opts.atStart ?? true);
}

/**
 * Words of the app the recognizer tends to get wrong because they are English words said in a Portuguese sentence (or names of
 * this company's people). Each rule turns what the recognizer wrote into what was meant.
 */
const MISHEARD = [
    // "issues" is heard as "inches", "ichus", "ishus"…
    [/\b(?:inches|ichus|ishus|ishuz|íssues|issus|ixus|íxus|isshus|ichuz|ichues|ishues)\b/giu, 'issues'],
    [/\b(?:ichu|ishu|íssue|ixu|íxu|ichiu)\b/giu, 'issue'],
    [/\ba\s+signad([oa]s?)\b/giu, 'assignad$1'],
    [/\b(?:assigni|assinee|assigne)\b/giu, 'assignee'],
    [/\b(?:iu|you|yu|i\s*u)\s*-?\s*tra[ck]k?\b/giu, 'YouTrack'],
    [/\b(?:esprint|sprinte)\b/giu, 'sprint'],
    [/\b(?:bak\s*log|béquelogue|bequelog|béquilogue)\b/giu, 'backlog'],
    [/\blogue?\s*in\b/giu, 'login'],
    [/\b(?:dêploi|deploi|diploi|diploy)\b/giu, 'deploy'],
];

const LETTER = '\\p{L}\\p{N}';

/**
 * Fixes words the recognizer gets wrong, and gives the names of the people of the app their capitals ("paulo alves" -> "Paulo Alves";
 * a first name alone too, when only one person has it).
 */
export function fixVocabulary(text, names = [], locale = 'pt') {
    let out = text;
    // these are the mistakes of a Portuguese recognizer with English words; an English one does not make them
    if (locale === 'pt') for (const [re, to] of MISHEARD) out = out.replace(re, to);

    const full = [...new Set(names.map((n) => String(n).trim()).filter((n) => n.length >= 5))];
    const firstNames = new Map();
    for (const n of full) {
        const first = n.split(/\s+/)[0];
        if (first.length >= 4) firstNames.set(first.toLowerCase(), firstNames.has(first.toLowerCase()) ? null : first); // null: two people share it
    }
    for (const n of full.sort((a, b) => b.length - a.length)) {
        out = out.replace(new RegExp(`(?<![${LETTER}])${escRe(n)}(?![${LETTER}])`, 'giu'), n);
    }
    for (const [lower, canonical] of firstNames) {
        if (canonical) out = out.replace(new RegExp(`(?<![${LETTER}])${escRe(lower)}(?![${LETTER}])`, 'giu'), canonical);
    }

    return out;
}

/** The words that matter to this app, lowercase: used to prefer, among the recognizer's alternatives, the one that knows them. */
export function vocabularyWords({ keys = [], names = [], extra = [] } = {}) {
    const words = new Set(['issue', 'issues', 'youtrack', 'sprint', 'backlog', 'login', 'assignee', 'assignados', 'assignado', ...extra.map((w) => String(w).toLowerCase())]);
    for (const k of keys) words.add(String(k).toLowerCase());
    for (const n of names) for (const w of String(n).toLowerCase().split(/\s+/)) if (w.length >= 3) words.add(w);

    return words;
}

/**
 * A recognizer result carries several alternatives (best guess first). Take the first one unless a later one contains clearly more
 * of the app's own words (the recognizer's favourite for "issues" is often "inches"): then the alternative that knows them wins.
 *
 * @param {ArrayLike<{transcript: string, confidence?: number}>} result
 * @param {Set<string>} vocabulary from vocabularyWords()
 */
export function pickAlternative(result, vocabulary) {
    const hits = (text) => String(text).toLowerCase().split(/[^\p{L}\p{N}-]+/u).filter((w) => vocabulary.has(w)).length;
    let best = result[0];
    let bestHits = hits(best?.transcript);
    for (let i = 1; i < result.length; i++) {
        const h = hits(result[i].transcript);
        if (h > bestHits) {
            best = result[i];
            bestHits = h;
        }
    }

    return best?.transcript ?? '';
}

/** Does `text` end where a new sentence starts (so the next dictated word gets a capital)? */
export const endsSentence = (text) => text.trim() === '' || /[.?!…]\s*$/.test(text) || /\n\s*$/.test(text);
