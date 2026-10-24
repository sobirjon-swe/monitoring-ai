import { describe, it, expect, vi } from 'vitest';
import { ApiClient, ApiError } from '../api/client';
describe('same origin client', () => {
 it('rejects arbitrary URLs before fetching', async () => { const fetcher = vi.fn(); const api = new ApiClient(fetcher); for (const path of ['https://evil.test','//evil.test','/browser/v1/../logout','/api/v1/projects','/browser/v1/projects/%2e%2e/session']) await expect(api.request(path)).rejects.toBeInstanceOf(ApiError); expect(fetcher).not.toHaveBeenCalled(); });
 it('sends current csrf with credentials and never bearer', async () => { const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({data:{ok:true}}))); const api=new ApiClient(fetcher); api.setCsrf('csrf'); await api.request('/browser/v1/logout',{method:'POST'}); const opts=fetcher.mock.calls[0][1]; expect(opts.credentials).toBe('same-origin'); expect(opts.headers['X-CSRF-TOKEN']).toBe('csrf'); expect(opts.headers.Authorization).toBeUndefined(); });
 it('invalidates on 401 without exposing server text', async () => { const on401=vi.fn(); const api=new ApiClient(vi.fn().mockResolvedValue(new Response(JSON.stringify({error:{message:'sensitive'}}),{status:401}))); api.onUnauthorized=on401; await expect(api.request('/browser/v1/projects')).rejects.toMatchObject({code:'unauthenticated',message:'unauthenticated'}); expect(on401).toHaveBeenCalledOnce(); });
});
