import { expect, it, vi } from 'vitest';
import { ApiClient } from './client';

const project='11111111-1111-4111-8111-111111111111';
const clarification='22222222-2222-4222-8222-222222222222';
const attempt='33333333-3333-4333-8333-333333333333';
it('uses the bounded coordinator routes and CSRF for claim, execute and acknowledgement', async () => {
  const fetcher=vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({data:{attempt_id:attempt,state:'claimed',replayed:false}}),{status:201})).mockResolvedValueOnce(new Response(JSON.stringify({data:{receipt_id:attempt,clarification_id:clarification,event_id:attempt,replayed:false,synthetic:true,ai_interpretation:'unavailable',customer_authenticity:'not_verified'}}))).mockResolvedValueOnce(new Response(null,{status:204}));
  const client=new ApiClient(fetcher); client.setCsrf('csrf');
  await client.claimBaAttempt(project,clarification,1,'hello');
  await client.executeBaAttempt(project,clarification,attempt);
  await client.ackBaAttempt(project,clarification,attempt);
  expect(fetcher.mock.calls[0][0]).toBe(`/browser/v1/projects/${project}/ba/clarifications/${clarification}/attempt`);
  expect(JSON.parse(fetcher.mock.calls[0][1].body)).toEqual({clarification_id:clarification,expected_revision:1,kind:'context.add',context:'hello'});
  expect(fetcher.mock.calls[0][1].headers['X-CSRF-TOKEN']).toBe('csrf');
  expect(JSON.parse(fetcher.mock.calls[1][1].body)).toEqual({attempt_id:attempt});
  expect(fetcher.mock.calls[2][0]).toContain('/attempt/ack');
});
it('recognizes only the explicit terminal execute rejection',async()=>{
  const fetcher=vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({error:{code:'attempt_rejected'}}),{status:422})).mockResolvedValueOnce(new Response(JSON.stringify({error:{code:'invalid_request'}}),{status:422}));
  const client=new ApiClient(fetcher);
  await expect(client.executeBaAttempt(project,clarification,attempt)).rejects.toMatchObject({code:'attempt_rejected'});
  await expect(client.executeBaAttempt(project,clarification,attempt)).rejects.toMatchObject({code:'invalid_request'});
});
it('accepts a replayed claim returned with HTTP 200',async()=>{
  const client=new ApiClient(vi.fn().mockResolvedValue(new Response(JSON.stringify({data:{attempt_id:attempt,state:'claimed',replayed:true}}),{status:200})));
  await expect(client.claimBaAttempt(project,clarification,1,'context')).resolves.toMatchObject({attempt_id:attempt});
});
