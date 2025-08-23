// Node 18+. Run with: node examples/node-fetch-basic.mjs
const baseUrl = (process.env.DRUPAL_BASE_URL || 'https://example.com').replace(/\/$/, '');
const user = process.env.PCC_USER || 'pcc_bot';
const pass = process.env.PCC_PASS || 'change-me';
const token = Buffer.from(`${user}:${pass}`).toString('base64');

const res = await fetch(`${baseUrl}/project-context-connector/snapshot`, {
    headers: {
        'Accept': 'application/json',
        'Authorization': `Basic ${token}`,
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
