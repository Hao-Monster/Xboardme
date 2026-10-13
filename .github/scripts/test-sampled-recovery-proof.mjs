import test from 'node:test';
import assert from 'node:assert/strict';
import {canonical,sha256,validateProof,verifyHistoricalProof,GATE_ORDER} from './verify-sampled-recovery-proof.mjs';
import {fixture,recoveryFixture} from './fixtures/sampled-recovery/fixture.mjs';
const reseal=f=>{f.proof.gate_sha256=sha256(JSON.stringify(Object.fromEntries(GATE_ORDER.map(k=>[k,f.proof.gate[k]])),null,2)+'\n');f.proofRaw=canonical(f.proof)+'\n';f.proofHash=sha256(f.proofRaw);return f;};
test('complete synthetic historical proof, no host mutation capability',()=>{
  const f=fixture();assert.equal(canonical(verifyHistoricalProof(f))+'\n',f.proofRaw);
  assert.equal(Object.hasOwn(verifyHistoricalProof(f),'recovery_authorized'),false);
});
for(const mutate of [p=>p.extra='secret',p=>delete p.audit,p=>p.source.branch='develop',p=>p.source.run_attempt=2,
  p=>p.source.extra='secret',p=>p.audit.image_count=0,p=>p.trace.sample_uptimes=[],p=>p.trace.baseline_after_samples=12,
  p=>p.trace.sample_uptimes[5]=p.trace.sample_uptimes[4],p=>p.trace.sample_uptimes[5]+=35,
  p=>p.trace.sample_uptimes[5]+=0.5,p=>p.trace.baseline_wall_nanoseconds='125000000000',
  p=>p.trace.baseline_wall_nanoseconds='0121100000000',p=>p.gate.sample_count=18,p=>p.gate.status='COMPLETE',
  p=>p.gate.failure_reason='other_failure',p=>p.gate.intent_at_uptime=1142,p=>p.gate.cleanup_validated=true,
  p=>p.gate.post_verification='passed',p=>p.gate.started_at_uptime=980,p=>p.gate.observe_seconds=299,
  p=>p.gate.baseline_completed_at_uptime=1144,p=>delete p.gate.intent_at_uptime,p=>p.gate.extra='secret']){
  test('reject claim '+mutate.toString(),()=>{const f=fixture();mutate(f.proof);reseal(f);assert.throws(()=>validateProof(f.proofRaw,f.proofHash));});
}
for(const transform of [raw=>'\ufeff'+raw,raw=>raw.replace(/\n/g,'\r\n'),raw=>raw+'\n',raw=>raw.trimEnd(),
  raw=>raw.replace('{','{"schema_version":1,'),raw=>raw.replace('{','{"\\u0073chema_version":1,'),raw=>raw+'{}']){
  test('reject noncanonical bytes '+transform.toString(),()=>{const f=fixture();const raw=transform(f.proofRaw);assert.throws(()=>validateProof(raw,sha256(raw)));});
}
for(const field of ['operationRaw','timelineRaw','alignmentRaw']){
  test('reject tampered '+field,()=>{const f=fixture();f[field]+='secret';assert.throws(()=>verifyHistoricalProof(f));});
}
for(const mutate of [f=>{f.proof.trace.baseline_wall_nanoseconds='121200000000';},
  f=>{f.proof.plan_sha256='f'.repeat(64);},f=>{f.proof.audit.inventory_sha256='f'.repeat(64);},
  f=>{f.proof.audit.container_count=7;},f=>{f.proof.gate.review_sha256='f'.repeat(64);},
  f=>{f.proof.gate.session_id='22222222-2222-4222-8222-222222222222';}]){
  test('reject source binding '+mutate.toString(),()=>{const f=fixture();mutate(f);reseal(f);assert.throws(()=>verifyHistoricalProof(f));});
}
test('no partial-audit success even with rebound operation hash',()=>{
  const f=fixture();f.operationRaw=f.operationRaw.replace(/^IMAGE_AUDIT_IMAGE_SNAPSHOT.*\n/gm,'');
  f.proof.source.operation_artifact_sha256=sha256(f.operationRaw);reseal(f);assert.throws(()=>verifyHistoricalProof(f));
});
test('source errors do not echo JSON payload',()=>{
  try{validateProof('{"secret":"private"',sha256('{"secret":"private"'));assert.fail('accepted');}
  catch(e){assert.doesNotMatch(e.message,/private|secret/);}
});
test('sub-two-second wall-to-uptime rounding is permitted, larger drift is not',()=>{
  const f=fixture();f.proof.gate.baseline_completed_at_uptime=1143;reseal(f);
  assert.equal(validateProof(f.proofRaw,f.proofHash).gate.baseline_completed_at_uptime,1143);
});

// Rebind all source hashes after an audit-prefix mutation. These cases must
// fail for the bad content itself, not merely for a stale outer checksum.
function changeAudit(f, transform){
  const before=f.operationRaw.trimEnd().split('\n');
  f.operationRaw=transform(f.operationRaw);
  const after=f.operationRaw.trimEnd().split('\n'),delta=after.length-before.length;
  const alignment=JSON.parse(f.alignmentRaw),timeline=JSON.parse(f.timelineRaw);
  alignment.operation_artifact_sha256=sha256(f.operationRaw);
  alignment.artifact_line_count=after.length;alignment.exact_non_event_line_count+=delta;
  f.alignmentRaw=canonical(alignment)+'\n';
  timeline.source.operation_artifact_sha256=sha256(f.operationRaw);
  timeline.source.alignment_report_sha256=sha256(f.alignmentRaw);
  timeline.events.forEach(e=>{e.operation_line+=delta;});
  f.timelineRaw=canonical(timeline)+'\n';
  f.proof.source={...timeline.source,timeline_sha256:sha256(f.timelineRaw)};
  return reseal(f);
}
for(const field of ['TRAFFIC_STATE','ROLLBACK_SUPPORTED','DISK_AVAILABLE_KIB','LIVE_SIGNALS','COMPLETED_AT_EPOCH']){
  for(const kind of ['missing','invalid'])test(`rebound artifact rejects ${kind} ${field}`,()=>{
    const f=changeAudit(fixture(),raw=>raw.replace(new RegExp(`^IMAGE_AUDIT_${field}=.*\\n`,'m'),
      kind==='missing'?'':`IMAGE_AUDIT_${field}=private-canary\n`));
    assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=(complete_audit_fields|audit_scalar_schema)/);
  });
}
for(const kind of ['CONTAINER','IMAGE'])test(`rebound ${kind} snapshot must preserve raw UTF8 bytes`,()=>{
  const f=changeAudit(fixture(),raw=>raw.replace(new RegExp(`^(IMAGE_AUDIT_${kind}_SNAPSHOT .*snapshot_b64=)([A-Za-z0-9+/=]+)$`,'m'),
    (_,prefix,encoded)=>prefix+Buffer.concat([Buffer.from([0xef,0xbb,0xbf]),Buffer.from(encoded,'base64')]).toString('base64')));
  assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_RECOVERY_TRACE_FAIL=invalid_json/);
});

test('verified prior recovery permits another strictly predelete CPU-PSI failure',()=>{
  const f=recoveryFixture();assert.equal(canonical(verifyHistoricalProof(f))+'\n',f.proofRaw);
  assert.equal(Object.hasOwn(verifyHistoricalProof(f),'recovery_authorized'),false);
});
for(const [name,change] of [
  ['missing context',f=>delete f.priorRecovery],
  ['missing proof',f=>delete f.priorRecovery.proofRaw],
  ['missing receipt',f=>delete f.priorRecovery.receiptRaw],
  ['extra context key',f=>f.priorRecovery.verified=true],
  ['null context',f=>f.priorRecovery=null],
  ['non-string proof',f=>f.priorRecovery.proofRaw={}],
  ['non-string receipt',f=>f.priorRecovery.receiptRaw={}],
])test(`prior recovery rejects ${name}`,()=>{const f=recoveryFixture();change(f);assert.throws(()=>verifyHistoricalProof(f));});
for(const field of ['proofRaw','receiptRaw']){
  for(const [name,change] of [
    ['BOM',raw=>'\ufeff'+raw],['CRLF',raw=>raw.replace(/\n/g,'\r\n')],['extra newline',raw=>raw+'\n'],
    ['missing newline',raw=>raw.trimEnd()],['duplicate key',raw=>raw.replace('{','{"schema_version":1,')],
    ['escaped duplicate key',raw=>raw.replace('{','{"\\u0073chema_version":1,')],
    ['extra document',raw=>raw+'{}'],['oversized',raw=>raw+' '.repeat(16385)],
  ])test(`prior ${field} rejects ${name}`,()=>{
    const f=recoveryFixture(),raw=change(f.priorRecovery[field]);
    if(field==='proofRaw')changePriorBytes(f,raw);else f.priorRecovery[field]=raw;
    assert.throws(()=>verifyHistoricalProof(f));
  });
}
for(const [field,value] of [
  ['schema_version',2],['result','FAIL'],['proof_sha256','d'.repeat(64)],['review_sha256','d'.repeat(64)],
  ['workflow_sha','d'.repeat(40)],['run_id','345678'],['run_attempt',2],['source_run_id','345678'],
  ['source_run_attempt',2],['source_workflow_sha','d'.repeat(40)],['extra','private-canary'],
])test(`prior receipt binds ${field}`,()=>{
  const f=recoveryFixture(),receipt=JSON.parse(f.priorRecovery.receiptRaw);receipt[field]=value;
  f.priorRecovery.receiptRaw=canonical(receipt)+'\n';assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=prior_/);
});
for(const field of ['proof_sha256','review_sha256','workflow_sha','run_id','run_attempt','source_run_id','source_run_attempt','source_workflow_sha']){
  test(`prior receipt requires ${field}`,()=>{
    const f=recoveryFixture(),receipt=JSON.parse(f.priorRecovery.receiptRaw);delete receipt[field];
    f.priorRecovery.receiptRaw=canonical(receipt)+'\n';assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=prior_/);
  });
}
for(const [name,transform] of [
  ['missing marker',raw=>raw.replace(/^SAMPLED_RECOVERY_PROOF=.*\n/m,'')],
  ['wrong hash',raw=>raw.replace(/(SAMPLED_RECOVERY_PROOF=PASS proof_sha256=)[a-f0-9]{64}/,'$1'+'d'.repeat(64))],
  ['duplicate marker',raw=>raw.replace(/^(SAMPLED_RECOVERY_PROOF=.*\n)/m,'$1$1')],
  ['wrong result',raw=>raw.replace('SAMPLED_RECOVERY_PROOF=PASS','SAMPLED_RECOVERY_PROOF=FAIL')],
  ['non-historical marker',raw=>raw.replace('historical_only=true','historical_only=false')],
  ['extra marker text',raw=>raw.replace('historical_only=true','historical_only=true extra=private-canary')],
  ['marker before admission',raw=>{const marker=raw.match(/^SAMPLED_RECOVERY_PROOF=.*\n/m)[0];return marker+raw.replace(marker,'');}],
  ['marker inside audit',raw=>{const marker=raw.match(/^SAMPLED_RECOVERY_PROOF=.*\n/m)[0];return raw.replace(marker,'').replace('IMAGE_AUDIT_SCHEMA=1\n','IMAGE_AUDIT_SCHEMA=1\n'+marker);}],
  ['marker after audit',raw=>{const marker=raw.match(/^SAMPLED_RECOVERY_PROOF=.*\n/m)[0];return raw.replace(marker,'').replace('IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved\n','IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved\n'+marker);}],
])test(`rebound prior recovery rejects ${name}`,()=>{
  const f=changeAudit(recoveryFixture(),transform);assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_RECOVERY_TRACE_FAIL=/);
});

// Rebind the raw prior proof, its receipt and marker together, so failures below
// prove semantic identity/schema validation rather than merely a changed hash.
function changePrior(f,change){
  const prior={proof:JSON.parse(f.priorRecovery.proofRaw)};
  change(prior.proof);reseal(prior);
  const receipt=JSON.parse(f.priorRecovery.receiptRaw);
  receipt.source_run_id=prior.proof.source.run_id;
  receipt.source_run_attempt=prior.proof.source.run_attempt;receipt.source_workflow_sha=prior.proof.source.workflow_sha;
  f.priorRecovery.receiptRaw=canonical(receipt)+'\n';
  return changePriorBytes(f,prior.proofRaw);
}
function changePriorBytes(f,priorRaw){
  const oldHash=sha256(f.priorRecovery.proofRaw),newHash=sha256(priorRaw),receipt=JSON.parse(f.priorRecovery.receiptRaw);
  f.priorRecovery.proofRaw=priorRaw;receipt.proof_sha256=newHash;f.priorRecovery.receiptRaw=canonical(receipt)+'\n';
  return changeAudit(f,raw=>raw.replace(`proof_sha256=${oldHash}`,`proof_sha256=${newHash}`));
}
for(const [name,change] of [
  ['same run',p=>p.source.run_id='234567'],
  ['same workflow',p=>{p.source.workflow_sha='b'.repeat(40);p.gate.workflow_sha=p.source.workflow_sha;}],
  ['same session',p=>p.gate.session_id='22222222-2222-4222-8222-222222222222'],
  ['different target',p=>p.gate.image_id='sha256:'+'d'.repeat(64)],
  ['different release',p=>p.gate.active_release_id='456-1'],
  ['different active SHA',p=>p.gate.active_release_sha='d'.repeat(40)],
  ['different protection',p=>{p.gate.protection_fingerprint='d'.repeat(64);p.audit.protection_fingerprint=p.gate.protection_fingerprint;}],
  ['different resources',p=>p.audit.resource_fingerprint='d'.repeat(64)],
  ['intent exists',p=>p.gate.intent_at_uptime=1142],
  ['changed failure',p=>p.gate.failure_reason='other_failure'],
  ['short baseline',p=>p.gate.baseline_seconds=119],
  ['short observation',p=>p.gate.observe_seconds=299],
])test(`rebound prior proof rejects ${name}`,()=>{
  const f=changePrior(recoveryFixture(),change);assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=/);
});
test('prior proof cannot substitute for the current failed run',()=>{
  const f=recoveryFixture();f.priorRecovery.proofRaw=f.proofRaw;assert.throws(()=>verifyHistoricalProof(f));
});
for(const field of ['intent_at_uptime','removed_at_uptime','observation_completed_at_uptime','completed_at_uptime',
  'post_evidence_sha256','post_verified_at_uptime']){
  for(const kind of ['current','prior'])test(`recovered ${kind} requires explicit null ${field}`,()=>{
    const f=recoveryFixture(),change=p=>p.gate[field]=field.endsWith('_sha256')?'d'.repeat(64):1142;
    if(kind==='prior')changePrior(f,change);else{change(f.proof);reseal(f);}
    assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=gate_schema/);
  });
}
for(const [name,change] of [
  ['non CPU-PSI failure',v=>{v.cpu_some_avg10=1;v.cpu_busy_percent=90;v.violations=['max_cpu_busy_percent'];}],
  ['multiple resource failures',v=>{v.mem_available_kib=1;v.violations.push('min_mem_available_kib');}],
  ['raised CPU threshold',v=>v.thresholds.max_cpu_some_avg10=30],
  ['false PASS result',v=>v.result='PASS'],
])test(`recovered current trace still rejects ${name}`,()=>{
  const f=changeAudit(recoveryFixture(),raw=>raw.replace(/^RETENTION_RESOURCE_SAMPLE=(.*)$/gm,(line,json)=>{
    const metric=JSON.parse(json);if(metric.result!=='FAIL')return line;
    change(metric);return 'RETENTION_RESOURCE_SAMPLE='+canonical(metric);
  }));
  assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_RECOVERY_TRACE_FAIL=/);
});
test('recovered current trace still requires the full 120-second baseline',()=>{
  const f=changeAudit(recoveryFixture(),raw=>raw.replace('RETENTION_CONTINUITY_BASELINE=PASS seconds=120',
    'RETENTION_CONTINUITY_BASELINE=PASS seconds=119'));
  assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_RECOVERY_TRACE_FAIL=invalid_baseline_marker/);
});
test('recovered prior receipt has an independent 4096-byte cap',()=>{
  const f=recoveryFixture();f.priorRecovery.receiptRaw=f.priorRecovery.receiptRaw.padEnd(4097,' ');
  assert.throws(()=>verifyHistoricalProof(f),/SAMPLED_PROOF_FAIL=prior_recovery_bytes/);
});
