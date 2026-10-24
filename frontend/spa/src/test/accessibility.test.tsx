import {render,screen,act} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {it,expect,vi} from 'vitest';
import {SessionProvider} from '../auth/SessionProvider';
import {LoginPage} from '../auth/LoginPage';
import {ApiClient} from '../api/client';
import {dictionaries,formatDate,formatNumber,readLocale,saveLocale} from '../i18n';
it('allows keyboard paste, reveals only on request and clears on invalid login',async()=>{
 const user=userEvent.setup();const fetcher=vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({data:{authenticated:false,csrf_token:'initial'}}))).mockResolvedValueOnce(new Response('{}',{status:401})).mockResolvedValueOnce(new Response(JSON.stringify({data:{authenticated:false,csrf_token:'new'}})));
 render(<SessionProvider client={new ApiClient(fetcher)}><LoginPage locale="en"/></SessionProvider>);
 const input=await screen.findByLabelText('Development token') as HTMLInputElement;
 await act(async()=>{await Promise.resolve();});await user.click(input);await user.paste('synthetic-token');expect(input.value).toBe('synthetic-token');expect(input.type).toBe('password');await user.click(screen.getByRole('button',{name:'Show'}));expect(input.type).toBe('text');await user.keyboard('{Tab}{Enter}');await screen.findByText('Token is invalid or expired.');expect(input.value).toBe('');expect(input.type).toBe('password');expect(fetcher.mock.calls[1][0]).toBe('/browser/v1/login');expect(JSON.parse(fetcher.mock.calls[1][1].body)).toEqual({token:'synthetic-token'});expect(Object.keys(localStorage)).toEqual([]);expect(Object.keys(sessionStorage)).toEqual([]);
});
it('keeps dictionaries complete and persists only the locale preference',()=>{const keys=Object.keys(dictionaries.en).sort();for(const dictionary of Object.values(dictionaries)){expect(Object.keys(dictionary).sort()).toEqual(keys);expect(Object.values(dictionary).every(value=>value.length>0)).toBe(true);}saveLocale('ru');expect(readLocale()).toBe('ru');expect(Object.keys(localStorage)).toEqual(['monitoring.locale']);expect(formatDate('en','2026-10-24T00:00:00Z')).toContain('24');expect(formatNumber('en',1234)).toBe('1,234');});
