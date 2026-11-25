 /**
 * Build script to compile and minify AMD JavaScript modules with source maps.
 *
 * @package    local_batchanalytics
 * @copyright  2026 Emertxe Information Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const fs = require('fs');
const path = require('path');
const terser = require('terser');

const modules = ['batch', 'dashboard', 'module', 'cliq_templates', 'student'];

console.log('Compiling AMD modules with Terser...\n');

async function build() {
    for (const mod of modules) {
        const srcFile = path.join(__dirname, `amd/src/${mod}.js`);
        const destFile = path.join(__dirname, `amd/build/${mod}.min.js`);
        const mapFile = path.join(__dirname, `amd/build/${mod}.min.js.map`);

        if (!fs.existsSync(srcFile)) continue;

        process.stdout.write(`Compiling ${mod}... `);
        const code = fs.readFileSync(srcFile, 'utf8');
        const result = await terser.minify(code, {
            sourceMap: {
                filename: `${mod}.min.js`,
                url: `${mod}.min.js.map`,
                includeSources: true
            },
            format: {
                comments: /^!|@license|@copyright/i
            }
        });

        fs.writeFileSync(destFile, result.code, 'utf8');
        if (result.map) {
            fs.writeFileSync(mapFile, result.map, 'utf8');
        }

        const srcStat = fs.statSync(srcFile);
        const destStat = fs.statSync(destFile);
        const reduction = (((srcStat.size - destStat.size) / srcStat.size) * 100).toFixed(1);

        console.log(`done! (${(srcStat.size / 1024).toFixed(1)} KB -> ${(destStat.size / 1024).toFixed(1)} KB, -${reduction}%)`);
    }
    console.log('\nAll AMD modules compiled successfully with source maps.\n');
}

build().catch(err => {
    console.error(err);
    process.exit(1);
});

