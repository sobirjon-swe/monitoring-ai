import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import { useState } from 'react';
import { ApiClient } from '../api/client';
import { SessionProvider, useSession } from '../auth/SessionProvider';
import { WorkspaceShell, type WorkspaceSection } from '../layout/WorkspaceShell';
import { ProjectPicker } from '../projects/ProjectPicker';

function Projects() {
  const {project}=useSession();
  return <WorkspaceShell locale="en" setLocale={()=>{}}>{project?<h1>Selected project workspace</h1>:<ProjectPicker locale="en"/>}</WorkspaceShell>;
}
it('moves focus to replacement main content after keyboard project selection and change',async()=>{
  const user=userEvent.setup();
  const client=new ApiClient(async input=>new Response(JSON.stringify({data:String(input).endsWith('/session')?
    {authenticated:true,csrf_token:'c',user:{id:'user',tenant_id:'tenant',employee_id:null,display_name:'Synthetic'},expires_at:'2026-10-25T00:00:00Z'}:
    {items:[{id:'11111111-1111-4111-8111-111111111111',name:'Synthetic project',revision:1}],next_cursor:null}})));
  render(<SessionProvider client={client}><Projects/></SessionProvider>);
  const open=await screen.findByRole('button',{name:'Open project: Synthetic project'});
  open.focus();
  await user.keyboard('{Enter}');
  await screen.findByRole('heading',{name:'Selected project workspace'});
  expect(document.activeElement).toBe(document.getElementById('main-content'));
  screen.getByRole('button',{name:'Change project'}).focus();
  await user.keyboard('{Enter}');
  await screen.findByRole('heading',{name:'Permitted projects'});
  await waitFor(()=>expect(document.activeElement).toBe(document.getElementById('main-content')));
});
function NavigableProject() {
  const {project,selectProject}=useSession();
  const [section,setSection]=useState<WorkspaceSection>('overview');
  return <WorkspaceShell locale="en" setLocale={()=>{}} section={section} onNavigate={setSection}>
    {project?<h1>{section} content</h1>:<button onClick={()=>selectProject({id:'11111111-1111-4111-8111-111111111111',name:'Synthetic project',revision:1})}>Choose project</button>}
  </WorkspaceShell>;
}
it('focuses replacement main after keyboard drawer navigation removes inert',async()=>{
  const user=userEvent.setup();
  const attempts:{inert:boolean;drawerOpen:boolean}[]=[];
  const nativeFocus=HTMLElement.prototype.focus;
  // jsdom does not enforce browser inert focus behavior. Model that rejection
  // explicitly and record the committed DOM at each main focus attempt.
  const focus=vi.spyOn(HTMLElement.prototype,'focus').mockImplementation(function(this:HTMLElement,options?:FocusOptions){
    const inert=this.closest('[inert]')!==null;
    if(this.id==='main-content') attempts.push({inert,drawerOpen:document.querySelector('[role="dialog"]')!==null});
    if(!inert) nativeFocus.call(this,options);
  });
  try {
    const client=new ApiClient(async()=>new Response(JSON.stringify({data:{authenticated:false,csrf_token:'c'}})));
    render(<SessionProvider client={client}><NavigableProject/></SessionProvider>);
    await user.click(screen.getByRole('button',{name:'Choose project'}));
    await user.click(screen.getByRole('button',{name:'Navigation'}));
    expect(document.getElementById('main-content')?.closest('[inert]')).not.toBeNull();
    const qa=screen.getByRole('link',{name:'QA verification'});
    qa.focus();
    attempts.length=0;
    await user.keyboard('{Enter}');
    await screen.findByRole('heading',{name:'qa content'});
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.getElementById('main-content')?.closest('[inert]')).toBeNull();
    expect(attempts).toEqual([{inert:false,drawerOpen:false}]);
    expect(document.activeElement).toBe(document.getElementById('main-content'));
  } finally {focus.mockRestore();}
});
