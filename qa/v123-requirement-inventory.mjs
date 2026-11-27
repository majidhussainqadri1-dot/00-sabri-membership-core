/**
 * File00 V12.3 G01: immutable 123-ID evidence inventory gate.
 *
 * This does NOT certify any requirement as implemented or accepted.
 * It protects exact plan IDs and prevents historical code_status from
 * being silently treated as a current positive/negative acceptance.
 */
import fs from 'node:fs';
import assert from 'node:assert/strict';
import path from 'node:path';
const root = path.resolve(import.meta.dirname, '..');
const read = (p) => JSON.parse(fs.readFileSync(path.join(root, p), 'utf8'));
const inventory = read('docs/FILE00-V123-123-TRACEABILITY-BASELINE.json');
const base = read('qa/requirements-traceability.json');
const advanced = read('qa/advanced-trust-traceability.json');
const pad = (n, digits=3) => String(n).padStart(digits,'0');
const requiredIds = [
  ...Array.from({length:100},(_,i)=>`F00-R${pad(i+1)}`),
  'F00-CEN-01','F00-CEN-02','F00-CEN-03',
  ...Array.from({length:20},(_,i)=>`F00-EXT-${pad(i+1)}`)
];
assert.equal(inventory.schema,'sabri.file00.v123.traceability-baseline/1.0.0');
assert.equal(inventory.master_plan,'Sabri Website Master Plan V12.3');
assert.equal(inventory.source_main_sha,'1fad051c86d530d558be3eb1890d3c6e67bce6e8');
assert.deepEqual(inventory.category_counts,{R:100,CEN:3,EXT:20,total:123});
assert.equal(inventory.requirements.length,123);
assert.deepEqual(inventory.requirements.map(r=>r.id),requiredIds);
assert.deepEqual(base.requirements.map(r=>r.id),requiredIds.slice(0,100));
assert.deepEqual(advanced.requirements.map(r=>r.id),requiredIds.slice(103));
let pathsChecked=0;
for (const row of inventory.requirements) {
  assert.ok(row.title && row.workstream, `No title/workstream: ${row.id}`);
  assert.ok(['R','CEN','EXT'].includes(row.category), `Bad category: ${row.id}`);
  assert.equal(row.current_positive_negative_runtime_acceptance,'UNVERIFIED');
  assert.equal(row.cross_file_consumer_acceptance,'UNVERIFIED');
  assert.equal(row.staging_acceptance,'UNVERIFIED');
  assert.equal(row.live_acceptance,'UNVERIFIED');
  assert.equal(row.disposition,'REVIEW_REQUIRED');
  assert.equal(row.current_exact_source_symbol,null);
  assert.ok(Array.isArray(row.main_source_pointer_candidates), `Missing references for ${row.id}`);
  for (const file of row.main_source_pointer_candidates) {
    assert.ok(!path.isAbsolute(file) && !file.includes('..'), `Unsafe path in ${row.id}`);
    assert.ok(fs.existsSync(path.join(root,file)), `Historical source reference absent: ${row.id}: ${file}`);
    pathsChecked++;
  }
}
console.log(`V12.3 File00 G01 inventory: 123/123 unique IDs; ${pathsChecked} source-pointer occurrences checked; 0 individual acceptances claimed; PASS`);
