// Pure runner-side verifier. A valid historical proof never authorizes deletion.
// The host independently checks its current, locked gate, plan and full state.
import { AUDIT_SCALARS, canonical, sha256, strictJson, traceEvidence } from './sampled-recovery-trace.mjs';
export { canonical, sha256, strictJson };
const HASH = /^(?!0{64}$)[a-f0-9]{64}$/;
const SHA = /^[a-f0-9]{40}$/;
const IMAGE = /^sha256:[a-f0-9]{64}$/;
const UUID = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
const object = x => x !== null && typeof x === 'object' && !Array.isArray(x);
const exact = (x, keys) => object(x) && canonical(Object.keys(x).sort()) === canonical([...keys].sort());
const clock = x => Number.isSafeInteger(x) && x >= 0;
const matches = (re, x) => typeof x === 'string' && re.test(x);
const hashDocument = x => sha256(canonical(x) + '\n');
const check = (ok, code) => { if (!ok) throw new Error(`SAMPLED_PROOF_FAIL=${code}`); };
export const GATE_ORDER = Object.freeze(['schema_version','status','owner_uid','session_id','workflow_sha','review_sha256',
  'active_release_id','active_release_sha','protection_fingerprint','image_id','baseline_seconds','observe_seconds',
  'sample_interval_seconds','started_at_uptime','last_heartbeat_uptime','baseline_started_at_uptime',
  'baseline_completed_at_uptime','intent_at_uptime','removed_at_uptime','observation_completed_at_uptime',
  'completed_at_uptime','sample_count','cleanup_validated','failure_reason','post_verification','post_evidence_sha256',
  'post_verified_at_uptime','monitoring_mode']);
const NULLS = ['intent_at_uptime','removed_at_uptime','observation_completed_at_uptime','completed_at_uptime',
  'post_evidence_sha256','post_verified_at_uptime'];
const SOURCE_KEYS = ['repository','run_id','run_attempt','workflow_sha','event','branch','conclusion',
  'operation_artifact_sha256','timeline_sha256','alignment_report_sha256','raw_workflow_log_sha256'];
const AUDIT_HASH_KEYS = ['active_state_sha256','inventory_sha256','startup_sha256','protection_fingerprint','resource_fingerprint'];
export function validateProof(raw, expectedHash) {
  check(typeof raw === 'string' && Buffer.byteLength(raw) <= 16384 && matches(HASH, expectedHash) && sha256(raw) === expectedHash, 'proof_bytes');
  const p = strictJson(raw);
  check(raw === canonical(p) + '\n' && exact(p, ['schema_version','mode','gate_sha256','plan_sha256','gate','source','audit','trace']) &&
    p.schema_version === 1 && p.mode === 'sampled_predelete_trace_v1', 'proof_schema');
  const g = p.gate, s = p.source, a = p.audit, t = p.trace;
  check(matches(HASH, p.gate_sha256) && matches(HASH, p.plan_sha256) && exact(g, GATE_ORDER) &&
    g.schema_version === 2 && g.status === 'HOLD' && g.monitoring_mode === 'passive_runtime_v1' &&
    clock(g.owner_uid) && matches(UUID, g.session_id) && matches(SHA, g.workflow_sha) && matches(HASH, g.review_sha256) &&
    matches(/^[0-9]+-[0-9]+$/, g.active_release_id) && matches(SHA, g.active_release_sha) &&
    matches(HASH, g.protection_fingerprint) && matches(IMAGE, g.image_id) &&
    g.baseline_seconds === 120 && g.observe_seconds === 300 && g.sample_interval_seconds === 10 &&
    ['started_at_uptime','last_heartbeat_uptime','baseline_started_at_uptime','baseline_completed_at_uptime','sample_count'].every(k => clock(g[k])) &&
    g.cleanup_validated === false && g.failure_reason === 'resource_limit_or_source_failed' &&
    ['pending','failed'].includes(g.post_verification) && NULLS.every(k => g[k] === null), 'gate_schema');
  // This fixed order is the actual jq gate writer's order, not canonical order.
  const gateRaw = JSON.stringify(Object.fromEntries(GATE_ORDER.map(k => [k,g[k]])), null, 2) + '\n';
  check(sha256(gateRaw) === p.gate_sha256, 'gate_raw_hash');
  check(exact(s, SOURCE_KEYS) && s.repository === 'Hao-Monster/Xboardme' && matches(/^[1-9][0-9]{0,14}$/,s.run_id) &&
    s.run_attempt === 1 && s.workflow_sha === g.workflow_sha && s.event === 'workflow_dispatch' &&
    s.branch === 'main' && s.conclusion === 'failure' &&
    SOURCE_KEYS.filter(k => k.endsWith('_sha256')).every(k => matches(HASH,s[k])), 'source_schema');
  check(exact(a,[...AUDIT_HASH_KEYS,'container_count','image_count']) && AUDIT_HASH_KEYS.every(k => matches(HASH,a[k])) &&
    clock(a.container_count) && a.container_count >= 6 && a.container_count <= 128 &&
    clock(a.image_count) && a.image_count >= 1 && a.image_count <= 8192 && a.protection_fingerprint === g.protection_fingerprint, 'audit_schema');
  check(exact(t,['sample_uptimes','baseline_after_samples','baseline_wall_nanoseconds']) &&
    Array.isArray(t.sample_uptimes) && t.sample_uptimes.length >= 13 && t.sample_uptimes.length <= 256 &&
    t.sample_uptimes.every(clock) && clock(t.baseline_after_samples) && t.baseline_after_samples >= 13 &&
    t.baseline_after_samples <= t.sample_uptimes.length && matches(/^(0|[1-9][0-9]{0,14})$/,t.baseline_wall_nanoseconds), 'trace_schema');
  const u = t.sample_uptimes, b = t.baseline_after_samples;
  const wallNs = BigInt(t.baseline_wall_nanoseconds), uptimeNs = BigInt(g.baseline_completed_at_uptime-u[0])*1000000000n;
  check(g.sample_count === u.length && g.baseline_started_at_uptime === u[0] && g.last_heartbeat_uptime === u.at(-1) &&
    u[0] >= g.started_at_uptime && u[0]-g.started_at_uptime <= 30 && u.every((x,i) => i === 0 || (x > u[i-1] && x-u[i-1] <= 30)) &&
    g.baseline_completed_at_uptime-u[0] >= 120 && g.baseline_completed_at_uptime <= u.at(-1) &&
    g.baseline_completed_at_uptime >= u[b-1] && (b === u.length || g.baseline_completed_at_uptime <= u[b]) &&
    wallNs-uptimeNs <= 2000000000n && uptimeNs-wallNs <= 2000000000n, 'trace_gate_binding');
  return p;
}
function base64Json(encoded, newline=false) {
  check(typeof encoded === 'string' && /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(encoded), 'base64');
  const bytes = Buffer.from(encoded,'base64');
  check(bytes.toString('base64') === encoded, 'base64');
  const raw = new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(bytes), value = strictJson(raw);
  check(raw === canonical(value) + (newline?'\n':''), 'noncanonical_snapshot');
  return value;
}
function parseCompleteAudit(raw, p) {
  const fields = new Map(), containers = [], images = [], refs = [];
  const lines = raw.replace(/\r\n/g,'\n').split('\n');
  const stop = lines.indexOf('IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved');
  check(stop >= 0, 'missing_audit');
  for (const line of lines.slice(0,stop)) {
    if (line.startsWith('IMAGE_AUDIT_CONTAINER_SNAPSHOT ')) {
      const m = /^IMAGE_AUDIT_CONTAINER_SNAPSHOT id=([a-f0-9]{64}) snapshot_b64=([A-Za-z0-9+/=]+)$/.exec(line);
      check(m,'container_line'); const c=base64Json(m[2]);
      check(exact(c,['id','name','image_id','status','started_at','restart_count','health','project','service','revision',
        'maintenance_release','config_sha256','host_config_sha256','ports','networks','mounts']) && c.id === m[1] &&
        matches(IMAGE,c.image_id) && ['running','exited'].includes(c.status) && typeof c.started_at === 'string' &&
        clock(c.restart_count) && matches(HASH,c.config_sha256) && matches(HASH,c.host_config_sha256) &&
        object(c.ports) && object(c.networks) && Array.isArray(c.mounts) &&
        (c.status !== 'running' || ['healthy','none'].includes(c.health)), 'container_snapshot');
      containers.push(c);
    } else if (line.startsWith('IMAGE_AUDIT_IMAGE_SNAPSHOT ')) {
      const m = /^IMAGE_AUDIT_IMAGE_SNAPSHOT id=(sha256:[a-f0-9]{64}) container_refs=([0-9]+) snapshot_sha256=([a-f0-9]{64}) snapshot_b64=([A-Za-z0-9+/=]+)$/.exec(line);
      check(m,'image_line'); const v=base64Json(m[4]);
      check(exact(v,['id','size_bytes','created_at','revision','source','tags','digests']) && v.id === m[1] &&
        clock(v.size_bytes) && ['created_at','revision','source'].every(k => typeof v[k] === 'string') &&
        Array.isArray(v.tags) && Array.isArray(v.digests) && [...v.tags,...v.digests].every(x=>typeof x==='string') &&
        hashDocument(v) === m[3], 'image_snapshot');
      images.push(v); refs.push(Number(m[2]));
    } else if (line.startsWith('IMAGE_AUDIT_')) {
      const m=/^(IMAGE_AUDIT_[A-Za-z0-9_]+)=(.*)$/.exec(line);
      check(m && !fields.has(m[1]),'audit_field'); fields.set(m[1],m[2]);
    }
  }
  const get = key => { check(fields.has(`IMAGE_AUDIT_${key}`),'missing_audit_field'); return fields.get(`IMAGE_AUDIT_${key}`); };
  check(fields.size === AUDIT_SCALARS.size && [...AUDIT_SCALARS].every(k => fields.has(k)), 'complete_audit_fields');
  const positiveScalar = key => /^[1-9][0-9]{0,14}$/.test(get(key)) && Number.isSafeInteger(Number(get(key)));
  check(['active_v2','finalized'].includes(get('TRAFFIC_STATE')) && ['true','false'].includes(get('ROLLBACK_SUPPORTED')) &&
    positiveScalar('DISK_AVAILABLE_KIB') && positiveScalar('COMPLETED_AT_EPOCH') &&
    ['missing','present_unverified'].includes(get('LIVE_SIGNALS')), 'audit_scalar_schema');
  const ordered = xs => [...xs].sort((a,b)=>a.id<b.id?-1:a.id>b.id?1:0);
  check(containers.length === p.audit.container_count && images.length === p.audit.image_count &&
    String(containers.length) === get('CONTAINER_COUNT') && String(images.length) === get('IMAGE_COUNT') &&
    new Set(containers.map(c=>c.id)).size === containers.length && new Set(images.map(i=>i.id)).size === images.length &&
    containers.every(c=>images.some(i=>c.image_id===i.id)) &&
    images.every((i,n)=>containers.filter(c=>c.image_id===i.id).length===refs[n]), 'complete_inventory');
  const inventory={containers:ordered(containers),images:ordered(images)};
  const startup=base64Json(get('STARTUP_B64'),true), protection=base64Json(get('PROTECTION_B64'));
  check(get('SCHEMA') === '1' && get('WORKFLOW_SHA') === p.source.workflow_sha &&
    get('ACTIVE_RELEASE_ID') === p.gate.active_release_id && get('ACTIVE_REVISION') === p.gate.active_release_sha &&
    get('ACTIVE_STATE_SHA256') === p.audit.active_state_sha256 && get('RESOURCE_FINGERPRINT') === p.audit.resource_fingerprint &&
    hashDocument(inventory) === p.audit.inventory_sha256 && get('INVENTORY_SHA256') === p.audit.inventory_sha256 &&
    hashDocument(startup) === p.audit.startup_sha256 && get('STARTUP_SHA256') === p.audit.startup_sha256 &&
    hashDocument(protection) === p.audit.protection_fingerprint && get('PROTECTION_FINGERPRINT') === p.audit.protection_fingerprint &&
    object(protection) && protection.upstream === get('ACTIVE_UPSTREAM') && /^127\.0\.0\.1:[1-9][0-9]{0,4}$/.test(protection.upstream) &&
    get('CADDY_ActiveState') === 'active' && /^[1-9][0-9]*$/.test(get('CADDY_MainPID')) && /^[a-f0-9]{32}$/.test(get('CADDY_InvocationID')) &&
    /^[1-9][0-9]*$/.test(get('CADDY_ActiveEnterTimestampMonotonic')), 'audit_binding');
  const target=images.find(i=>i.id===p.gate.image_id);
  check(target && !containers.some(c=>c.image_id===target.id),'target_consumed');
  const plan={schema_version:2,operation:'image_cleanup',review_sha256:p.gate.review_sha256,workflow_sha:p.gate.workflow_sha,
    active_state_sha256:p.audit.active_state_sha256,containers:[],images:[target],inventory,startup};
  check(hashDocument(plan) === p.plan_sha256,'plan_raw_hash');
  return {lines,inventory};
}
function verifyAlignment(raw, p, lines, timeline) {
  check(sha256(raw) === p.source.alignment_report_sha256,'alignment_hash');
  const a=strictJson(raw), eventLines=new Set(timeline.events.map(e=>e.operation_line));
  check(exact(a,['schema_version','purpose','repository','run_id','run_attempt','raw_workflow_log_sha256',
    'operation_artifact_sha256','artifact_line_count','exact_event_line_count','exact_non_event_line_count',
    'inventory_from_masked_log','exceptions']) && a.schema_version===1 && a.purpose==='github_masked_non_event_alignment' &&
    a.repository===p.source.repository && a.run_id===p.source.run_id && a.run_attempt===1 &&
    a.raw_workflow_log_sha256===p.source.raw_workflow_log_sha256 && a.operation_artifact_sha256===p.source.operation_artifact_sha256 &&
    a.inventory_from_masked_log===false && Array.isArray(a.exceptions) && a.exceptions.length<=256 &&
    a.artifact_line_count===lines.length && a.exact_event_line_count===eventLines.size &&
    clock(a.exact_non_event_line_count) && a.exact_event_line_count+a.exact_non_event_line_count+a.exceptions.length===lines.length, 'alignment_schema');
  const seen=new Set();
  for (const e of a.exceptions) {
    check(exact(e,['operation_line','field','artifact_line_sha256','log_line_sha256','artifact_line_bytes','log_line_bytes','replacements']) &&
      clock(e.operation_line) && e.operation_line>=1 && e.operation_line<=lines.length && !eventLines.has(e.operation_line) &&
      !seen.has(e.operation_line) && ['IMAGE_AUDIT_PROTECTION_B64','IMAGE_AUDIT_STARTUP_B64','IMAGE_AUDIT_CONTAINER_SNAPSHOT','IMAGE_AUDIT_IMAGE_SNAPSHOT'].includes(e.field) &&
      (lines[e.operation_line-1].startsWith(e.field+'=') || lines[e.operation_line-1].startsWith(e.field+' ')) &&
      sha256(lines[e.operation_line-1])===e.artifact_line_sha256 && matches(HASH,e.log_line_sha256) &&
      Buffer.byteLength(lines[e.operation_line-1])===e.artifact_line_bytes && clock(e.log_line_bytes) &&
      e.log_line_bytes>0 && e.log_line_bytes<=e.artifact_line_bytes && clock(e.replacements) && e.replacements>0,'alignment_exception');
    seen.add(e.operation_line);
  }
}
export function verifyHistoricalProof({proofRaw,proofHash,operationRaw,timelineRaw,alignmentRaw}) {
  const p=validateProof(proofRaw,proofHash);
  check(typeof operationRaw==='string' && Buffer.byteLength(operationRaw)<=8388608 &&
    sha256(operationRaw)===p.source.operation_artifact_sha256 && typeof timelineRaw==='string' &&
    Buffer.byteLength(timelineRaw)<=65536 && sha256(timelineRaw)===p.source.timeline_sha256 &&
    typeof alignmentRaw==='string' && Buffer.byteLength(alignmentRaw)<=65536, 'source_bytes');
  const {timeline_sha256:unused,...source}=p.source;
  const trace=traceEvidence(operationRaw,timelineRaw,source);
  const audit=parseCompleteAudit(operationRaw,p);
  if(audit.lines.at(-1)==='')audit.lines.pop();
  verifyAlignment(alignmentRaw,p,audit.lines,strictJson(timelineRaw));
  check(trace.reviewHash===p.gate.review_sha256 && trace.baseline.session===p.gate.session_id &&
    canonical(trace.samples.map(s=>s.uptime))===canonical(p.trace.sample_uptimes) &&
    trace.baseline.after_samples===p.trace.baseline_after_samples &&
    String(trace.times.get(trace.baseline.operation_line)-trace.times.get(trace.samples[0].passive_line))===p.trace.baseline_wall_nanoseconds, 'verified_trace_binding');
  return p;
}
