import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { ApiClient } from '../api/client';
import { RecordHistory } from './RecordHistory';

const setup=vi.hoisted(()=>({context:{} as Record<string,unknown>}));
vi.mock('../auth/SessionProvider',()=>({useSession:()=>setup.context}));
const project='11111111-1111-4111-8111-111111111111';
const second='22222222-2222-4222-8222-222222222222';
function page(items:unknown[],cursor:string|null=null) {return {items,next_cursor:cursor,coverage_status:'complete',visible_record_count:items.length,reason_codes:[],synthetic:true,ai_interpretation:'unavailable'};}
function wire(value:unknown) {return new Response(JSON.stringify({data:value}));}
const props={projectId:project,scopeGeneration:1,locale:'en' as const,onClose:()=>{},onDenied:()=>{}};
it('cancels changed locators and discards an out-of-order old history response',async()=>{
  const pending:{resolve:(value:Response)=>void;signal:AbortSignal|undefined;path:string}[]=[];
  setup.context={isCurrent:()=>true,client:new ApiClient((input,init)=>new Promise(resolve=>pending.push({resolve,signal:init?.signal as AbortSignal,path:String(input)})))};
  const view=render(<RecordHistory {...props} locator={{domain:'qa',kind:'handoff',id:project}}/>);
  expect(screen.getByRole('heading',{name:'History'})).toBe(document.activeElement);
  view.rerender(<RecordHistory {...props} locator={{domain:'qa',kind:'defect',id:second}}/>);
  expect(pending[0].signal?.aborted).toBe(true);
  expect(pending[1].path).toContain(`/qa/history/defect/${second}?limit=50`);
  await act(async()=>{pending[1].resolve(wire(page([{id:second,revision:2,state:'open'}])));});
  expect(await screen.findByText('2')).toBeTruthy();
  await act(async()=>{pending[0].resolve(wire(page([{id:project,revision:99,state:'completed'}])));});
  expect(screen.queryByText('99')).toBeNull();
  expect(screen.queryByText('Completed')).toBeNull();
});
it('passes an opaque QA history continuation and hides old content when its request fails',async()=>{
  const paths:string[]=[];
  const denied=vi.fn();
  setup.context={isCurrent:()=>true,client:new ApiClient(async input=>{
    paths.push(String(input));
    return paths.length===1?wire(page([{id:project,revision:7,state:'open'}],'opaque+cursor=')):new Response('{}',{status:403});
  })};
  render(<RecordHistory {...props} onDenied={denied} locator={{domain:'qa',kind:'handoff',id:project}}/>);
  expect(await screen.findByText('7')).toBeTruthy();
  fireEvent.click(screen.getByRole('button',{name:'Load more'}));
  expect(screen.queryByText('7')).toBeNull();
  await screen.findByText('You do not have permission to view this information.');
  expect(paths[1]).toContain('limit=50&cursor=opaque%2Bcursor%3D');
  await waitFor(()=>expect(denied).toHaveBeenCalledOnce());
});
for(const domain of ['ba','management'] as const) {
  it(`uses ${domain} exact history route and twenty-row default`,async()=>{
    const paths:string[]=[];
    setup.context={isCurrent:()=>true,client:new ApiClient(async input=>{paths.push(String(input));return wire({...page([]),reasons:[]});})};
    render(<RecordHistory {...props} locator={{domain,id:project}}/>);
    await screen.findByText('No records in this authorized page.');
    expect(paths).toEqual([`/browser/v1/projects/${project}/${domain}/history/${project}?limit=20`]);
  });
}
it('supports Escape for keyboard history close',async()=>{
  const close=vi.fn();
  setup.context={isCurrent:()=>true,client:new ApiClient(async()=>wire(page([])))};
  render(<RecordHistory {...props} onClose={close} locator={{domain:'qa',kind:'handoff',id:project}}/>);
  fireEvent.keyDown(screen.getByRole('heading',{name:'History'}),{key:'Escape'});
  expect(close).toHaveBeenCalledOnce();
  await screen.findByText('No records in this authorized page.');
});
for (const [locale, human, source] of [['uz','Kontekst','Kontekst (manba)'],['ru','Контекст','Контекст (источник)'],['en','Context','Context (source)']] as const) {
  it(`labels only human BA context events without source attribution in ${locale}`,async()=>{
    setup.context={isCurrent:()=>true,client:new ApiClient(async()=>wire(page([
      {clarification_id:project,id:project,revision:1,kind:'context.add',context:'Human note'},
      {clarification_id:project,id:second,revision:2,kind:'request.revise',context:'Source note'},
    ])))};
    render(<RecordHistory {...props} locale={locale} locator={{domain:'ba',id:project}}/>);
    const humanValue=await screen.findByText('Human note');
    expect(humanValue.closest('div')?.querySelector('dt')?.textContent).toBe(human);
    expect(screen.getByText('Source note').closest('div')?.querySelector('dt')?.textContent).toBe(source);
  });
}
it('keeps management context labeled as source context',async()=>{
  setup.context={isCurrent:()=>true,client:new ApiClient(async()=>wire({...page([{action_id:project,event_id:project,revision:1,operation:'context.add',state:'open',support_status:'pending',review_status:'pending',underlying_dependency_status:[],reason_codes:[],current_reason_codes:[],reference_results:[],context:'Management note'}]),reasons:[]}))};
  render(<RecordHistory {...props} locator={{domain:'management',id:project}}/>);
  expect((await screen.findByText('Management note')).closest('div')?.querySelector('dt')?.textContent).toBe('Context (source)');
});
