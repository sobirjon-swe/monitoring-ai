import { expect, it } from 'vitest';
import { historyPath, locatorFor, parsePage, parseProgress, parseVerification } from '../api/readAdapters';
const id='11111111-1111-4111-8111-111111111111';
it('accepts the canonical management dependency array and rejects a scalar replacement',()=>{
  const item={action_id:id,event_id:id,revision:1,operation:'action.open',state:'open',support_status:'not_recorded',review_status:'not_reviewed',underlying_dependency_status:['open','scope_change_required'],reference_results:[],reason_codes:[],current_reason_codes:[]};
  const page={items:[item],next_cursor:null,visible_record_count:1,coverage_status:'complete',reasons:[],synthetic:true,ai_interpretation:'unavailable'};
  expect(parsePage(page,'management').items[0].underlying_dependency_status).toEqual(['open','scope_change_required']);
  expect(()=>parsePage({...page,items:[{...item,underlying_dependency_status:'completed'}]},'management')).toThrow('invalid_response');
});
it('rejects unsupported page/coverage/AI shapes instead of treating them as empty complete data',()=>{
  for(const value of [[],{}, {items:[],next_cursor:null,visible_record_count:0,coverage_status:'unknown',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'}, {items:[],next_cursor:null,visible_record_count:0,coverage_status:'new_state',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'}]) {
    expect(()=>parsePage(value,'qa')).toThrow('invalid_response');
  }
});
it('never substitutes a zero denominator or a synthetic progress percentage',()=>{
  const progress={status:'unavailable',accepted_weight:0,total_weight:0,percentage_basis_points:null,baseline_id:null,policy_id:null,coverage_reason_codes:['baseline_missing']};
  expect(parseProgress(progress).percentage_basis_points).toBeNull();
  expect(()=>parseProgress({...progress,percentage_basis_points:0})).toThrow('invalid_response');
});
it('restricts queue history locators to the returned root type and approved route',()=>{
  expect(locatorFor('qa',{id,revision:1})).toEqual({domain:'qa',kind:'handoff',id});
  expect(historyPath(id,locatorFor('ba',{clarification_id:id}))).toBe(`/browser/v1/projects/${id}/ba/history/${id}?limit=20`);
  expect(()=>historyPath(id,{domain:'qa',kind:'anything',id} as never)).toThrow('invalid_response');
  expect(()=>locatorFor('management',{action_id:'https://elsewhere.test'})).toThrow('invalid_response');
});
it('rejects a renamed QA metric basis rather than presenting it as pass rate',()=>{
  expect(()=>parseVerification({coverage_status:'complete',reason_codes:[],synthetic:true,ai_interpretation:'unavailable',qa_verification:{basis:'pass_rate'},counts:{},critical_signals:{}})).toThrow('invalid_response');
});
