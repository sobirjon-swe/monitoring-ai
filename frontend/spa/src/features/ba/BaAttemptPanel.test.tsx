import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import { BaAttemptPanel } from './BaAttemptPanel';

const client={showBaAttempt:vi.fn(),claimBaAttempt:vi.fn(),executeBaAttempt:vi.fn(),ackBaAttempt:vi.fn(),request:vi.fn()};
const isCurrent=vi.fn(()=>true);
vi.mock('../../auth/SessionProvider',()=>({useSession:()=>({client,isCurrent})}));
const project='11111111-1111-4111-8111-111111111111', clarification='22222222-2222-4222-8222-222222222222';
const props={projectId:project,clarificationId:clarification,revision:1,scopeGeneration:1,locale:'en' as const,queuePath:'/queue',onRefresh:vi.fn(),onDenied:vi.fn()};
const history={items:[],next_cursor:null,visible_record_count:0,coverage_status:'complete',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'};
beforeEach(()=>{vi.resetAllMocks();isCurrent.mockReturnValue(true);client.showBaAttempt.mockRejectedValue(new ApiError('not_found',404));});
const event='33333333-3333-4333-8333-333333333333';
async function openReview(){
  client.showBaAttempt.mockResolvedValue({attempt_id:'existing',state:'completed'});
  client.executeBaAttempt.mockResolvedValue({receipt_id:'receipt',event_id:event});
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  fireEvent.click(await screen.findByText('Check result'));
}
it('allows navigating authorized history to a matching event on the next page',async()=>{
  client.request.mockResolvedValueOnce({...history,next_cursor:'cursor'}).mockResolvedValueOnce({...history,items:[{id:event,clarification_id:clarification,revision:2,kind:'context.add',context:'Visible context'}],visible_record_count:1});
  await openReview();await screen.findByText(/Event is not visible on this page/);
  fireEvent.click(screen.getByRole('button',{name:'Load more'}));
  await screen.findByText('Visible context');expect(screen.getByText(/Event is visible on this page/)).toBeTruthy();
  expect(client.request.mock.calls[1][0]).toContain('cursor=cursor');
  expect(screen.getByRole('button',{name:'First page'})).toBeTruthy();
});
it('permits a deliberate receipt acknowledgement when partial history does not show the event',async()=>{
  client.request.mockResolvedValue({...history,coverage_status:'partial',visible_record_count:null,reason_codes:['coverage_limit']});
  await openReview();await screen.findByText(/Event is not visible on this page/);
  expect(screen.getByText(/acknowledge the receipt without seeing the event/)).toBeTruthy();
  expect((screen.getByRole('button',{name:'Acknowledge receipt'}) as HTMLButtonElement).disabled).toBe(false);
});
it('blocks acknowledgement on history errors and clears the parent on denial',async()=>{
  client.request.mockRejectedValue(new ApiError('forbidden',403));await openReview();
  await waitFor(()=>expect(props.onDenied).toHaveBeenCalled());
  expect((screen.getByRole('button',{name:'Acknowledge receipt'}) as HTMLButtonElement).disabled).toBe(true);
});
it('discards a history page that arrives after scope changes',async()=>{
  let resolve!:(v:unknown)=>void;client.request.mockReturnValue(new Promise(r=>{resolve=r;}));
  await openReview();await screen.findByText(/Context saved/);isCurrent.mockReturnValue(false);
  resolve({...history,items:[{id:event,clarification_id:clarification,revision:2,kind:'context.add',context:'Old scope'}],visible_record_count:1});
  await waitFor(()=>expect(screen.queryByText('Old scope')).toBeNull());
  expect((screen.getByRole('button',{name:'Acknowledge receipt'}) as HTMLButtonElement).disabled).toBe(true);
});
it('recovers a lost claim without changing its frozen body and requires acknowledgement after execution',async()=>{
  client.claimBaAttempt.mockRejectedValueOnce(new ApiError('network')).mockResolvedValueOnce({attempt_id:'a',state:'claimed'});
  client.executeBaAttempt.mockResolvedValue({receipt_id:'receipt',event_id:'event'});client.ackBaAttempt.mockResolvedValue(undefined);client.request.mockResolvedValue(history);
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  const field=await screen.findByRole('textbox');fireEvent.change(field,{target:{value:'original'}});
  fireEvent.click(screen.getByText('Save'));await screen.findByText(/outcome is unknown/);
  fireEvent.click(screen.getByText('Check attempt'));await screen.findByText(/outcome is unknown/);
  fireEvent.click(screen.getByText('Save'));await screen.findByText(/attempt is active/);
  expect(client.claimBaAttempt.mock.calls).toEqual([[project,clarification,1,'original'],[project,clarification,1,'original']]);
  fireEvent.click(screen.getByText('Check result'));await screen.findByText(/Context saved/);
  await screen.findByText('receipt');expect(screen.getByText(/History may be incomplete/)).toBeTruthy();
  expect(client.ackBaAttempt).not.toHaveBeenCalled();await waitFor(()=>expect((screen.getByText('Acknowledge receipt') as HTMLButtonElement).disabled).toBe(false));fireEvent.click(screen.getByText('Acknowledge receipt'));
  await waitFor(()=>expect(props.onRefresh).toHaveBeenCalled());
});
it('keeps acknowledgement blocked until authorized history has loaded',async()=>{
  client.showBaAttempt.mockResolvedValue({attempt_id:'existing',state:'completed'});
  client.executeBaAttempt.mockResolvedValue({receipt_id:'receipt',event_id:'event'});
  let resolve!: (v:unknown)=>void;client.request.mockReturnValue(new Promise(r=>{resolve=r;}));
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  fireEvent.click(await screen.findByText('Check result'));await screen.findByText(/Context saved/);
  expect((screen.getByText('Acknowledge receipt') as HTMLButtonElement).disabled).toBe(true);
  resolve(history);await waitFor(()=>expect((screen.getByText('Acknowledge receipt') as HTMLButtonElement).disabled).toBe(false));
});
it('allows correction after a definitive initial claim validation rejection',async()=>{
  client.claimBaAttempt.mockRejectedValueOnce(new ApiError('invalid_request',422));
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  const field=await screen.findByRole('textbox');fireEvent.change(field,{target:{value:'bad'}});
  fireEvent.click(screen.getByText('Save'));await screen.findByText(/rejected before claiming/);
  expect((screen.getByRole('textbox') as HTMLTextAreaElement).value).toBe('bad');
  fireEvent.change(screen.getByRole('textbox'),{target:{value:'corrected'}});
  expect((screen.getByText('Save') as HTMLButtonElement).disabled).toBe(false);
});
it('keeps execute validation rejection blocked because the server slot remains active',async()=>{
  client.showBaAttempt.mockResolvedValue({attempt_id:'existing',state:'claimed'});
  client.executeBaAttempt.mockRejectedValue(new ApiError('invalid_request',422));
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  fireEvent.click(await screen.findByText('Check result'));await screen.findByText(/outcome is unknown/);
  expect(screen.queryByRole('textbox')).toBeNull();
});
it('shows the refreshed record and requires explicit review after conflict',async()=>{
  client.claimBaAttempt.mockRejectedValue(new ApiError('conflict',409));
  client.request.mockResolvedValue({items:[{clarification_id:clarification,revision:2,question:'Updated question',clarification_state:'open',applicability:'current',dependency_status:'unresolved',scope_status:'not_requested',answer_review_status:'unanswered',reasons:[],customer_authenticity:'not_verified'}],next_cursor:null,visible_record_count:1,coverage_status:'complete',reason_codes:[],synthetic:true,ai_interpretation:'unavailable'});
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  const field=await screen.findByRole('textbox');fireEvent.change(field,{target:{value:'draft'}});
  fireEvent.click(screen.getByText('Save'));fireEvent.click(await screen.findByText('Check current state'));
  await screen.findByText('Updated question');expect((screen.getByText('Save') as HTMLButtonElement).disabled).toBe(true);
  fireEvent.click(screen.getByText('I have reviewed the current record'));
  expect((screen.getByRole('textbox') as HTMLTextAreaElement).value).toBe('draft');
  expect((screen.getByText('Save') as HTMLButtonElement).disabled).toBe(false);
});
it('resumes an existing slot without exposing a new form',async()=>{
  client.showBaAttempt.mockResolvedValue({attempt_id:'existing',state:'completed'});
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  await screen.findByText(/attempt is active/);expect(screen.queryByRole('textbox')).toBeNull();
  fireEvent.click(screen.getByText('Check result'));
  await waitFor(()=>expect(client.executeBaAttempt).toHaveBeenCalledWith(project,clarification,'existing'));
});
it('clears access through the parent on a denial',async()=>{
  client.showBaAttempt.mockRejectedValue(new ApiError('forbidden',403));
  render(<BaAttemptPanel {...props}/>);fireEvent.click(screen.getByText('Add context'));
  await waitFor(()=>expect(props.onDenied).toHaveBeenCalled());
  expect(screen.queryByRole('textbox')).toBeNull();
});
