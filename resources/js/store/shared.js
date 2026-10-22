// Constants and helpers used by more than one part of the store.

export const MAX_FILES = 10;
export const MAX_BYTES = 25 * 1024 * 1024;
export const REQUIRED = ['summary', 'description', 'project', 'type', 'priority'];
export const MAX_ISSUES = 5; // issues that can go with one message (as the server allows)

let uid = 0;
export const nextUid = () => ++uid; // ids of the messages and files that only exist in the browser (so far)
