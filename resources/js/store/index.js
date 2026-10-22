import { coreSlice } from './core';
import { trackerSlice } from './tracker';
import { conversationsSlice } from './conversations';
import { chatSlice } from './chat';
import { batchSlice } from './batch';
import { voiceSlice } from './voice';
import { draftSlice } from './draft';
import { contextSlice } from './context';
import { issuesSlice } from './issues';
import { mineSlice } from './mine';
import { analyticsSlice } from './analytics';

// The store is made of one part per area of the app; they all end up in one object, so a method of one part can call a
// method of another through `this`. Getters stay getters.
function merge(...parts) {
    const out = {};
    for (const part of parts) Object.defineProperties(out, Object.getOwnPropertyDescriptors(part));

    return out;
}

export function createStore(boot) {
    return merge(coreSlice(boot), trackerSlice(boot), conversationsSlice(boot), chatSlice(boot), batchSlice(boot), voiceSlice(boot), draftSlice(boot), contextSlice(boot), issuesSlice(boot), mineSlice(boot), analyticsSlice(boot));
}
