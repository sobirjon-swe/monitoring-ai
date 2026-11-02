import { useState } from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import { WorkspaceShell, sections, type WorkspaceSection } from '../layout/WorkspaceShell';
import { SessionProvider, useSession } from '../auth/SessionProvider';
import { ApiClient } from '../api/client';

function Selected({onNavigate}:{onNavigate:(section:WorkspaceSection)=>void}) {
  const {selectProject,project}=useSession();
  const [section,setSection]=useState<WorkspaceSection>('overview');
  return <WorkspaceShell locale="ru" setLocale={()=>{}} section={section} visibleSections={sections} onNavigate={next=>{setSection(next);onNavigate(next);}}>
    {project?<h2>{section} content</h2>:<button onClick={()=>selectProject({id:'11111111-1111-4111-8111-111111111111',name:'Synthetic project',revision:1})}>Choose project</button>}
  </WorkspaceShell>;
}
function renderShell(onNavigate=vi.fn()) {
  const api=new ApiClient(vi.fn().mockResolvedValue(new Response(JSON.stringify({data:{authenticated:false,csrf_token:'c'}}))));
  return render(<SessionProvider client={api}><Selected onNavigate={onNavigate}/></SessionProvider>);
}
it('keeps every localized route available in inline navigation and focuses the selected content',async()=>{
  renderShell();
  fireEvent.click(screen.getByRole('button',{name:'Choose project'}));
  const nav=screen.getByRole('navigation');
  expect(nav.querySelectorAll('a')).toHaveLength(8);
  expect(screen.queryByRole('button',{name:'Навигация'})).toBeNull();
  expect(screen.queryByRole('dialog')).toBeNull();
  fireEvent.click(nav.querySelector('a[href="/app/qa"]')!);
  await screen.findByRole('heading',{name:'qa content'});
  await waitFor(()=>expect(document.activeElement).toBe(document.getElementById('main-content')));
  expect(nav.querySelector('[aria-current="page"]')?.getAttribute('href')).toBe('/app/qa');
});
it('preserves native modified link navigation while plain clicks navigate in the workspace',()=>{
  const navigate=vi.fn();renderShell(navigate);
  fireEvent.click(screen.getByRole('button',{name:'Choose project'}));
  const link=document.querySelector('a[href="/app/tasks"]')!;
  const prevented:boolean[]=[];
  const nativeNavigation=(event:MouseEvent)=>{prevented.push(event.defaultPrevented);event.preventDefault();};
  document.addEventListener('click',nativeNavigation);
  fireEvent.click(link,{ctrlKey:true});
  fireEvent.click(link,{metaKey:true});
  fireEvent.click(link,{shiftKey:true});
  document.removeEventListener('click',nativeNavigation);
  expect(prevented).toEqual([false,false,false]);
  expect(navigate).not.toHaveBeenCalled();
  expect(fireEvent.click(link)).toBe(false);
  expect(navigate).toHaveBeenCalledWith('tasks');
});

it('keeps the active end tab visible after the viewport narrows and cleans up resize handling',()=>{
  const view=renderShell();
  fireEvent.click(screen.getByRole('button',{name:'Choose project'}));
  const nav=screen.getByRole('navigation');
  const roles=nav.querySelector<HTMLElement>('a[href="/app/roles"]')!;
  let width=1400;
  Object.defineProperty(nav,'clientWidth',{configurable:true,get:()=>width});
  Object.defineProperty(nav,'offsetLeft',{configurable:true,value:0});
  Object.defineProperty(roles,'offsetLeft',{configurable:true,value:1100});
  Object.defineProperty(roles,'offsetWidth',{configurable:true,value:100});
  fireEvent.click(roles);
  expect(nav.scrollLeft).toBe(0);
  const focused=document.activeElement;
  width=300;
  fireEvent(window,new Event('resize'));
  expect(nav.scrollLeft).toBe(900);
  expect(document.activeElement).toBe(focused);
  view.unmount();
  nav.scrollLeft=0;
  fireEvent(window,new Event('resize'));
  expect(nav.scrollLeft).toBe(0);
});
