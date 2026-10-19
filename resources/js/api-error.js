export class ApiError extends Error {
    constructor(status, data) {
        super(data?.message ?? `HTTP ${status}`);
        this.status = status;
        this.data = data ?? {};
    }
}

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
