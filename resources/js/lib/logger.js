export function logAction(action, status, context = {}) {
    const log = {
        timestamp: new Date().toISOString(),
        action,
        status,
        ...context,
    };

    const prefix = '[TOPUPLOG]';
    if (status === 'fail' || status === 'error') {
        console.error(prefix, JSON.stringify(log, null, 2));
    } else {
        console.log(prefix, JSON.stringify(log, null, 2));
    }
}
