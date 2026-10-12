import test from 'node:test';
import assert from 'node:assert/strict';
import {canonical,sha256,validateProof,verifyHistoricalProof,GATE_ORDER} from './verify-sampled-recovery-proof.mjs';
import {fixture} from './fixtures/sampled-recovery/fixture.mjs';
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
