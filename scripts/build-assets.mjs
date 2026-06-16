#!/usr/bin/env node
/**
 * Copies vendored Cap distribution files into assets/ so the plugin can ship
 * a fully self-hosted copy — no jsdelivr requests at runtime.
 *
 * Run via `bun run build` (or `npm run build`).
 */

import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const projectRoot = resolve(__dirname, '..');

const widgetPkg = resolve(projectRoot, 'node_modules/cap-widget');
const wasmPkg = resolve(projectRoot, 'node_modules/@cap.js/wasm');
const pakoPkg = resolve(projectRoot, 'node_modules/pako');

const jsDestDir = resolve(projectRoot, 'assets/js/vendor');
const wasmDestDir = resolve(projectRoot, 'assets/wasm');

for (const [name, dir] of [['cap-widget', widgetPkg], ['@cap.js/wasm', wasmPkg], ['pako', pakoPkg]]) {
    if (!existsSync(dir)) {
        console.error(`${name} is not installed. Run \`bun install\` before building assets.`);
        process.exit(1);
    }
}

const widgetVersion = JSON.parse(
    readFileSync(resolve(widgetPkg, 'package.json'), 'utf8'),
).version;
const wasmVersion = JSON.parse(
    readFileSync(resolve(wasmPkg, 'package.json'), 'utf8'),
).version;
const pakoVersion = JSON.parse(
    readFileSync(resolve(pakoPkg, 'package.json'), 'utf8'),
).version;

/**
 * Neutralises the upstream jsdelivr fallback URLs baked into the minified
 * widget bundle. The plugin always sets `window.CAP_PAKO_URL` and
 * `window.CAP_CUSTOM_WASM_URL` (see Asset\Enqueuer), so these `X || "<url>"`
 * fallbacks are never reached at runtime — but stripping the literals keeps
 * the shipped file free of any third-party CDN reference, as WordPress.org
 * requires (Guideline 8). Each URL literal becomes an empty string.
 *
 * @param {Buffer} buffer
 * @returns {Buffer}
 */
function stripCdnFallbacks(buffer) {
    const stripped = buffer
        .toString('utf8')
        .replace(/https:\/\/cdn\.jsdelivr\.net\/[^"'`]*/g, '');

    return Buffer.from(stripped, 'utf8');
}

/** @type {{ srcDir: string, src: string, destDir: string, dest: string, transform?: (buffer: Buffer) => Buffer }[]} */
const files = [
    { srcDir: widgetPkg, src: 'cap.min.js', destDir: jsDestDir, dest: 'cap-widget.js', transform: stripCdnFallbacks },
    { srcDir: widgetPkg, src: 'cap-floating.min.js', destDir: jsDestDir, dest: 'cap-widget.floating.js' },
    { srcDir: wasmPkg, src: 'browser/cap_wasm_bg.wasm', destDir: wasmDestDir, dest: 'cap_wasm_bg.wasm' },
    { srcDir: pakoPkg, src: 'dist/pako_inflate.min.js', destDir: jsDestDir, dest: 'pako_inflate.min.js' },
];

mkdirSync(jsDestDir, { recursive: true });
mkdirSync(wasmDestDir, { recursive: true });

const checkOnly = process.argv.includes('--check');
let mismatched = false;

for (const { srcDir, src, destDir, dest, transform } of files) {
    const srcPath = resolve(srcDir, src);
    const destPath = resolve(destDir, dest);
    const relDest = destPath.slice(projectRoot.length + 1);

    if (!existsSync(srcPath)) {
        console.warn(`skip: ${src} not present in ${srcDir}`);
        continue;
    }

    const contents = transform ? transform(readFileSync(srcPath)) : readFileSync(srcPath);

    if (checkOnly) {
        if (!existsSync(destPath) || hash(contents) !== hash(readFileSync(destPath))) {
            console.error(`out of date: ${relDest}`);
            mismatched = true;
        }
        continue;
    }

    writeFileSync(destPath, contents);
    console.log(`wrote ${relDest}`);
}

if (checkOnly) {
    if (mismatched) {
        console.error('Run `bun run build` to refresh vendored assets.');
        process.exit(1);
    }
    console.log('Vendored Cap assets are up to date.');
    process.exit(0);
}

console.log(
    `Bundled cap-widget@${widgetVersion} + @cap.js/wasm@${wasmVersion} + pako@${pakoVersion}.`,
);

function hash(buffer) {
    return createHash('sha256').update(buffer).digest('hex');
}
