// Runner-only historical trace verification. No host access or write authority.
// Current locked production checks are independent and always remain required.
import { createHash } from 'node:crypto';

export const LIMITS = Object.freeze({ max_cpu_busy_percent: 85, max_cpu_iowait_percent: 10,
  max_cpu_some_avg10: 10, max_io_full_avg10: 1, min_mem_available_kib: 131072 });
export const CASCADE = Object.freeze([
  'RETENTION_CONTINUITY=HOLD reason=resource_limit_or_source_failed',
  'RETENTION_CONTINUITY_FAIL=resource_limit_or_source_failed',
  'RETENTION_CONTINUITY=HOLD reason=monitor_or_state_unhealthy',
  'RETENTION_CONTINUITY_FAIL=monitor_or_state_unhealthy',
  'IMAGE_CLEANUP_FAIL=continuity_check_failed',
  'RETENTION_CONTINUITY=HOLD reason=executor_exit_1',
]);
const SHA = /^[a-f0-9]{40}$/;
const HASH = /^(?!0{64}$)[a-f0-9]{64}$/;
const UUID = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
const IMAGE = /^sha256:[a-f0-9]{64}$/;
export const AUDIT_SCALARS = new Set(['IMAGE_AUDIT_SCHEMA','IMAGE_AUDIT_WORKFLOW_SHA','IMAGE_AUDIT_ACTIVE_RELEASE_ID',
  'IMAGE_AUDIT_ACTIVE_REVISION','IMAGE_AUDIT_ACTIVE_STATE_SHA256','IMAGE_AUDIT_ACTIVE_UPSTREAM',
  'IMAGE_AUDIT_TRAFFIC_STATE','IMAGE_AUDIT_ROLLBACK_SUPPORTED','IMAGE_AUDIT_RESOURCE_FINGERPRINT',
  'IMAGE_AUDIT_INVENTORY_SHA256','IMAGE_AUDIT_CONTAINER_COUNT','IMAGE_AUDIT_IMAGE_COUNT',
  'IMAGE_AUDIT_PROTECTION_FINGERPRINT','IMAGE_AUDIT_PROTECTION_B64','IMAGE_AUDIT_STARTUP_SHA256',
  'IMAGE_AUDIT_STARTUP_B64','IMAGE_AUDIT_DISK_AVAILABLE_KIB','IMAGE_AUDIT_CADDY_MainPID',
  'IMAGE_AUDIT_CADDY_ActiveState','IMAGE_AUDIT_CADDY_ActiveEnterTimestampMonotonic','IMAGE_AUDIT_CADDY_InvocationID',
  'IMAGE_AUDIT_LIVE_SIGNALS','IMAGE_AUDIT_COMPLETED_AT_EPOCH']);
function fail(code) { throw new Error(`SAMPLED_RECOVERY_TRACE_FAIL=${code}`); }
function demand(ok, code) { if (!ok) fail(code); }
function object(x) { return x !== null && typeof x === 'object' && !Array.isArray(x); }
function keys(x, expected) { return object(x) && Object.keys(x).sort().join('|') === [...expected].sort().join('|'); }
function matches(regex, value) { return typeof value === 'string' && regex.test(value); }
function clock(x) { return Number.isSafeInteger(x) && x >= 0; }
function number(x, max = Number.MAX_SAFE_INTEGER) { return Number.isFinite(x) && x >= 0 && x <= max; }
export function sha256(raw) { return createHash('sha256').update(raw).digest('hex'); }
export function canonical(x) {
  if (Array.isArray(x)) return `[${x.map(canonical).join(',')}]`;
  if (object(x)) return `{${Object.keys(x).sort().map(k => `${JSON.stringify(k)}:${canonical(x[k])}`).join(',')}}`;
  return JSON.stringify(x);
}
function eq(a, b) { return canonical(a) === canonical(b); }

// Reject duplicate keys, multiple documents and non-finite values. Native
// JSON.parse alone silently accepts duplicate keys; error details never escape.
export function strictJson(raw) {
  demand(typeof raw === 'string' && raw.length > 0 && raw.length <= 16 * 1024 * 1024, 'invalid_json');
  let i = 0;
  const ws = () => { while (i < raw.length && /[ \t\r\n]/.test(raw[i])) i++; };
  function parse(depth = 0) {
    demand(depth < 64, 'invalid_json'); ws(); const c = raw[i];
    if (c === '"') {
      const start = i++; let escaped = false;
      while (i < raw.length) {
        const ch = raw[i++];
        if (!escaped && ch === '"') {
          try { return JSON.parse(raw.slice(start, i)); } catch { fail('invalid_json'); }
        }
        if (!escaped && ch === '\\') escaped = true; else escaped = false;
      }
      fail('invalid_json');
    }
    if (c === '{' || c === '[') {
      const isObject = c === '{'; const result = isObject ? Object.create(null) : [];
      i++; ws(); if (raw[i] === (isObject ? '}' : ']')) { i++; return result; }
      for (;;) {
        if (isObject) {
          demand(raw[i] === '"', 'invalid_json'); const key = parse(depth + 1); ws();
          demand(raw[i++] === ':' && !Object.hasOwn(result, key), 'invalid_json');
          result[key] = parse(depth + 1);
        } else result.push(parse(depth + 1));
        ws(); const end = raw[i++];
        if (end === (isObject ? '}' : ']')) return result;
        demand(end === ',', 'invalid_json'); ws();
      }
    }
    const match = /^(?:true|false|null|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)/.exec(raw.slice(i));
    demand(match, 'invalid_json'); i += match[0].length;
    const value = JSON.parse(match[0]); demand(typeof value !== 'number' || Number.isFinite(value), 'invalid_json');
    return value;
  }
  try { const result = parse(); ws(); demand(i === raw.length, 'invalid_json'); return result; }
  catch { fail('invalid_json'); }
}
function instant(value) {
  demand(typeof value === 'string', 'invalid_timeline');
  const match = /^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.(\d{1,9}))?Z$/.exec(value);
  demand(match, 'invalid_timeline'); const ms = Date.parse(`${match[1]}Z`);
  demand(Number.isFinite(ms) && new Date(ms).toISOString().startsWith(match[1]), 'invalid_timeline');
  return BigInt(ms) * 1000000n + BigInt((match[2] ?? '').padEnd(9, '0'));
}
const seconds = (later, earlier) => Number(later - earlier) / 1e9;

function resource(raw, index) {
  const value = strictJson(raw);
  demand(keys(value, ['schema_version','result','phase','mem_available_kib','io_full_avg10',
    'cpu_some_avg10','cpu_busy_percent','cpu_iowait_percent','thresholds','violations']), 'resource_schema');
  demand(value.schema_version === 1 && eq(value.thresholds, LIMITS) &&
    value.phase === (index === 0 ? 'initial' : 'delta') && clock(value.mem_available_kib) &&
    number(value.io_full_avg10, 100) && number(value.cpu_some_avg10, 100) &&
    (index === 0 ? value.cpu_busy_percent === null && value.cpu_iowait_percent === null :
      number(value.cpu_busy_percent, 100) && number(value.cpu_iowait_percent, 100)), 'resource_schema');
  const violations = [];
  if (value.cpu_busy_percent > 85) violations.push('max_cpu_busy_percent');
  if (value.cpu_iowait_percent > 10) violations.push('max_cpu_iowait_percent');
  if (value.cpu_some_avg10 > 10) violations.push('max_cpu_some_avg10');
  if (value.io_full_avg10 > 1) violations.push('max_io_full_avg10');
  if (value.mem_available_kib < 131072) violations.push('min_mem_available_kib');
  demand(eq(value.violations, violations) && value.result === (violations.length ? 'FAIL' : 'PASS'), 'resource_result_mismatch');
  return value;
}
function passive(line, value, initial) {
  const match = /^RETENTION_PASSIVE_SAMPLE=PASS uptime=(\d+) cpu_busy_percent=(initial_sample|\d+) cpu_iowait_percent=(initial_sample|\d+) mem_available_kib=(\d+) io_full_avg10=(\d+(?:\.\d+)?) cpu_some_avg10=(\d+(?:\.\d+)?) http=not_run connections=not_measured$/.exec(line);
  demand(match && clock(Number(match[1])), 'passive_schema');
  demand(match[2] === (initial ? 'initial_sample' : String(Math.floor(value.cpu_busy_percent))) &&
    match[3] === (initial ? 'initial_sample' : String(Math.floor(value.cpu_iowait_percent))) &&
    Number(match[4]) === value.mem_available_kib && Number(match[5]) === value.io_full_avg10 &&
    Number(match[6]) === value.cpu_some_avg10, 'passive_resource_mismatch');
  return Number(match[1]);
}

export function traceEvidence(operationRaw, timelineRaw, expectedSource) {
  demand(typeof operationRaw === 'string' && operationRaw.length <= 16 * 1024 * 1024 &&
    !operationRaw.includes('\u0000') && !operationRaw.includes('\x1b'), 'invalid_artifact');
  const timeline = strictJson(timelineRaw);
  demand(keys(timeline, ['schema_version','purpose','source','events']) && timeline.schema_version === 1 &&
    timeline.purpose === 'sampled_predelete_resource_evidence' && eq(timeline.source, expectedSource), 'unbound_timeline_source');
  const source = timeline.source;
  demand(keys(source, ['repository','run_id','run_attempt','workflow_sha','event','branch','conclusion',
    'raw_workflow_log_sha256','operation_artifact_sha256','alignment_report_sha256']) && source.repository === 'Hao-Monster/Xboardme' &&
    matches(/^[1-9]\d*$/, source.run_id) && source.run_attempt === 1 && matches(SHA, source.workflow_sha) &&
    source.event === 'workflow_dispatch' && source.branch === 'main' && source.conclusion === 'failure' &&
    matches(HASH, source.raw_workflow_log_sha256) && matches(HASH, source.alignment_report_sha256) &&
    source.operation_artifact_sha256 === sha256(operationRaw), 'invalid_source_identity');
  const lines = operationRaw.replace(/\r\n/g, '\n').split('\n');
  if (lines.at(-1) === '') lines.pop();
  const start = lines.indexOf('IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved');
  demand(start >= 3 && lines.lastIndexOf(lines[start]) === start, 'missing_complete_base_audit');
  let reviewHash = null; let identityCount = 0;
  for (const line of lines.slice(0, start)) {
    demand(!/(?:_FAIL=|=FAIL|ERROR|RETENTION_|IMAGE_CLEANUP)/.test(line), 'unexpected_prelude');
    if (line.startsWith('IMAGE_REQUEST_REVIEW_SHA256=')) {
      const hash = line.slice(28); demand(HASH.test(hash) && (reviewHash === null || reviewHash === hash), 'admission_binding'); reviewHash = hash;
    } else if (line.startsWith('IMAGE_REQUEST=PASS action=cleanup sha=')) {
      demand(line === `IMAGE_REQUEST=PASS action=cleanup sha=${source.workflow_sha}`, 'admission_binding'); identityCount++;
    } else demand(line === 'IMAGE_REQUEST_MONITORING_MODE=passive_runtime_v1' ||
      line === 'IMAGE_RESTORE_EVIDENCE=PASS mode=passive_runtime_v1' ||
      (line.includes('=') && AUDIT_SCALARS.has(line.split('=', 1)[0])) ||
      /^IMAGE_AUDIT_(?:CONTAINER|IMAGE)_SNAPSHOT id=/.test(line), 'unexpected_prelude');
  }
  demand(reviewHash !== null && identityCount >= 1, 'admission_binding');
  const events = []; const samples = []; let baseline = null; let finalResource = null; let cursor = start + 1;
  while (cursor < lines.length) {
    const line = lines[cursor];
    if (line.startsWith('RETENTION_CONTINUITY_BASELINE=')) {
      const match = /^RETENTION_CONTINUITY_BASELINE=PASS seconds=120 session=([a-f0-9-]{36})$/.exec(line);
      demand(match && UUID.test(match[1]) && baseline === null && samples.length >= 13, 'invalid_baseline_marker');
      baseline = { session: match[1], after_samples: samples.length, operation_line: cursor + 1 };
      events.push(cursor + 1); cursor++; continue;
    }
    demand(line.startsWith('RETENTION_RESOURCE_SAMPLE='), 'unexpected_monitor_record');
    const metric = resource(line.slice(26), samples.length); events.push(cursor + 1);
    if (metric.result === 'FAIL') {
      demand(baseline !== null && eq(metric.violations, ['max_cpu_some_avg10']), 'non_cpu_psi_failure');
      finalResource = { operation_line: cursor + 1, metric }; cursor++; break;
    }
    demand(cursor + 1 < lines.length, 'missing_passive_pair');
    const uptime = passive(lines[cursor + 1], metric, samples.length === 0);
    samples.push({ resource_line: cursor + 1, passive_line: cursor + 2, uptime });
    events.push(cursor + 2); cursor += 2;
  }
  demand(finalResource && eq(lines.slice(cursor), CASCADE), 'invalid_failure_cascade');
  for (let j = 0; j < CASCADE.length; j++) events.push(cursor + j + 1);
  demand(Array.isArray(timeline.events) && timeline.events.length === events.length, 'timeline_event_set');
  const times = new Map(); let previous = null;
  timeline.events.forEach((event, index) => {
    demand(keys(event, ['operation_line','at']) && event.operation_line === events[index], 'timeline_event_set');
    const at = instant(event.at); demand(previous === null || at >= previous, 'timeline_regressed');
    previous = at; times.set(event.operation_line, at);
  });
  for (let j = 0; j < samples.length; j++) {
    const sample = samples[j];
    demand(seconds(times.get(sample.passive_line), times.get(sample.resource_line)) <= 10, 'sample_overrun');
    if (j) {
      const old = samples[j - 1]; const delta = sample.uptime - old.uptime;
      const wallDelta = seconds(times.get(sample.passive_line), times.get(old.passive_line));
      demand(delta > 0 && delta <= 30 && wallDelta > 0 && wallDelta <= 30 && Math.abs(wallDelta - delta) <= 2, 'sample_discontinuity');
    }
  }
  const failureAt = times.get(finalResource.operation_line);
  demand(seconds(failureAt, times.get(samples.at(-1).passive_line)) <= 30 &&
    seconds(times.get(cursor + 1), failureAt) <= 10 &&
    seconds(times.get(lines.length), failureAt) <= 30, 'failure_cascade_timeout');
  return { samples, baseline, times, reviewHash, source, finalResource };
}
