// Runner only. Fetch one hash-bound historical artifact, never raw workflow logs.
import {constants,closeSync,fstatSync,lstatSync,mkdtempSync,openSync,readFileSync,realpathSync,writeFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {canonical,sha256,strictJson,validateProof,verifyHistoricalProof} from './verify-sampled-recovery-proof.mjs';
const check=ok=>{if(!ok)throw new Error('invalid_runner_evidence');};
const hashPattern=/^(?!0{64}$)[a-f0-9]{64}$/;
const base=resolve(dirname(fileURLToPath(import.meta.url)),'../release');
function readSafe(path,max,privateFile=false){
  check(realpathSync(path)===path);
  const before=lstatSync(path);
  check(before.isFile()&&!before.isSymbolicLink()&&before.nlink===1&&before.size>0&&before.size<=max);
  if(privateFile)check(before.uid===process.getuid()&&(before.mode&0o777)===0o600);
  const fd=openSync(path,constants.O_RDONLY|constants.O_NOFOLLOW);
  try{
    const actual=fstatSync(fd);check(actual.ino===before.ino&&actual.dev===before.dev);
    const bytes=readFileSync(fd);check(bytes.length===actual.size&&bytes.length<=max);
    return new TextDecoder('utf-8',{fatal:true,ignoreBOM:true}).decode(bytes);
  }finally{closeSync(fd);}
}
function readCommitted(directory,hash,max){
  check(hashPattern.test(hash));
  const parent=join(base,directory);check(realpathSync(parent)===parent&&lstatSync(parent).isDirectory());
  const raw=readSafe(join(parent,hash+'.json'),max);check(sha256(raw)===hash);return raw;
}
function gh(args,timeout=30000){
  return execFileSync('gh',[...args,'-R','Hao-Monster/Xboardme'],{encoding:'utf8',timeout,maxBuffer:65536,stdio:['ignore','pipe','pipe']});
}
try{
  check(process.argv.length===4&&['verify','check'].includes(process.argv[2])&&process.platform==='linux');
  const mode=process.argv[2],env=process.env,reviewPath=realpathSync(process.argv[3]);
  const reviewRaw=readSafe(reviewPath,262144),review=strictJson(reviewRaw),r=review.recovery;
  check(r?.schema_version===2&&r.mode==='predelete_sampled_hold_v2'&&typeof r.proof_sha256==='string'&&hashPattern.test(r.proof_sha256));
  check(env.GITHUB_REPOSITORY==='Hao-Monster/Xboardme'&&env.GITHUB_REF==='refs/heads/main'&&
    env.GITHUB_EVENT_NAME==='workflow_dispatch'&&env.GITHUB_RUN_ATTEMPT==='1'&&/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID??'')&&
    /^[a-f0-9]{40}$/.test(env.EXPECTED_SHA??'')&&env.EXPECTED_SHA===env.GITHUB_SHA&&env.EXPECTED_SHA===env.GITHUB_WORKFLOW_SHA&&
    env.GITHUB_WORKFLOW_REF==='Hao-Monster/Xboardme/.github/workflows/production-image-cleanup.yml@refs/heads/main');
  const temp=env.RUNNER_TEMP;check(typeof temp==='string'&&temp.startsWith('/')&&realpathSync(temp)===temp&&lstatSync(temp).isDirectory());
  const proofRaw=readCommitted('image-cleanup-recovery-proofs',r.proof_sha256,16384),proof=validateProof(proofRaw,r.proof_sha256);
  check(['gate_sha256','plan_sha256'].every(k=>r[k]===proof[k])&&['review_sha256','workflow_sha','session_id','image_id'].every(k=>r[k]===proof.gate[k])&&
    proof.source.workflow_sha!==env.EXPECTED_SHA&&proof.gate.active_release_id===review.active_release_id&&
    proof.gate.active_release_sha===review.active_release_sha&&proof.gate.protection_fingerprint===review.protection_fingerprint&&
    proof.gate.image_id===review.images[0].id&&proof.audit.resource_fingerprint===review.resource_fingerprint);
  const destination=join(temp,'images-only-sampled-proof.json'),receiptPath=join(temp,'images-only-sampled-proof-verified.json');
  const receipt={schema_version:1,result:'PASS',workflow_sha:env.EXPECTED_SHA,run_id:env.GITHUB_RUN_ID,run_attempt:1,
    review_sha256:sha256(reviewRaw),proof_sha256:r.proof_sha256,source_run_id:proof.source.run_id,
    source_run_attempt:1,source_workflow_sha:proof.source.workflow_sha};
  if(mode==='verify'){
    const s=proof.source,meta=strictJson(gh(['run','view',s.run_id,'--attempt','1','--json',
      'databaseId,attempt,headSha,headBranch,event,status,conclusion,workflowName']));
    check(String(meta.databaseId)===s.run_id&&meta.attempt===1&&meta.headSha===s.workflow_sha&&meta.headBranch==='main'&&
      meta.event==='workflow_dispatch'&&meta.status==='completed'&&meta.conclusion==='failure'&&meta.workflowName==='Production Image-Only Cleanup');
    const timelineRaw=readCommitted('image-cleanup-recovery-evidence',s.timeline_sha256,65536);
    const alignmentRaw=readCommitted('image-cleanup-recovery-evidence',s.alignment_report_sha256,65536);
    const download=mkdtempSync(join(temp,'.sampled-recovery-source-'));
    gh(['run','download',s.run_id,'--name',`production-image-cleanup-${s.workflow_sha}-${s.run_id}-1`,'--dir',download],120000);
    const operationRaw=readSafe(join(download,'images-only-operation.txt'),8388608);
    verifyHistoricalProof({proofRaw,proofHash:r.proof_sha256,operationRaw,timelineRaw,alignmentRaw});
    // Neither existing evidence nor an incomplete previous attempt is overwritten.
    writeFileSync(destination,proofRaw,{flag:'wx',mode:0o600});
    writeFileSync(receiptPath,canonical(receipt)+'\n',{flag:'wx',mode:0o600});
  }
  check(readSafe(destination,16384,true)===proofRaw&&readSafe(receiptPath,4096,true)===canonical(receipt)+'\n');
  process.stdout.write(`SAMPLED_RECOVERY_PROOF=PASS proof_sha256=${r.proof_sha256} historical_only=true\n`);
}catch{
  // Node/gh parse and filesystem errors can contain source fragments or paths.
  process.stderr.write('SAMPLED_RECOVERY_RUNNER_FAIL=evidence_unavailable_or_mismatch\n');process.exitCode=1;
}
