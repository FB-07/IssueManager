// Dictation by voice.
import { vocabularyWords, pickAlternative, polishDictation, endsSentence } from '../voice';

let voiceSession = null;

export function voiceSlice() {
    return {
 // the passage of an answer the user has selected: { text, x, y } (offers "reply")
        // dictation through the browser's speech recognition (Chrome/Edge/Safari); hidden where unsupported
        voice: { supported: !!(window.SpeechRecognition || window.webkitSpeechRecognition), listening: false },
        // The browser's recognizer (Web Speech API) ends a "continuous" session by itself after a pause or a minute, and delivers
        // its results per session. A dictation is therefore a series of sessions that the app chains until the user stops, and the
        // text shown is rebuilt from everything said so far, cleaned up by voice.js (spoken punctuation, capitals, issue ids).
        toggleVoice() {
            return this.voice.listening ? this.stopVoice() : this.startVoice();
        },
        startVoice() {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SR) return this.notify(this.t('errors.voice_unsupported'), 'error');
            if (this.processing || this.voice.listening) return;

            const session = { wanted: true, fatal: false, rec: null, base: this.composer.text.trim(), committed: '', final: '', interim: '', starts: [] };
            voiceSession = session;
            this.voice.listening = true;
            this.focusComposer();
            this.launchVoice(session);
        },
        launchVoice(session) {
            const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            const rec = new SR();
            rec.lang = this.locale === 'pt' ? 'pt-PT' : 'en-US';
            rec.continuous = true;
            rec.interimResults = true;
            rec.maxAlternatives = 5; // several guesses per phrase: the one that knows the app's own words wins (see pickAlternative)
            const vocabulary = vocabularyWords({ keys: this.voiceKeys(), names: this.voiceNames() });

            rec.onresult = (e) => {
                // late results of a dictation that was cancelled (the message was sent and the box emptied) are ignored
                if (voiceSession !== session) return;
                let final = '';
                let interim = '';
                for (let i = 0; i < e.results.length; i++) {
                    const r = e.results[i];
                    const said = pickAlternative(r, vocabulary);
                    if (r.isFinal) final += said;
                    else interim += said;
                }
                session.final = final;
                session.interim = interim;
                this.renderVoice(session);
            };
            rec.onerror = (e) => {
                if (e.error === 'no-speech' || e.error === 'aborted') return; // silence: the next session simply starts again
                session.fatal = true;
                const key = { 'not-allowed': 'voice_denied', 'service-not-allowed': 'voice_denied', 'audio-capture': 'voice_no_mic', network: 'voice_network' }[e.error] ?? 'voice_failed';
                this.notify(this.t(`errors.${key}`), 'error');
            };
            rec.onend = () => {
                if (voiceSession !== session) return;
                // what this session heard (even a last provisional phrase) is kept for the text of the whole dictation
                session.committed = [session.committed, `${session.final}${session.interim}`.trim()].filter(Boolean).join(' ');
                session.final = session.interim = '';

                if (session.wanted && !session.fatal) {
                    const now = Date.now();
                    session.starts = [...session.starts.filter((t) => now - t < 5000), now];
                    if (session.starts.length > 6) {
                        // restarting over and over without anything being heard: give up instead of looping
                        this.notify(this.t('errors.voice_failed'), 'error');
                    } else {
                        setTimeout(() => voiceSession === session && session.wanted && this.launchVoice(session), 120);

                        return;
                    }
                }
                this.voice.listening = false;
                voiceSession = null;
                this.renderVoice(session);
            };

            try {
                rec.start();
                session.rec = rec;
            } catch (e) {
                session.fatal = true;
                this.voice.listening = false;
                voiceSession = null;
                this.notify(this.t('errors.voice_failed'), 'error');
            }
        },
        /** Project keys and people of the user: what the dictation should recognise (issue ids, names with capitals). */
        voiceKeys() {
            return (this.projects ?? this.catalog?.projects ?? []).map((p) => p.key);
        },
        voiceNames() {
            const metas = Object.values(this.catalog?.byProject ?? this.metaByProject ?? {});

            return [...new Set(metas.flatMap((m) => (m.assignees ?? []).map((a) => a.label)).filter(Boolean))];
        },
        /** Box = what was typed before + everything dictated so far, cleaned up. */
        renderVoice(session) {
            const raw = [session.committed, session.final, session.interim].map((x) => x.trim()).filter(Boolean).join(' ');
            const said = polishDictation(raw, { locale: this.locale, keys: this.voiceKeys(), names: this.voiceNames(), atStart: endsSentence(session.base) });
            this.composer.text = said ? `${session.base}${session.base === '' || session.base.endsWith('\n') ? '' : ' '}${said}` : session.base;
            this.followDictation();
        },
        /**
         * The box grows with the text up to its maximum height and then scrolls. Typing keeps the caret in view, but text put
         * there by the dictation does not, so the newest words would stay out of sight below the edge: show the end of the text.
         */
        followDictation() {
            setTimeout(() => {
                // after the text has been written into the box (x-model) and it has been resized
                const el = document.getElementById('composer-input');
                if (!el) return;
                this.autosize(el);
                el.scrollTop = el.scrollHeight;
                el.setSelectionRange?.(el.value.length, el.value.length);
            }, 0);
        },
        /**
         * Stops dictating. Normally (the microphone button) the last words are still delivered; with `discard` (the message is
         * being sent, or the box is being emptied) the recognition is cut off and nothing it says afterwards may come back.
         */
        stopVoice(discard = false) {
            const session = voiceSession;
            if (session) {
                session.wanted = false;
                if (discard) voiceSession = null; // from now on its late results are ignored (see onresult)
                try {
                    discard ? session.rec?.abort() : session.rec?.stop();
                } catch (e) {}
            }
            this.voice.listening = false;
        },    };
}
