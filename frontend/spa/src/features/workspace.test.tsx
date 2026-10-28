import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import App from '../App';
import { ApiClient } from '../api/client';

const projectId = '11111111-1111-4111-8111-111111111111';
const setup = vi.hoisted(() => ({ context: {} as Record<string, unknown> }));
vi.mock('../auth/SessionProvider', () => ({
  SessionProvider: ({ children }: { children: React.ReactNode }) => children,
  useSession: () => setup.context,
}));
let replies: Record<string, unknown>;
let statuses: Record<string,number>;
let requests: string[];
beforeEach(() => {
  localStorage.setItem('monitoring.locale', 'en');
  statuses={};
  requests=[];
  replies = {
    capabilities: {project_id: projectId, purposes: ['project.read','progress.read','requirements.read','qa.read','qa.source.read','ba.read','ba.source.read','management.read','management.source.read'], observed_at: '2026-10-24T00:00:00Z'},
    progress: {status:'available', accepted_weight:18,total_weight:30,percentage_basis_points:6000,baseline_id:projectId,policy_id:projectId,coverage_reason_codes:[],as_of:'2026-10-24T00:00:00Z'},
    requirements: [], 'lifecycle-tasks': [],
    'qa/verification': {qa_verification:{basis:'distinct_included_criteria_with_current_completed_execution',scope_basis:'approved_plan',ratio_basis_points:null},selection:null,counts:{numerator:null,denominator:null,ratio_basis_points:null,fully_executed_criterion_count:0,all_required_passed_criterion_count:0,excluded_criterion_count:0,criteria:[],excluded:[],omitted_count:0},critical_signals:{codes:[],items:[],omitted_count:0,continuation_required:false},coverage_status:'unknown',reason_codes:['plan_missing'],synthetic:true,ai_interpretation:'unavailable'},
    'qa/queue': page([]), 'ba/queue': page([]), 'management/queue': {...page([]),reasons:[]},
  };
  const client = new ApiClient(async input => {
    requests.push(String(input));
    const suffix = String(input).split(`${projectId}/`)[1]?.split('?')[0];
    return new Response(JSON.stringify({data: replies[suffix]}),{status:statuses[suffix]??200});
  });
  setup.context = {client,state:{status:'authenticated',session:{user:{display_name:'Synthetic reviewer'}}},project:{id:projectId,name:'Synthetic project',revision:1},scopeGeneration:1,isCurrent:()=>true,selectProject:vi.fn(),logout:vi.fn()};
});
function page(items: unknown[], coverage = 'complete') {
  return {items,next_cursor:null,visible_record_count:coverage==='complete'?items.length:null,coverage_status:coverage,reason_codes:[],synthetic:true,ai_interpretation:'unavailable'};
}
function open(section: string) { history.replaceState(null,'',`/app/${section}`); render(<App/>); }
function verificationWithFailedExecution() {
  return {qa_verification:{basis:'distinct_included_criteria_with_current_completed_execution',scope_basis:'approved_plan',ratio_basis_points:10000},selection:{id:projectId,revision:1,plan_id:projectId,plan_review_id:projectId,build_id:projectId,environment_id:projectId},counts:{numerator:1,denominator:1,ratio_basis_points:10000,fully_executed_criterion_count:1,all_required_passed_criterion_count:0,excluded_criterion_count:0,criteria:{[projectId]:{required:1,optional:0,completed:1,passed:0,failed:1,blocked:0,missing:0,stale:0,unknown:0,required_completed:1,required_passed:0,fully_executed:true,all_required_passed:false,required_counts:{completed:1,passed:0,failed:1,blocked:0,missing:0,stale:0,unknown:0},optional_counts:{completed:0,passed:0,failed:0,blocked:0,missing:0,stale:0,unknown:0},tests:[{definition_version_id:projectId,definition_review_id:projectId,source_instance_id:projectId,required:true,release_critical:true,series_id:projectId,attempt_id:projectId,execution_id:projectId,status:'failed',reason_codes:[]}],reason_codes:[]}},excluded:[],omitted_count:0},critical_signals:{codes:['release_critical_test_failed'],items:[{code:'release_critical_test_failed',criterion_id:projectId,definition_version_id:projectId,execution_id:projectId,required:true}],omitted_count:0,continuation_required:false},coverage_status:'complete',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'};
}
it('shows accepted weight and baseline only from the supplied progress specimen', async () => {
  open('overview');
  expect(await screen.findByText('60%')).toBeTruthy();
  expect(screen.getByText('18 / 30')).toBeTruthy();
});
it('keeps missing baseline unavailable without inventing zero progress', async () => {
  replies.progress = {status:'unavailable',accepted_weight:null,total_weight:null,percentage_basis_points:null,baseline_id:null,policy_id:null,coverage_reason_codes:['baseline_missing'],as_of:'2026-10-24T00:00:00Z'};
  open('overview');
  expect(await screen.findByText('No approved baseline.')).toBeTruthy();
  expect(screen.queryByText('0%')).toBeNull();
});
it('distinguishes QA coverage from pass rate and accepted progress', async () => {
  open('qa');
  expect(await screen.findByText('Verification coverage')).toBeTruthy();
  expect(screen.getByText('Coverage is neither pass rate nor accepted progress.')).toBeTruthy();
  expect(screen.queryByText('0%')).toBeNull();
});
it('retains BA unverified customer and scope obligation', async () => {
  replies['ba/queue'] = {...page([{clarification_id:projectId,revision:1,question:'Original customer text',clarification_state:'open',applicability:'current',answer_review_status:'unanswered',scope_status:'scope_approval_pending',dependency_status:'unresolved',reasons:[],customer_authenticity:'not_verified'}]),customer_authenticity:'not_verified'};
  open('ba');
  expect(await screen.findByText('Customer authenticity is not verified.')).toBeTruthy();
  expect(screen.getByText('Original customer text')).toBeTruthy();
  expect(screen.getByText('Scope obligation')).toBeTruthy();
});
it('completed management support retains the underlying blocker', async () => {
  replies['management/queue'] = {...page([{action_id:projectId,event_id:projectId,revision:1,operation:'support',state:'completed',support_status:'provided',review_status:'accepted',underlying_dependency_status:['unresolved'],reference_results:[],reason_codes:[],current_reason_codes:[]}]),reasons:[]};
  open('management');
  expect(await screen.findByText('Underlying QA/BA dependency')).toBeTruthy();
  expect(screen.getByText('Unresolved')).toBeTruthy();
});
for(const domain of ['qa','ba','management']) {
  for(const coverage of ['complete','partial','unknown','unavailable']) {
    it(`${domain} preserves ${coverage} empty page and null totals`,async()=>{
      replies[`${domain}/queue`]={...page([],coverage),reasons:coverage==='complete'?[]:['coverage_limit']};
      open(domain);
      if(coverage==='complete') expect(await screen.findByText('No records in this authorized page.')).toBeTruthy();
      else expect(await screen.findByText('Total unknown')).toBeTruthy();
      expect(screen.queryByText('0%')).toBeNull();
    });
  }
  it(`${domain} treats the server denial as authoritative despite capability hints`,async()=>{
    statuses[`${domain}/queue`]=403;
    open(domain);
    expect(await screen.findByText('You do not have permission to view this information.')).toBeTruthy();
    expect(screen.queryByText('No records in this authorized page.')).toBeNull();
  });
}
it('shows requirement version metadata without inventing source text or evidence counts',async()=>{
  replies.requirements=[{id:projectId,requirement_id:projectId,version:3,draft_revision:2,content_hash:'a'.repeat(64),approved_at:'2026-10-24T00:00:00Z'}];
  open('requirements');
  expect((await screen.findAllByText('3')).length).toBeGreaterThan(0);
  expect(screen.getByText(/Requirement text and evidence lists are not supplied/)).toBeTruthy();
  fireEvent.click(screen.getByText('Version and evidence details',{selector:'summary'}));
  expect(screen.getByText('a'.repeat(64))).toBeTruthy();
});
it('shows a task source title as text and its exact immutable version',async()=>{
  replies['lifecycle-tasks']=[{id:projectId,version_id:projectId,revision:2,title:'<script>original</script>',assignee_id:projectId,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],status:'blocked',content_hash:'b'.repeat(64),effective_at:'2026-10-24T00:00:00Z'}];
  open('tasks');
  expect(await screen.findByText('<script>original</script>')).toBeTruthy();
  expect(document.querySelector('script')).toBeNull();
  expect(screen.getByText('Blocked',{selector:'dd'})).toBeTruthy();
});
it('uses each lifecycle list last UUID cursor and no invented page wrapper',async()=>{
  replies.requirements=Array.from({length:20},(_,index)=>({id:`11111111-1111-4111-8111-${String(index+1).padStart(12,'0')}`,requirement_id:projectId,version:1,draft_revision:1,content_hash:'a'.repeat(64),approved_at:'2026-10-24T00:00:00Z'}));
  open('requirements');
  fireEvent.click(await screen.findByRole('button',{name:'Load more'}));
  await waitFor(()=>expect(requests.some(path=>path.endsWith('requirements?limit=20&cursor=11111111-1111-4111-8111-000000000020'))).toBe(true));
});
it('filters only the loaded page while advancing using its original last row',async()=>{
  replies.requirements=Array.from({length:20},(_,index)=>({id:`11111111-1111-4111-8111-${String(index+1).padStart(12,'0')}`,requirement_id:projectId,version:index+1,draft_revision:1,content_hash:'a'.repeat(64),approved_at:'2026-10-24T00:00:00Z'}));
  open('requirements');
  const search=await screen.findByRole('searchbox',{name:'Search this page'});
  fireEvent.change(search,{target:{value:'000000000001'}});
  expect(screen.queryByText('11111111-1111-4111-8111-000000000020')).toBeNull();
  fireEvent.click(screen.getByRole('button',{name:'Load more'}));
  await waitFor(()=>expect(requests.some(path=>path.endsWith('requirements?limit=20&cursor=11111111-1111-4111-8111-000000000020'))).toBe(true));
});
it('sorts only the current page and restores original order',async()=>{
  replies.requirements=[2,1].map(index=>({id:`11111111-1111-4111-8111-${String(index).padStart(12,'0')}`,requirement_id:projectId,version:index,draft_revision:1,content_hash:'a'.repeat(64),approved_at:'2026-10-24T00:00:00Z'}));
  open('requirements');
  const select=await screen.findByRole('combobox',{name:'Sort this page'});
  fireEvent.change(select,{target:{value:'asc'}});
  let rows=screen.getAllByRole('row').slice(1);
  expect(rows[0].textContent).toContain('000000000001');
  fireEvent.change(select,{target:{value:'original'}});
  rows=screen.getAllByRole('row').slice(1);
  expect(rows[0].textContent).toContain('000000000002');
});
it('filters tasks by status and shows an explicit page-only empty result',async()=>{
  replies['lifecycle-tasks']=['blocked','active'].map((status,index)=>({id:`11111111-1111-4111-8111-${String(index+1).padStart(12,'0')}`,version_id:projectId,revision:2,title:index?'Second task':'First task',assignee_id:projectId,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],status,content_hash:'b'.repeat(64),effective_at:'2026-10-24T00:00:00Z'}));
  open('tasks');
  const status=await screen.findByRole('combobox',{name:'Status on this page'});
  fireEvent.change(status,{target:{value:'blocked'}});
  expect(screen.getByText('First task')).toBeTruthy();
  expect(screen.queryByText('Second task')).toBeNull();
  fireEvent.change(screen.getByRole('searchbox',{name:'Search this page'}),{target:{value:'no match'}});
  expect(screen.getByText('No matching records on this page.')).toBeTruthy();
});
it('localizes an unknown server reason without turning it into complete coverage',async()=>{
  replies['ba/queue']={...page([],'unknown'),reason_codes:['new_server_reason_with_long_label']};
  open('ba');
  expect(await screen.findByText('Unrecognized code: new_server_reason_with_long_label')).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'RU'}));
  expect(screen.getByText('Неподдерживаемый код: new_server_reason_with_long_label')).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'UZ'}));
  expect(screen.getByText('Qo‘llab-quvvatlanmagan kod: new_server_reason_with_long_label')).toBeTruthy();
});
it('links only typed QA subjects actually supplied by current verification and preserves a failed test at full coverage',async()=>{
  replies['qa/verification']=verificationWithFailedExecution();
  replies[`qa/history/build/${projectId}`]=page([{id:projectId,revision:1,content_hash:'c'.repeat(64)}]);
  open('qa');
  expect(await screen.findByText('100%')).toBeTruthy();
  expect(screen.getByText('Failed',{selector:'dd'})).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:`History: Build ${projectId}`}));
  await screen.findByText('c'.repeat(64));
  expect(requests.some(path=>path.includes(`/qa/history/build/${projectId}?limit=50`))).toBe(true);
});
it('clears earlier QA verification when the handoff request discovers source revocation',async()=>{
  replies['qa/verification']=verificationWithFailedExecution();
  statuses['qa/queue']=403;
  open('qa');
  await screen.findByText('You do not have permission to view this information.');
  expect(screen.queryByText('100%')).toBeNull();
});
it('returns keyboard focus to the queue history trigger when history closes',async()=>{
  replies['qa/queue']=page([{id:projectId,revision:1,state:'open',handoff_type:'verification_blocked',owner_id:projectId,opened_at:'2026-10-24T00:00:00Z',responsibility:{},age:{elapsed_seconds:10},applicability:'current',reason_codes:[]}]);
  replies[`qa/history/handoff/${projectId}`]=page([{id:projectId,revision:1,state:'open'}]);
  open('qa');
  const trigger=await screen.findByRole('button',{name:`History: ${projectId}`});
  trigger.focus();
  fireEvent.keyDown(trigger,{key:'Enter'});
  fireEvent.click(trigger);
  const heading=screen.getByRole('heading',{name:'History'});
  expect(document.activeElement).toBe(heading);
  await screen.findByRole('button',{name:'Close history'});
  fireEvent.keyDown(heading,{key:'Escape'});
  expect(screen.queryByRole('heading',{name:'History'})).toBeNull();
  expect(document.activeElement).toBe(trigger);
});
for(const domain of ['qa','ba','management']) {
  it(`${domain} distinguishes service unavailability from authorized empty success`,async()=>{
    statuses[`${domain}/queue`]=503;
    open(domain);
    expect(await screen.findByText('This view is unavailable.')).toBeTruthy();
    expect(screen.queryByText('No records in this authorized page.')).toBeNull();
  });
}
it('retains source language across all local system-language changes',async()=>{
  const source='Savol: talabning asl matni — '.repeat(15);
  replies['ba/queue']={...page([{clarification_id:projectId,revision:1,question:source,clarification_state:'open',applicability:'current',answer_review_status:'unanswered',scope_status:'scope_approval_pending',dependency_status:'unresolved',reasons:[],customer_authenticity:'not_verified'}]),customer_authenticity:'not_verified'};
  open('ba');
  expect(await screen.findByText(source.trim())).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'RU'}));
  expect(screen.getByText(source.trim())).toBeTruthy();
  expect(screen.getByText('Обязательство по объёму')).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'UZ'}));
  expect(screen.getByText(source.trim())).toBeTruthy();
});
it('uses the authoritative partial progress fraction without promoting it to complete',async()=>{
  replies.progress={status:'partial',accepted_weight:5,total_weight:9,percentage_basis_points:5555,baseline_id:projectId,policy_id:projectId,coverage_reason_codes:['acceptance_inputs_changed']};
  open('overview');
  expect(await screen.findByText('55.55%')).toBeTruthy();
  expect(screen.getByText('5 / 9')).toBeTruthy();
  expect(screen.getByText('Partial')).toBeTruthy();
  expect(screen.getByText('Acceptance inputs changed.')).toBeTruthy();
});
for(const domain of ['qa','ba','management']) {
  it(`${domain} preserves exact queue page size and opaque continuation`,async()=>{
    replies[`${domain}/queue`]={...page([]),reasons:[],next_cursor:'opaque+cursor='};
    open(domain);
    fireEvent.click(await screen.findByRole('button',{name:'Load more'}));
    await waitFor(()=>expect(requests.some(path=>path.endsWith(`${domain}/queue?limit=${domain==='qa'?50:20}&cursor=opaque%2Bcursor%3D`))).toBe(true));
  });
}
