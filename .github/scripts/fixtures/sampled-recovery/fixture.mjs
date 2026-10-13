// Entirely synthetic historical evidence. Never a production approval.
import {canonical,sha256,GATE_ORDER} from '../../verify-sampled-recovery-proof.mjs';
import {LIMITS,CASCADE} from '../../sampled-recovery-trace.mjs';
export function fixture(options={}){
  const h=n=>n.toString(16).padStart(64,'0'),sh=n=>n.toString(16).padStart(40,'0');
  const hash=x=>sha256(canonical(x)+'\n'),b64=x=>Buffer.from(canonical(x)).toString('base64');
  const images=Array.from({length:7},(_,n)=>({id:'sha256:'+h(n+101),size_bytes:1024,
    created_at:'2026-01-01T00:00:00Z',revision:sh(n+1),source:'https://github.com/Hao-Monster/Xboardme',tags:[],digests:[]}));
  const containers=Array.from({length:6},(_,n)=>({id:h(n+1),name:`fixture-${n}`,image_id:images[n].id,status:'running',
    started_at:'2026-01-01T00:00:00Z',restart_count:0,health:'healthy',project:'xboard-v2-123-1',
    service:['redis','web','ws','edge','horizon','scheduler'][n],revision:sh(n+1),maintenance_release:'none',
    config_sha256:h(201),host_config_sha256:h(202),ports:{},networks:{},mounts:[]}));
  const inventory={containers,images},startup={schema_version:1,image_ids:[],revisions:[],digests:[],references:[],files:[],compose_metadata_sha256:h(301)};
  const protection={upstream:'127.0.0.1:7002',containers};
  const gate={schema_version:2,status:'HOLD',owner_uid:0,session_id:options.sessionId??'11111111-1111-4111-8111-111111111111',
    workflow_sha:options.workflowSha??sh(50),review_sha256:options.reviewHash??h(501),active_release_id:'123-1',active_release_sha:sh(1),
    protection_fingerprint:hash(protection),image_id:images[6].id,baseline_seconds:120,observe_seconds:300,
    sample_interval_seconds:10,started_at_uptime:1000,last_heartbeat_uptime:1200,baseline_started_at_uptime:1020,
    baseline_completed_at_uptime:1141,intent_at_uptime:null,removed_at_uptime:null,observation_completed_at_uptime:null,
    completed_at_uptime:null,sample_count:19,cleanup_validated:false,failure_reason:'resource_limit_or_source_failed',
    post_verification:'pending',post_evidence_sha256:null,post_verified_at_uptime:null,monitoring_mode:'passive_runtime_v1'};
  const audit={active_state_sha256:h(401),inventory_sha256:hash(inventory),startup_sha256:hash(startup),
    protection_fingerprint:hash(protection),resource_fingerprint:h(402),container_count:6,image_count:7};
  const plan={schema_version:2,operation:'image_cleanup',review_sha256:gate.review_sha256,workflow_sha:gate.workflow_sha,
    active_state_sha256:audit.active_state_sha256,containers:[],images:[images[6]],inventory,startup};
  const lines=[`IMAGE_REQUEST_REVIEW_SHA256=${gate.review_sha256}`,'IMAGE_REQUEST_MONITORING_MODE=passive_runtime_v1',
    `IMAGE_REQUEST=PASS action=cleanup sha=${gate.workflow_sha}`,'IMAGE_RESTORE_EVIDENCE=PASS mode=passive_runtime_v1'];
  if(options.priorProofHash)lines.push(`SAMPLED_RECOVERY_PROOF=PASS proof_sha256=${options.priorProofHash} historical_only=true`);
  const fields={SCHEMA:'1',WORKFLOW_SHA:gate.workflow_sha,ACTIVE_RELEASE_ID:gate.active_release_id,ACTIVE_REVISION:gate.active_release_sha,
    ACTIVE_STATE_SHA256:audit.active_state_sha256,ACTIVE_UPSTREAM:protection.upstream,TRAFFIC_STATE:'finalized',ROLLBACK_SUPPORTED:'false',
    RESOURCE_FINGERPRINT:audit.resource_fingerprint,INVENTORY_SHA256:audit.inventory_sha256,CONTAINER_COUNT:'6',IMAGE_COUNT:'7',
    PROTECTION_FINGERPRINT:audit.protection_fingerprint,PROTECTION_B64:b64(protection),STARTUP_SHA256:audit.startup_sha256,
    STARTUP_B64:Buffer.from(canonical(startup)+'\n').toString('base64'),DISK_AVAILABLE_KIB:'6000000',
    CADDY_MainPID:'42',CADDY_ActiveState:'active',CADDY_ActiveEnterTimestampMonotonic:'1000',CADDY_InvocationID:'a'.repeat(32),
    LIVE_SIGNALS:'missing',COMPLETED_AT_EPOCH:'1790000000'};
  for(const [key,value] of Object.entries(fields))lines.push(`IMAGE_AUDIT_${key}=${value}`);
  for(const c of containers)lines.push(`IMAGE_AUDIT_CONTAINER_SNAPSHOT id=${c.id} snapshot_b64=${b64(c)}`);
  for(const i of images)lines.push(`IMAGE_AUDIT_IMAGE_SNAPSHOT id=${i.id} container_refs=${containers.filter(c=>c.image_id===i.id).length} snapshot_sha256=${hash(i)} snapshot_b64=${b64(i)}`);
  lines.push('IMAGE_AUDIT=PASS mode=read_only lifecycle=preserved');
  const events=[],epoch=Date.parse('2026-01-01T00:00:00Z');
  const event=(line,millis)=>{lines.push(line);events.push({operation_line:lines.length,at:new Date(epoch+millis).toISOString()});};
  for(let n=0;n<19;n++){
    const resource={schema_version:1,result:'PASS',phase:n===0?'initial':'delta',mem_available_kib:1048576,io_full_avg10:0,
      cpu_some_avg10:1,cpu_busy_percent:n===0?null:10,cpu_iowait_percent:n===0?null:0,thresholds:LIMITS,violations:[]};
    event('RETENTION_RESOURCE_SAMPLE='+canonical(resource),n*10000);
    event(`RETENTION_PASSIVE_SAMPLE=PASS uptime=${1020+n*10} cpu_busy_percent=${n===0?'initial_sample':'10'} cpu_iowait_percent=${n===0?'initial_sample':'0'} mem_available_kib=1048576 io_full_avg10=0 cpu_some_avg10=1 http=not_run connections=not_measured`,n*10000+300);
    if(n===12)event(`RETENTION_CONTINUITY_BASELINE=PASS seconds=120 session=${gate.session_id}`,121400);
  }
  event('RETENTION_RESOURCE_SAMPLE='+canonical({schema_version:1,result:'FAIL',phase:'delta',mem_available_kib:1048576,io_full_avg10:0,
    cpu_some_avg10:20.34,cpu_busy_percent:10,cpu_iowait_percent:0,thresholds:LIMITS,violations:['max_cpu_some_avg10']}),190000);
  CASCADE.forEach((line,n)=>event(line,190500+n*500));
  const operationRaw=lines.join('\n')+'\n';
  const source={repository:'Hao-Monster/Xboardme',run_id:options.runId??'123456',run_attempt:1,workflow_sha:gate.workflow_sha,
    event:'workflow_dispatch',branch:'main',conclusion:'failure',raw_workflow_log_sha256:h(801),operation_artifact_sha256:sha256(operationRaw)};
  const alignmentRaw=canonical({schema_version:1,purpose:'github_masked_non_event_alignment',repository:source.repository,run_id:source.run_id,
    run_attempt:1,raw_workflow_log_sha256:source.raw_workflow_log_sha256,operation_artifact_sha256:source.operation_artifact_sha256,
    artifact_line_count:lines.length,exact_event_line_count:events.length,exact_non_event_line_count:lines.length-events.length,
    inventory_from_masked_log:false,exceptions:[]})+'\n';
  source.alignment_report_sha256=sha256(alignmentRaw);
  const timelineRaw=canonical({schema_version:1,purpose:'sampled_predelete_resource_evidence',source,events})+'\n';
  const proof={schema_version:1,mode:'sampled_predelete_trace_v1',gate_sha256:sha256(JSON.stringify(Object.fromEntries(GATE_ORDER.map(k=>[k,gate[k]])),null,2)+'\n'),
    plan_sha256:hash(plan),gate,source:{...source,timeline_sha256:sha256(timelineRaw)},audit,
    trace:{sample_uptimes:Array.from({length:19},(_,n)=>1020+n*10),baseline_after_samples:13,baseline_wall_nanoseconds:'121100000000'}};
  const proofRaw=canonical(proof)+'\n',proofHash=sha256(proofRaw);
  return {proofRaw,proofHash,operationRaw,timelineRaw,alignmentRaw,proof,inventory,plan};
}

export function recoveryFixture(){
  const prior=fixture();
  const current=fixture({workflowSha:'b'.repeat(40),runId:'234567',reviewHash:'c'.repeat(64),
    sessionId:'22222222-2222-4222-8222-222222222222',priorProofHash:prior.proofHash});
  current.priorRecovery={proofRaw:prior.proofRaw,receiptRaw:canonical({schema_version:1,result:'PASS',
    proof_sha256:prior.proofHash,workflow_sha:current.proof.source.workflow_sha,run_id:current.proof.source.run_id,
    run_attempt:1,review_sha256:current.proof.gate.review_sha256,source_run_id:prior.proof.source.run_id,
    source_run_attempt:1,source_workflow_sha:prior.proof.source.workflow_sha})+'\n'};
  return current;
}
