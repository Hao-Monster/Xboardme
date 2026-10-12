// Linux-only process integration with a fixed local gh replacement. No network.
import test from 'node:test';
import assert from 'node:assert/strict';
import {chmodSync,copyFileSync,existsSync,linkSync,mkdirSync,mkdtempSync,readFileSync,rmSync,symlinkSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname,join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {fixture} from './fixtures/sampled-recovery/fixture.mjs';
import {canonical,sha256} from './verify-sampled-recovery-proof.mjs';
assert.equal(process.platform,'linux','This integration requires a real Linux Node runtime');
const source=dirname(fileURLToPath(import.meta.url));
function setup(t){
  const root=mkdtempSync(join(tmpdir(),'xboard-proof-runner-'));
  t.after(()=>{assert.ok(root.startsWith(join(tmpdir(),'xboard-proof-runner-')));rmSync(root,{recursive:true});});
  const scripts=join(root,'scripts'),release=join(root,'release'),temp=join(root,'runner'),bin=join(root,'bin');
  for(const path of [scripts,temp,bin,join(release,'image-cleanup-recovery-proofs'),join(release,'image-cleanup-recovery-evidence')])mkdirSync(path,{recursive:true});
  for(const name of ['verify-sampled-recovery-runner.mjs','verify-sampled-recovery-proof.mjs','sampled-recovery-trace.mjs'])copyFileSync(join(source,name),join(scripts,name));
  const f=fixture(),proofPath=join(release,'image-cleanup-recovery-proofs',f.proofHash+'.json');
  writeFileSync(proofPath,f.proofRaw);
  writeFileSync(join(release,'image-cleanup-recovery-evidence',f.proof.source.timeline_sha256+'.json'),f.timelineRaw);
  writeFileSync(join(release,'image-cleanup-recovery-evidence',f.proof.source.alignment_report_sha256+'.json'),f.alignmentRaw);
  const op=join(root,'operation.txt');writeFileSync(op,f.operationRaw);
  const review={schema_version:3,active_release_id:f.proof.gate.active_release_id,active_release_sha:f.proof.gate.active_release_sha,
    protection_fingerprint:f.proof.audit.protection_fingerprint,resource_fingerprint:f.proof.audit.resource_fingerprint,
    images:[{id:f.proof.gate.image_id}],recovery:{schema_version:2,mode:'predelete_sampled_hold_v2',proof_sha256:f.proofHash,
      gate_sha256:f.proof.gate_sha256,plan_sha256:f.proof.plan_sha256,review_sha256:f.proof.gate.review_sha256,
      workflow_sha:f.proof.gate.workflow_sha,session_id:f.proof.gate.session_id,image_id:f.proof.gate.image_id}};
  const reviewPath=join(release,'review.json');writeFileSync(reviewPath,canonical(review)+'\n');
  const meta={databaseId:123456,attempt:1,headSha:f.proof.source.workflow_sha,headBranch:'main',event:'workflow_dispatch',status:'completed',
    conclusion:'failure',workflowName:'Production Image-Only Cleanup'};
  const gh=join(bin,'gh');
  writeFileSync(gh,`#!/usr/bin/env node
const fs=require('node:fs'),path=require('node:path');
const a=process.argv.slice(2);fs.appendFileSync(process.env.TEST_GH_LOG,JSON.stringify(a)+'\\n');
if(a.at(-2)!=='-R'||a.at(-1)!=='Hao-Monster/Xboardme'||a[0]!=='run'||a[2]!=='123456')process.exit(97);
if(a[1]==='view'){process.stdout.write(process.env.TEST_RUN_META);}
else if(a[1]==='download'){
 if(a[4]!=='production-image-cleanup-${f.proof.source.workflow_sha}-123456-1'||a[3]!=='--name'||a[5]!=='--dir')process.exit(97);
 fs.copyFileSync(process.env.TEST_OPERATION,path.join(a[6],'images-only-operation.txt'));
}else process.exit(97);
`);chmodSync(gh,0o700);
  const env={...process.env,PATH:bin+':'+dirname(process.execPath)+':'+process.env.PATH,
    GITHUB_REPOSITORY:'Hao-Monster/Xboardme',GITHUB_REF:'refs/heads/main',GITHUB_EVENT_NAME:'workflow_dispatch',GITHUB_RUN_ATTEMPT:'1',GITHUB_RUN_ID:'789',
    EXPECTED_SHA:'a'.repeat(40),GITHUB_SHA:'a'.repeat(40),GITHUB_WORKFLOW_SHA:'a'.repeat(40),
    GITHUB_WORKFLOW_REF:'Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main',RUNNER_TEMP:temp,
    GH_TOKEN:'fixture-token-must-not-emit',TEST_GH_LOG:join(root,'gh.log'),TEST_RUN_META:JSON.stringify(meta),TEST_OPERATION:op};
  const invoke=(mode='verify',extra={})=>spawnSync(process.execPath,[join(scripts,'verify-sampled-recovery-runner.mjs'),mode,reviewPath],
    {env:{...env,...extra},encoding:'utf8',timeout:15000,maxBuffer:65536});
  return {root,env,invoke,f,meta,proofPath,op,reviewPath,temp,destination:join(temp,'images-only-sampled-proof.json'),receipt:join(temp,'images-only-sampled-proof-verified.json')};
}
function failure(result){assert.equal(result.status,1);assert.equal(result.stdout,'');assert.equal(result.stderr,'SAMPLED_RECOVERY_RUNNER_FAIL=evidence_unavailable_or_mismatch\n');}
test('same-run evidence uses real filesystem/Node process and fixed gh boundary',t=>{
  const x=setup(t);const run=x.invoke();assert.equal(run.status,0,run.stderr);assert.match(run.stdout,/historical_only=true/);
  assert.equal(readFileSync(x.destination,'utf8'),x.f.proofRaw);assert.equal(x.invoke('check').status,0);
  const calls=readFileSync(x.env.TEST_GH_LOG,'utf8').trim().split('\n');assert.equal(calls.length,2);
  const before=sha256(readFileSync(x.destination));failure(x.invoke());assert.equal(sha256(readFileSync(x.destination)),before);
});
for(const field of ['headSha','headBranch','event','status','conclusion','attempt','workflowName','databaseId']){
  test('reject wrong historical run '+field,t=>{const x=setup(t);x.meta[field]=field==='attempt'?2:'wrong';failure(x.invoke('verify',{TEST_RUN_META:JSON.stringify(x.meta)}));assert.equal(existsSync(x.destination),false);});
}
for(const [name,change] of [
  ['missing receipt',x=>rmSync(x.receipt)],['proof changed',x=>writeFileSync(x.destination,'private-secret')],
  ['receipt changed',x=>writeFileSync(x.receipt,'private-secret')],['open proof permissions',x=>chmodSync(x.destination,0o644)],
  ['proof symlink',x=>{rmSync(x.destination);symlinkSync(x.proofPath,x.destination);}],
  ['proof hardlink',x=>linkSync(x.destination,join(x.root,'extra-link'))],
])test(name+' fails closed',t=>{const x=setup(t);assert.equal(x.invoke().status,0);change(x);failure(x.invoke('check'));});
test('receipt cannot be reused by another workflow run',t=>{const x=setup(t);assert.equal(x.invoke().status,0);failure(x.invoke('check',{GITHUB_RUN_ID:'790'}));});
for(const [name,change] of [
  ['truncated artifact',x=>writeFileSync(x.op,'private-secret')],
  ['oversized artifact',x=>writeFileSync(x.op,'x'.repeat(8388609))],
  ['symlinked committed proof',x=>{const target=join(x.root,'proof.json');writeFileSync(target,x.f.proofRaw);rmSync(x.proofPath);symlinkSync(target,x.proofPath);}],
  ['dirty committed proof bytes',x=>writeFileSync(x.proofPath,x.f.proofRaw+'\n')],
  ['proof UTF8 BOM',x=>writeFileSync(x.proofPath,'\ufeff'+x.f.proofRaw)],
  ['operation UTF8 BOM',x=>writeFileSync(x.op,'\ufeff'+x.f.operationRaw)],
  ['timeline UTF8 BOM',x=>writeFileSync(join(x.root,'release','image-cleanup-recovery-evidence',x.f.proof.source.timeline_sha256+'.json'),'\ufeff'+x.f.timelineRaw)],
  ['alignment UTF8 BOM',x=>writeFileSync(join(x.root,'release','image-cleanup-recovery-evidence',x.f.proof.source.alignment_report_sha256+'.json'),'\ufeff'+x.f.alignmentRaw)],
])test(name+' is rejected before evidence receipt',t=>{const x=setup(t);change(x);failure(x.invoke());assert.equal(existsSync(x.receipt),false);});
test('wrong main identity fails before gh',t=>{const x=setup(t);failure(x.invoke('verify',{GITHUB_REF:'refs/heads/develop'}));assert.equal(existsSync(x.env.TEST_GH_LOG),false);});
