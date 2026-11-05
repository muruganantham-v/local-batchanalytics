 /**
 * Build script to compile and minify AMD JavaScript modules with source maps.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const { execSync } = require('child_process');
const fs = require('fs');

const modules = ['batch', 'dashboard', 'module', 'cliq_templates'];

console.log('Compiling AMD modules with Terser...\n');

modules.forEach(mod => {
    const srcFile = `amd/src/${mod}.js`;
    const destFile = `amd/build/${mod}.min.js`;
    const mapFile = `amd/build/${mod}.min.js.map`;

    process.stdout.write(`Compiling ${mod}... `);
    const cmd = `npx terser "${srcFile}" -o "${destFile}" --source-map "url='${mod}.min.js.map',includeSources" --comments "/^!|@license|@copyright/i"`;
    execSync(cmd, { stdio: ['ignore', 'ignore', 'inherit'] });

    const srcStat = fs.statSync(srcFile);
    const destStat = fs.statSync(destFile);
    const reduction = (((srcStat.size - destStat.size) / srcStat.size) * 100).toFixed(1);

    console.log(`done! (${(srcStat.size / 1024).toFixed(1)} KB -> ${(destStat.size / 1024).toFixed(1)} KB, -${reduction}%)`);
});

console.log('\nAll AMD modules compiled successfully with source maps.\n');

