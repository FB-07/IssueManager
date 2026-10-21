import { ApiError } from './api-error';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/**
 * Streams a chat turn from POST /api/chat (Server-Sent Events over fetch).
 * Handlers: onText(delta). Resolves with { patch, created, updated, replies, batch }: the draft tool input, or the create_issue tool input when the draft mode is off.
 */
export async function streamChat({ payload, files = [], signal }, { onText }) {
    const fd = new FormData();
    fd.append('payload', JSON.stringify(payload));
    files.forEach((f) => fd.append('files[]', f));

    const res = await fetch('/api/chat', {
        method: 'POST',
        headers: { Accept: 'text/event-stream', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        credentials: 'same-origin',
        signal: signal ?? AbortSignal.timeout(330000), // a stalled connection must not leave the chat waiting forever
    });

    if (!res.ok) {
        const data = await res.json().catch(() => null);
        throw new ApiError(res.status, { message: data?.message ?? `HTTP ${res.status}` });
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let patch = null;
    let created = null;
    let updated = null;
    let replies = null;
    let batch = null;

    const handle = (raw) => {
        let event = 'message';
        let data = '';
        for (const line of raw.split('\n')) {
            if (line.startsWith('event: ')) event = line.slice(7);
            else if (line.startsWith('data: ')) data += line.slice(6);
        }
        if (!data) return;
        const json = JSON.parse(data);
        if (event === 'text') onText(json.t);
        else if (event === 'draft') patch = json.patch;
        else if (event === 'create') created = json.issue;
        else if (event === 'update') updated = json.issue;
        else if (event === 'batch') batch = json;
        else if (event === 'replies') replies = json.replies;
        else if (event === 'error') throw new ApiError(502, { message: json.message });
    };

    for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });
        let idx;
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
            handle(buffer.slice(0, idx));
            buffer = buffer.slice(idx + 2);
        }
    }

    return { patch, created, updated, replies, batch };
}
