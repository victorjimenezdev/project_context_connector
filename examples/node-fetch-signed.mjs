// Node 18+. Run with: node examples/node-fetch-signed.mjs
import crypto from 'node:crypto';

const baseUrl = (process.env.DRUPAL_BASE_URL || 'https://example.com').replace(/\/$/, '');
const keyId = process.env.PCC_KEY_ID || 'prompt-bot';
const secret = process.env.PCC_SHARED_SECRET || 'change-me';

const method = 'GET';
const path = '/project-context-connector/snapshot/signed';
const ts = Math.floor(Date.now() / 1000).toString();
const base = `${method}\n${path}\n${ts}`;
const sig = crypto.createHmac('sha256', secret).update(base).digest('hex');

const res = await fetch(`${baseUrl}${path}`, {
    method,
    headers: {
        'Accept': 'application/json',
        'X-PCC-Key': keyId,
        'X-PCC-Timestamp': ts,
        'X-PCC-Signature': sig,
        'User-Agent': 'pcc-client/1.0'
    }
});

if (res.status === 429) {
    console.error('Rate limited. Retry-After:', res.headers.get('retry-after'));
    process.exit(2);
}
if (!res.ok) {
    console.error('HTTP error', res.status);
    process.exit(1);
}

const json = await res.json();
console.log(JSON.stringify(json, null, 2));
