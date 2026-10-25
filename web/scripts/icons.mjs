// Renders the app icons from resources/icons/icon.svg (run: npm run icons).
//   public/icons/icon-192.png, icon-512.png   Android / the web manifest ("any": the rounded square as drawn)
//   public/icons/maskable-512.png             Android adaptive icon: the mark on a full lime square (the launcher crops
//                                             its own shape, so the drawing stays inside the inner 80 %)
//   public/icons/apple-touch-icon.png         iOS home screen (180, square: iOS rounds the corners itself)
//   public/favicon.ico (32), public/favicon.svg
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const svg = await readFile(new URL('../resources/icons/icon.svg', import.meta.url), 'utf8');
// a square variant: the same mark, no rounded corners, drawing scaled into the safe zone
const square = (scale) =>
    svg.replace(/rx="116"/, 'rx="0"').replace('<g fill', `<g transform="translate(256 256) scale(${scale}) translate(-256 -256)" fill`);

await mkdir(new URL('../public/icons', import.meta.url), { recursive: true });
const out = (name) => fileURLToPath(new URL(`../public/${name}`, import.meta.url));
const png = (src, size, name) => sharp(Buffer.from(src), { density: 384 }).resize(size, size).png().toFile(out(name));

await png(svg, 192, 'icons/icon-192.png');
await png(svg, 512, 'icons/icon-512.png');
await png(square(0.8), 512, 'icons/maskable-512.png');
await png(square(1), 180, 'icons/apple-touch-icon.png');
await writeFile(out('favicon.svg'), svg.replace(/<!--[\s\S]*?-->\s*/, ''));
// .ico: a 32px PNG inside the ICO container (every current browser reads PNG-in-ICO)
const p32 = await sharp(Buffer.from(svg), { density: 384 }).resize(32, 32).png().toBuffer();
const header = Buffer.alloc(6 + 16);
header.writeUInt16LE(0, 0);
header.writeUInt16LE(1, 2);
header.writeUInt16LE(1, 4);
header.writeUInt8(32, 6);
header.writeUInt8(32, 7);
header.writeUInt8(0, 8);
header.writeUInt8(0, 9);
header.writeUInt16LE(1, 10);
header.writeUInt16LE(32, 12);
header.writeUInt32LE(p32.length, 14);
header.writeUInt32LE(22, 18);
await writeFile(out('favicon.ico'), Buffer.concat([header, p32]));
console.log('icons written to public/icons, public/favicon.*');
