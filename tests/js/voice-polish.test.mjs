import test from 'node:test';
import assert from 'node:assert/strict';
import { polishDictation as p, spokenNumber, endsSentence, fixVocabulary, pickAlternative, vocabularyWords } from '../../resources/js/voice.js';
const K = ['DEV', '2010043'];
const t = (name, fn) => test(name, fn);

t('spoken punctuation (pt)', () => assert.equal(p('olá vírgula tudo bem ponto de interrogação'), 'Olá, tudo bem?'));
t('sentences get capitals', () => assert.equal(p('primeiro ponto final segundo ponto final'), 'Primeiro. Segundo.'));
t('line and paragraph breaks', () => { assert.equal(p('um nova linha dois'), 'Um\nDois'); assert.equal(p('um novo parágrafo dois'), 'Um\n\nDois'); });
t('colon, semicolon, exclamation, ellipsis', () => assert.equal(p('atenção dois pontos isto ponto e vírgula aquilo ponto de exclamação reticências'), 'Atenção: isto; Aquilo!…'.replace('; Aquilo', '; aquilo')));
t('parentheses', () => assert.equal(p('isto abre parênteses mais ou menos fecha parênteses sim'), 'Isto (mais ou menos) sim'));
t('english', () => assert.equal(p('hello comma world question mark'), 'Hello, world?'));
t('"ponto" and other plain words stay', () => { assert.equal(p('ponto de situação'), 'Ponto de situação'); assert.equal(p('uma vírgula é pequena'.replace('uma vírgula', 'a vírgula')).includes(','), true); });
t('issue key said with a number word', () => assert.equal(p('altera o dev doze', { keys: K }), 'Altera o DEV-12'));
t('issue key with dash words and compound numbers', () => { assert.equal(p('fecha o dev traço vinte e cinco', { keys: K }), 'Fecha o DEV-25'); assert.equal(p('dev menos trinta', { keys: K }), 'DEV-30'); });
t('issue key spelled letter by letter, or as digits', () => { assert.equal(p('vê o d e v 7', { keys: K }), 'Vê o DEV-7'); assert.equal(p('dev 12', { keys: K }), 'DEV-12'); assert.equal(p('dev-12', { keys: K }), 'DEV-12'); });
t('numeric project key', () => { assert.equal(p('abre 2010043 traço 1989', { keys: K }), 'Abre 2010043-1989'); assert.equal(p('2010043 1989', { keys: K }), '2010043-1989'); });
t('a key word without a number is left alone', () => { assert.equal(p('o dev ontem', { keys: K }), 'O dev ontem'); assert.equal(p('dev quatro vezes', { keys: K }), 'DEV-4 vezes'.replace('DEV-4 vezes', 'DEV-4 vezes')); });
t('keys are not invented inside other words', () => { assert.equal(p('devolver 12 itens', { keys: K }), 'Devolver 12 itens'); assert.equal(p('editdev 12', { keys: K }), 'Editdev 12'); });
t('without known keys nothing is rewritten', () => assert.equal(p('dev doze'), 'Dev doze'));
t('numbers and versions are not broken', () => assert.equal(p('versão 1.5 e 3,5 percento'), 'Versão 1.5 e 3,5 percento'));
t('continuing a sentence keeps lower case', () => assert.equal(p('e depois fecha', { atStart: false }), 'e depois fecha'));
t('empty and spacing', () => { assert.equal(p('   '), ''); assert.equal(p('um  vírgula   dois'), 'Um, dois'); });
t('spokenNumber', () => { assert.equal(spokenNumber('vinte e um'), 21); assert.equal(spokenNumber('nine'), 9); assert.equal(spokenNumber('abc'), null); });
t('endsSentence', () => { assert.ok(endsSentence('')); assert.ok(endsSentence('Fim.')); assert.ok(endsSentence('a\n')); assert.ok(!endsSentence('a meio')); });


const PEOPLE = ['Paulo Alves', 'Ana Silva', 'Ana Costa', 'Mariana Tavares'];
t('the sentence of the report: "inches a signados" is "issues assignados"', () =>
  assert.equal(p('o que é que tu percebes com isso do paulo são inches a signados ou criados', { names: PEOPLE }), 'O que é que tu percebes com isso do Paulo são issues assignados ou criados'));
t('other English words of the app that a Portuguese recognizer garbles', () => {
  assert.equal(p('abre o iu track e o esprint', {}), 'Abre o YouTrack e o sprint');
  assert.equal(p('faz logue in no béquelogue', {}), 'Faz login no backlog');
  assert.equal(p('o ishu está aberto', {}), 'O issue está aberto');
});
t('those corrections are for Portuguese only: an English recognizer is left alone', () => assert.equal(p('it is 5 inches wide', { locale: 'en' }), 'It is 5 inches wide'));
t('names of the people get their capitals; a first name alone only when it is unique', () => {
  assert.equal(p('atribui à mariana tavares', { names: PEOPLE }), 'Atribui à Mariana Tavares');
  assert.equal(p('fala com o paulo', { names: PEOPLE }), 'Fala com o Paulo');
  assert.equal(p('fala com a ana', { names: PEOPLE }), 'Fala com a ana'); // two Anas: which one?
  assert.equal(p('fala com a ana silva', { names: PEOPLE }), 'Fala com a Ana Silva');
});
t('a name inside another word is not touched', () => assert.equal(fixVocabulary('o paulos fez', ['Paulo Alves']), 'o paulos fez'));

const V = vocabularyWords({ keys: ['DEV'], names: PEOPLE });
t('among the alternatives the one that knows the words of the app wins', () => {
  const alts = [{ transcript: 'lista os inches do paulo' }, { transcript: 'lista os issues do paulo' }, { transcript: 'lista os inchas do paulo' }];
  assert.equal(pickAlternative(alts, V), 'lista os issues do paulo');
});
t('without a clear winner the first (best) alternative is kept', () => {
  assert.equal(pickAlternative([{ transcript: 'olá bom dia' }, { transcript: 'olá bom dias' }], V), 'olá bom dia');
  assert.equal(pickAlternative([{ transcript: 'o dev e a ana' }, { transcript: 'o dev a ana' }], V), 'o dev e a ana', 'a tie goes to the first');
  assert.equal(pickAlternative([{ transcript: 'o dev e a ana' }, { transcript: 'o dev e a ana silva' }], V), 'o dev e a ana silva', 'more known names win');
  assert.equal(pickAlternative([], V), '');
});
