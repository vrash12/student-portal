// Builds the system proposal PDF.
//
//   node docs/proposal/build.mjs
//
// Replaces {{icon-name}} placeholders in proposal.src.html with inline Lucide
// icons (from node_modules, so no internet is needed), writes proposal.html,
// and prints it to Academic-Monitoring-System-Proposal.pdf with headless
// Chrome or Edge. Pass --page=N to also save a PNG preview of page N.
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const iconDir = join(root, 'node_modules/lucide-react/dist/esm/icons');

async function iconSvg(name) {
    const file = join(iconDir, `${name}.mjs`);
    if (!existsSync(file)) {
        throw new Error(`Unknown icon: ${name}`);
    }
    const { __iconData } = await import(pathToFileURL(file).href);
    if (__iconData === undefined) {
        // An alias module re-exports another icon file.
        const target = readFileSync(file, 'utf8').match(/from '\.\/([a-z0-9-]+)\.mjs'/);
        if (target === null) {
            throw new Error(`Cannot read icon: ${name}`);
        }

        return iconSvg(target[1]);
    }
    const children = __iconData.node
        .map(([tag, attributes]) => `<${tag} ${Object.entries(attributes).filter(([key]) => key !== 'key').map(([key, value]) => `${key}="${value}"`).join(' ')}/>`)
        .join('');

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${children}</svg>`;
}

let html = readFileSync(join(here, 'proposal.src.html'), 'utf8');
const names = [...new Set([...html.matchAll(/\{\{([a-z0-9-]+)\}\}/g)].map((match) => match[1]))];
for (const name of names) {
    html = html.replaceAll(`{{${name}}}`, await iconSvg(name));
}
const htmlPath = join(here, 'proposal.html');
writeFileSync(htmlPath, html);

const browser = [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find((candidate) => existsSync(candidate));
if (browser === undefined) {
    throw new Error('Chrome or Edge is needed to print the PDF.');
}

const pdfPath = join(here, 'Academic-Monitoring-System-Proposal.pdf');
const common = ['--headless=new', '--disable-gpu', '--hide-scrollbars', '--virtual-time-budget=5000'];
execFileSync(browser, [...common, '--no-pdf-header-footer', `--print-to-pdf=${pdfPath}`, pathToFileURL(htmlPath).href], { stdio: 'inherit' });
console.log(`Wrote ${pdfPath}`);

const pageArgument = process.argv.find((argument) => argument.startsWith('--page='));
if (pageArgument !== undefined) {
    const page = pageArgument.slice('--page='.length);
    const pngPath = join(here, `preview-p${page}.png`);
    execFileSync(browser, [...common, '--window-size=816,1056', `--screenshot=${pngPath}`, `${pathToFileURL(htmlPath).href}#p${page}`], { stdio: 'inherit' });
    console.log(`Wrote ${pngPath}`);
}
