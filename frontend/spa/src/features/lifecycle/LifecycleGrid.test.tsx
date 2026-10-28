import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { LifecycleGrid } from './LifecycleGrid';

it('renders filtered mobile cards with the same source details',()=>{
  vi.stubGlobal('matchMedia', () => ({matches:true,addEventListener:()=>{},removeEventListener:()=>{}}));
  const data=[
    {id:'row-a',title:'First item',status:'blocked',version_id:'v1',revision:1,assignee_id:null,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],content_hash:'one',effective_at:'2026-10-28'},
    {id:'row-b',title:'Second item',status:'active',version_id:'v2',revision:1,assignee_id:null,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],content_hash:'two',effective_at:'2026-10-28'},
  ];
  const {container}=render(<LifecycleGrid data={data} view="tasks" locale="en"/>);
  fireEvent.change(screen.getByRole('searchbox',{name:'Search this page'}),{target:{value:'First'}});
  const cards=container.querySelector('.lifecycle-mobile-cards');
  expect(cards).not.toBeNull();
  expect(within(cards as HTMLElement).getByText('First item')).toBeTruthy();
  expect(within(cards as HTMLElement).queryByText('Second item')).toBeNull();
  expect(within(cards as HTMLElement).getByText('one')).toBeTruthy();
  vi.unstubAllGlobals();
});

it('switches between grid and cards without rendering both representations',()=>{
  let matches=false;
  let listener:()=>void=()=>{};
  vi.stubGlobal('matchMedia',()=>({matches,addEventListener:(_event:string,cb:()=>void)=>{listener=cb},removeEventListener:()=>{}}));
  const data=[{id:'row-a',title:'First item',status:'blocked',version_id:'v1',revision:1,assignee_id:null,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],content_hash:'one',effective_at:'2026-10-28'}];
  const {container}=render(<LifecycleGrid data={data} view="tasks" locale="en"/>);
  expect(container.querySelector('table')).not.toBeNull();
  expect(container.querySelector('.lifecycle-mobile-cards')).toBeNull();
  act(()=>{matches=true;listener();});
  expect(container.querySelector('table')).toBeNull();
  expect(container.querySelector('.lifecycle-mobile-cards')).not.toBeNull();
  expect(screen.getAllByText('First item')).toHaveLength(1);
  vi.unstubAllGlobals();
});

it('keeps an open record and keyboard focus when switching layouts',()=>{
  let matches=false;
  let listener:()=>void=()=>{};
  vi.stubGlobal('matchMedia',()=>({matches,addEventListener:(_event:string,cb:()=>void)=>{listener=cb},removeEventListener:()=>{}}));
  const data=[{id:'row-a',title:'First item',status:'blocked',version_id:'v1',revision:1,assignee_id:null,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],content_hash:'one',effective_at:'2026-10-28'}];
  const {container}=render(<LifecycleGrid data={data} view="tasks" locale="en"/>);
  const summary=screen.getByText('Version and evidence details',{selector:'summary'});
  summary.focus();
  fireEvent.click(summary);
  expect(summary.parentElement?.hasAttribute('open')).toBe(true);
  act(()=>{matches=true;listener();});
  const mobileSummary=within(container.querySelector('.lifecycle-mobile-cards') as HTMLElement).getByText('Version and evidence details',{selector:'summary'});
  expect(mobileSummary.parentElement?.hasAttribute('open')).toBe(true);
  expect(document.activeElement).toBe(mobileSummary);
  act(()=>{matches=false;listener();});
  const desktopSummary=screen.getByText('Version and evidence details',{selector:'summary'});
  expect(desktopSummary.parentElement?.hasAttribute('open')).toBe(true);
  expect(document.activeElement).toBe(desktopSummary);
  vi.unstubAllGlobals();
});

it('keeps nested technical details open and restores their focus across layouts',()=>{
  let matches=false;
  let listener:()=>void=()=>{};
  vi.stubGlobal('matchMedia',()=>({matches,addEventListener:(_event:string,cb:()=>void)=>{listener=cb},removeEventListener:()=>{}}));
  const data=[{id:'row-a',title:'First item',status:'blocked',version_id:'v1',revision:1,assignee_id:null,estimate_basis:'planned',remaining_effort:null,expected_test_keys:[],content_hash:'one',effective_at:'2026-10-28'}];
  render(<LifecycleGrid data={data} view="tasks" locale="en"/>);
  fireEvent.click(screen.getByText('Version and evidence details',{selector:'summary'}));
  const nested=screen.getByText('Technical and version details',{selector:'summary'});
  fireEvent.click(nested);
  nested.focus();
  act(()=>{matches=true;listener();});
  const restored=screen.getByText('Technical and version details',{selector:'summary'});
  expect(restored.parentElement?.hasAttribute('open')).toBe(true);
  expect(document.activeElement).toBe(restored);
  act(()=>{matches=false;listener();});
  const desktop=screen.getByText('Technical and version details',{selector:'summary'});
  expect(desktop.parentElement?.hasAttribute('open')).toBe(true);
  expect(document.activeElement).toBe(desktop);
  vi.unstubAllGlobals();
});
it('moves focus from the disappearing table region to the page search',()=>{
  let matches=false;
  let listener:()=>void=()=>{};
  vi.stubGlobal('matchMedia',()=>({matches,addEventListener:(_event:string,cb:()=>void)=>{listener=cb},removeEventListener:()=>{}}));
  render(<LifecycleGrid data={[]} view="tasks" locale="en"/>);
  screen.getByRole('region',{name:'Tasks and evidence'}).focus();
  act(()=>{matches=true;listener();});
  expect(document.activeElement).toBe(screen.getByRole('searchbox',{name:'Search this page'}));
  vi.unstubAllGlobals();
});
