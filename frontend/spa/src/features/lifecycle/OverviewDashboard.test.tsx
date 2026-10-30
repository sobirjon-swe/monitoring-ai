import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import type { Progress } from '../../api/readAdapters';
import { OverviewDashboard } from './OverviewDashboard';

vi.mock('../../auth/SessionProvider', () => ({useSession: () => ({project:{id:'project-1',name:'Actual project',revision:1}})}));
const data:Progress = {status:'partial',accepted_weight:18,total_weight:30,percentage_basis_points:6000,baseline_id:'baseline-1',policy_id:'policy-1',coverage_reason_codes:['acceptance_inputs_changed'],as_of:'2025-03-02T01:00:00Z',previous_snapshot:{accepted_weight:12},schedule_coverage:{status:'unknown'}};

it('uses the supplied snapshot month and selected project with an explicitly absent time series', () => {
  render(<OverviewDashboard data={data} locale="en" projectId="project-1"/>);
  expect(screen.getByRole('heading',{name:'March 2025'})).toBeTruthy();
  expect(screen.getByText('Actual project')).toBeTruthy();
  expect(screen.getByText('Time series unavailable')).toBeTruthy();
  expect(screen.getByRole('progressbar').getAttribute('value')).toBe('6000');
  expect(screen.getByText('18 / 30')).toBeTruthy();
  expect(screen.getByText('baseline-1')).toBeTruthy();
  expect(screen.getByText('policy-1')).toBeTruthy();
});

it('uses a neutral localized title without a timestamp and never renders missing progress as zero', () => {
  render(<OverviewDashboard data={{...data,as_of:undefined,status:'unavailable',baseline_id:null,accepted_weight:null,total_weight:null,percentage_basis_points:null,coverage_reason_codes:['baseline_missing']}} locale="uz" projectId="project-1"/>);
  expect(screen.getByRole('heading',{name:'Loyiha holati'})).toBeTruthy();
  expect(screen.getByText('Vaqt qatori mavjud emas')).toBeTruthy();
  expect(screen.queryByRole('progressbar')).toBeNull();
  expect(screen.queryByText('0%')).toBeNull();
});

it('localizes the new overview copy in Russian', () => {
  render(<OverviewDashboard data={data} locale="ru" projectId="project-1"/>);
  expect(screen.getByRole('heading',{name:'Покрытие и ограничения'})).toBeTruthy();
  expect(screen.getByText('Временной ряд недоступен')).toBeTruthy();
});

it('does not confuse an available snapshot with acceptance completion at zero progress', () => {
  render(<OverviewDashboard data={{...data,status:'available',accepted_weight:0,percentage_basis_points:0}} locale="en" projectId="project-1"/>);
  expect(screen.getByText('0%')).toBeTruthy();
  expect(screen.getByRole('columnheader',{name:'Data coverage'})).toBeTruthy();
  expect(screen.queryByText('Acceptance status: Complete')).toBeNull();
});
