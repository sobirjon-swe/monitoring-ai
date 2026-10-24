import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import { readText } from './read';
import type { Locale } from './index';
import { Fields, Value } from '../features/readWorkspace';
import { parsePage } from '../api/readAdapters';

const locales:Locale[]=['uz','ru','en'];
const examples={
  uz:['Takroriy mezon.','Manba ma’lumotlari to‘liq emas.','Qayd etilmagan','Tekshirilmagan'],
  ru:['Повторяющийся критерий.','Данные источника неполные.','Не записано','Не проверено'],
  en:['Duplicate criterion.','Source data is incomplete.','Not recorded','Not reviewed'],
};
for(const locale of locales) {
  it(`labels the reviewed known QA/BA/management codes in ${locale}`,()=>{
    expect(['duplicate_criterion','source_incomplete','not_recorded','not_reviewed'].map(code=>readText(locale,code))).toEqual(examples[locale]);
  });
  it(`recognizes the bounded current read-producer code inventory in ${locale}`,()=>{
    const known=[
      'canonical_defect_unresolved','closure_not_current','critical_defect_applicability_unknown','critical_defect_unresolved','defect_scope_unknown','historical_context_unknown',
      'missing_request','missing_answer','missing_review','source_superseded','request_subject_proof_missing','request_subject_time_unknown','coordinator_time_unknown','link_missing','link_superseded','decision_identity_unknown','party_identity_unknown','party_superseded','answer_identity_unknown',
      'source_invalidation_provenance_unknown','source_selection_missing','source_terminal_changed','source_terminal_invalidity','source_tuple_invalid','assignment_missing',
      'none','recorded','unverified','reviewed','assigned','declined','withdrawn','answered','resolved','approved','acknowledged','triaged','verified_closed','reopened','scope_revised','duplicate','context_added','owner_changed',
      'action.open','action.reopen','action.assign','action.withdraw','support.record','support.correct','support.retract','support.decline','outcome.confirm','outcome.reject','outcome.revoke',
      'request.open','request.revise','request.reopen','request.withdraw','answer.record','answer.correct','answer.retract','review.confirm','review.reject','review.revoke','context.add',
      'distinct_included_criteria_with_current_completed_execution','approved_plan','department_authorized_entries','scope','pm','ba',
    ];
    for(const code of known) expect(readText(locale,code)).not.toContain(readText(locale,'unknownCode'));
  });
}
it('keeps genuinely new protocol codes explicit in every locale',()=>{
  for(const locale of locales) expect(readText(locale,'future_protocol_code')).toBe(`${readText(locale,'unknownCode')}: future_protocol_code`);
});
it('preserves source strings even when their text matches a known system code',()=>{
  const view=render(<Value value="not_recorded" field="question" locale="en"/>);
  for(const locale of locales) {
    view.rerender(<Value value="not_recorded" field="question" locale={locale}/>);
    expect(screen.getByText('not_recorded')).toBeTruthy();
  }
});
it('renders actual management read statuses without an unknown-code label',()=>{
  const id='11111111-1111-4111-8111-111111111111';
  const page=parsePage({items:[{action_id:id,event_id:id,revision:1,operation:'action.open',state:'assigned',support_status:'none',review_status:'unverified',underlying_dependency_status:['open'],reference_results:[],reason_codes:[],current_reason_codes:[]}],next_cursor:null,visible_record_count:1,coverage_status:'complete',reasons:[],synthetic:true,ai_interpretation:'unavailable'},'management');
  render(<Fields record={page.items[0]} fields={['state','support_status','review_status']} locale="en"/>);
  expect(screen.getByText('Assigned')).toBeTruthy();
  expect(screen.getByText('None')).toBeTruthy();
  expect(screen.getByText('Unverified')).toBeTruthy();
  expect(screen.queryByText(/Unrecognized code:/)).toBeNull();
});
