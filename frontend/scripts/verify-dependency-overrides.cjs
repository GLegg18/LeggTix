const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { createRequire } = require('node:module');

async function main() {
  // Resolve through Nitro's actual parents so nested installs exercise the scoped overrides.
  const nitroRequire = createRequire(require.resolve('nitropack/package.json'));
  const nftEntry = nitroRequire.resolve('@vercel/nft');
  const nftRequire = createRequire(nftEntry);
  const archiverRequire = createRequire(nitroRequire.resolve('archiver'));
  const readdirEntry = archiverRequire.resolve('readdir-glob');
  const readdirRequire = createRequire(readdirEntry);
  const globVersion = nftRequire('glob/package.json').version;
  const minimatchVersion = readdirRequire('minimatch/package.json').version;
  assert.equal(globVersion, '14.0.4');
  assert.equal(minimatchVersion, '11.0.5');
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'leggtix-dependency-probe-'));
  try {
    for (const [file, content] of Object.entries({
      'package.json': '{"name":"leggtix-dependency-probe","type":"commonjs"}',
      'server.cjs': "const fs=require('node:fs'); const dep=require('./dep.cjs'); module.exports=[dep, fs.readdirSync(__dirname+'/assets')];",
      'dep.cjs': 'module.exports = 42;',
      'assets/data.txt': 'included asset', 'assets/other.txt': 'additional globbed asset',
      '.hidden.txt': 'hidden fixture', 'ignored/omit.txt': 'ignored fixture',
      'node_modules/skip.txt': 'skipped fixture',
    })) {
      const target = path.join(root, file);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.writeFileSync(target, content);
    }
    const { nodeFileTrace } = require(nftEntry);
    const trace = await nodeFileTrace([path.join(root, 'server.cjs')], { base: root, processCwd: root });
    const traced = [...trace.fileList].map(file => file.split(path.sep).join('/')).sort();
    for (const file of ['server.cjs', 'dep.cjs', 'assets/data.txt', 'assets/other.txt']) {
      assert(traced.includes(file), `NFT must trace its server dependency and globbed assets: ${file}`);
    }
    const readdirGlob = require(readdirEntry);
    const collect = options => new Promise((resolve, reject) => {
      const matches = [];
      readdirGlob(root, options).on('match', match => matches.push(match.relative))
        .on('error', reject).on('end', () => resolve(matches.sort()));
    });
    const options = { pattern: ['**/*.txt', '**/*.cjs'], ignore: 'ignored/**', skip: 'node_modules', nodir: true };
    const matches = await collect({ ...options, dot: true });
    assert(matches.includes('.hidden.txt'));
    assert(matches.includes('assets/data.txt'));
    assert(matches.includes('dep.cjs'));
    assert(!matches.includes('ignored/omit.txt'));
    assert(!matches.includes('node_modules/skip.txt'));
    const withoutHidden = await collect({ ...options, dot: false });
    assert(!withoutHidden.includes('.hidden.txt'));
    const result = { node: process.version, globVersion, minimatchVersion, traced, readdirMatches: matches, readdirWithoutDot: withoutHidden };
    console.log(JSON.stringify(result, null, 2));
  } finally {
    assert(root.startsWith(os.tmpdir() + path.sep));
    fs.rmSync(root, { recursive: true, force: true });
  }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
