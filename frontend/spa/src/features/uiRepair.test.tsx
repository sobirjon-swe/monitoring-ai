import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { fieldText, readText } from '../i18n/read';
import { formatDate, type Locale } from '../i18n';
import { Fields } from './readWorkspace';
import { QueueWorkspace } from './QueueWorkspace';
import { ApiClient } from '../api/client';

const session=vi.hoisted(()=>({context:{} as Record<string,unknown>}));
vi.mock('../auth/SessionProvider',()=>({useSession:()=>session.context}));
const locales:Locale[]=['uz','ru','en'];
const metadataKeys=['roles','role','valid_from','valid_to','employee_id','task_hash','input_hash','membership','mapping','attribution','occurred_at','reason_code','task_revision','task_version_id','age','age_basis','business_age','business_age_reason','review_id','links','criterion_id','criterion_version_id','relation','requirement_version_id','requirement_hash','effective_until','phase','variant','source_instance_id','object_id','source_updated_at','subject_answer_version_id','request','decision_owner_party_version_id','request_hash','links_hash','assignment_id','recorder_id','text','support_hash','references_hash','current_action_version_id','current_assignment_id','current_support_id','current_review_id','project_id'];
for(const locale of locales) {
  it(`labels browser-observed canonical metadata fields in ${locale}`,()=>{
    for(const key of metadataKeys) {
      expect(fieldText(locale,key)).not.toContain(readText(locale,'unknownCode'));
      expect(fieldText(locale,key)).not.toContain(readText(locale,'unknownField'));
    }
    expect(fieldText(locale,'future_metadata_field')).toBe(`${readText(locale,'unknownField')}: future_metadata_field`);
    expect(readText(locale,'future_status')).toBe(`${readText(locale,'unknownCode')}: future_status`);
  });
}
it('formats dates with numeric month and preserves the Tashkent timezone',()=>{
  const DateTimeFormat=Intl.DateTimeFormat;
  const options:Intl.DateTimeFormatOptions[]=[];
  const formatter=vi.spyOn(Intl,'DateTimeFormat').mockImplementation(function(locale,configuration){
    options.push(configuration??{});
    return new DateTimeFormat(locale,configuration);
  });
  try {
    for(const locale of locales) {
      const result=formatDate(locale,'2026-10-24T00:00:00Z');
      expect(result).toContain('2026');
      expect(result).not.toMatch(/M10|October|окт|okt/i);
    }
    expect(options).toEqual(locales.map(()=>({year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',timeZone:'Asia/Tashkent'})));
    expect(formatDate('uz','invalid')).toBe('—');
  } finally {formatter.mockRestore();}
});
it('keeps the BA customer wait label separate from the unverified-authenticity warning',async()=>{
  const id='11111111-1111-4111-8111-111111111111';
  session.context={isCurrent:()=>true,client:new ApiClient(async()=>new Response(JSON.stringify({data:{items:[{clarification_id:id,revision:1,question:'Original source question',clarification_state:'open',applicability:'current',answer_review_status:'unanswered',scope_status:'not_requested',dependency_status:'unresolved',waiting_on:'customer',reasons:[],customer_authenticity:'not_verified'}],next_cursor:null,visible_record_count:1,coverage_status:'complete',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'}})))};
  render(<QueueWorkspace projectId={id} scopeGeneration={1} locale="en" domain="ba"/>);
  expect(await screen.findByText('Customer',{selector:'dd'})).toBeTruthy();
  expect(screen.getByText('Customer authenticity is not verified.')).toBeTruthy();
  expect(screen.getByText('Original source question').closest('details')).toBeNull();
});
it('discloses hashes and provenance while keeping source text and dependency meaning primary',()=>{
  const source='Asl manba matni — <b>hech qanday tarjimasiz</b>';
  const record={state:'completed',underlying_dependency_status:['blocked'],support:{text:source,outcome:'completed',support_hash:'a'.repeat(64),helper_id:'helper-original-id'},responsibility:[{attribution:'known',employee_id:'employee-original-id',membership:{valid_from:'2026-10-01T00:00:00Z',valid_to:null},roles:[{role:'developer',valid_from:'2026-10-01T00:00:00Z',valid_to:null}],task_hash:'b'.repeat(64)}]};
  const view=render(<Fields record={record} fields={Object.keys(record)} locale="en"/>);
  expect(screen.getByText('Blocked').closest('details')).toBeNull();
  expect(screen.getByText(source).closest('details')).toBeNull();
  const hash=screen.getByText('a'.repeat(64));
  const provenance=screen.getByText('employee-original-id');
  expect(hash.closest('details')).not.toBeNull();
  expect(hash.closest('details')?.open).toBe(false);
  expect(provenance.closest('details')).not.toBeNull();
  expect(provenance.closest('details')?.open).toBe(false);
  const summaries=screen.getAllByText('Technical and version details',{selector:'summary'});
  expect(summaries.length).toBeGreaterThan(0);
  for(const locale of locales) {
    view.rerender(<Fields record={record} fields={Object.keys(record)} locale={locale}/>);
    expect(screen.getByText(source)).toBeTruthy();
    expect(screen.getByText('a'.repeat(64))).toBeTruthy();
    expect(screen.getByText('employee-original-id')).toBeTruthy();
  }
});
