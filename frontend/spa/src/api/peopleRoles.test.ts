import {it,expect,vi} from 'vitest';
import {ApiClient} from './client';
const id='11111111-1111-4111-8111-111111111111';
it('uses a dedicated CSRF write and leaves generic project writes closed',async()=>{
 const fetcher=vi.fn().mockImplementation(async()=>new Response(JSON.stringify({data:{id,name:'QA',description:'',status:'active',revision:1}}),{status:201}));const api=new ApiClient(fetcher);api.setCsrf('csrf');
 await expect(api.request(`/browser/v1/projects/${id}/employees`)).resolves.toBeDefined();
 await api.createCatalogRole({name:'QA',description:'',request_id:id});
 expect(fetcher.mock.calls[1][1].headers['X-CSRF-TOKEN']).toBe('csrf');
 for(const path of ['/browser/v1/role-catalog',`/browser/v1/projects/${id}/catalog-role-assignments`])await expect(api.request(path,{method:'POST',body:{}})).rejects.toMatchObject({code:'invalid_request'});
 expect(fetcher).toHaveBeenCalledTimes(2);
});
it('rejects invalid assignment IDs and unsafe writes before fetch',async()=>{const fetcher=vi.fn();const api=new ApiClient(fetcher);await expect(api.assignCatalogRole('../session',{employee_id:id,role_catalog_id:id,expected_role_revision:1,request_id:id})).rejects.toMatchObject({code:'invalid_path'});expect(fetcher).not.toHaveBeenCalled();});
