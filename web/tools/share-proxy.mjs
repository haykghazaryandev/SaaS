// Development only: one local port for sharing the app through a tunnel (ngrok).
// /app/* and /apps/* (Reverb WebSockets) go to Reverb on :8080, everything else to Laravel on :8000.
// Usage: node tools/share-proxy.mjs  (listens on :8090), then: ngrok http 8090
import http from 'node:http';
import net from 'node:net';

const APP = 8000, REVERB = 8080, PORT = 8090;
const target = (url) => (/^\/apps?(\/|$)/.test(url) ? REVERB : APP);

const server = http.createServer((req, res) => {
    const up = http.request({ host: '127.0.0.1', port: target(req.url), path: req.url, method: req.method, headers: req.headers }, (r) => {
        res.writeHead(r.statusCode, r.headers);
        r.pipe(res);
    });
    up.on('error', () => { res.writeHead(502); res.end('upstream down'); });
    req.pipe(up);
});

// WebSocket upgrade: pass the raw connection through
server.on('upgrade', (req, socket, head) => {
    const up = net.connect(target(req.url), '127.0.0.1', () => {
        up.write(`${req.method} ${req.url} HTTP/${req.httpVersion}\r\n` + Object.entries(req.headers).map(([k, v]) => `${k}: ${v}`).join('\r\n') + '\r\n\r\n');
        up.write(head);
        socket.pipe(up).pipe(socket);
    });
    up.on('error', () => socket.destroy());
    socket.on('error', () => up.destroy());
});

server.listen(PORT, () => console.log(`share proxy on :${PORT} → app :${APP}, reverb :${REVERB}`));
