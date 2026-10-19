import { ApiError, sleep } from './api-error';
import { http } from './http';

export { ApiError, sleep };

/** All data goes through the Laravel JSON API (conversations, drafts and tracker are persisted/served server side). */
export const api = http;
