export class ApiError extends Error {
 constructor(public readonly code:string, public readonly status=0, public readonly requestId?:string){super(code);this.name='ApiError';}
}
const uuid='[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const projectPath=new RegExp(`^/browser/v1/projects/${uuid}/(?:capabilities|requirements|lifecycle-tasks|progress|qa/(?:verification|queue|history/(?:[a-z_]+)/${uuid})|ba/(?:queue|history/${uuid})|management/(?:queue|history/${uuid}))$`);
export class ApiClient {
 private csrf='';
 private sessionEpoch=0;
 onUnauthorized?:()=>void;
 constructor(private readonly fetcher:typeof fetch=(...args)=>fetch(...args)){}
 setCsrf(value:string){this.csrf=value;}
 advanceSessionEpoch(){this.sessionEpoch++;}
 async request<T>(path:string,options:{method?:string;body?:unknown;signal?:AbortSignal}={}):Promise<T>{
  // Parse only after rejecting separators/encoding that could normalize into a different route.
  if(!path.startsWith('/browser/v1/') || /[\\#\s]/.test(path) || /%(?:2f|5c|2e)/i.test(path.split('?')[0]) || path.split('?')[0].includes('..'))throw new ApiError('invalid_path');
  const [route,query,...extra]=path.split('?');
  if(extra.length || !( ['/browser/v1/session','/browser/v1/login','/browser/v1/logout','/browser/v1/projects'].includes(route) || projectPath.test(route)))throw new ApiError('invalid_path');
  const method=options.method??'GET';
  if(!['GET','POST'].includes(method) || (method==='POST' && !['/browser/v1/login','/browser/v1/logout'].includes(route)) || (method==='GET' && ['/browser/v1/login','/browser/v1/logout'].includes(route)) || (method==='GET' && options.body!==undefined) || (query && ['/browser/v1/session','/browser/v1/login','/browser/v1/logout'].includes(route)))throw new ApiError('invalid_request');
  const originatingSessionEpoch=this.sessionEpoch;
  let response:Response;
  try{response=await this.fetcher(path,{method,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json',...(method==='POST'?{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf}:{})},body:options.body===undefined?undefined:JSON.stringify(options.body),signal:options.signal});}
  catch(error){if(error instanceof DOMException && error.name==='AbortError')throw error;throw new ApiError('network');}
  if(response.status===401){
   // Old-session failures still reject their caller, but cannot invalidate a newer session.
   if(originatingSessionEpoch===this.sessionEpoch){this.csrf='';this.onUnauthorized?.();}
   throw new ApiError('unauthenticated',401);
  }
  if(!response.ok){const code=({403:'forbidden',404:'not_found',409:'conflict',413:'invalid_request',419:'session_expired',422:'invalid_request',429:'too_many_requests'} as Record<number,string>)[response.status]??'unavailable'; const id=response.headers.get('X-Request-ID');throw new ApiError(code,response.status,id && /^[0-9a-f-]{36}$/i.test(id)?id:undefined);}
  if(response.status===204)return undefined as T;
  try{const envelope=await response.json();if(!envelope || !Object.hasOwn(envelope,'data'))throw new Error();return envelope.data as T;}catch{throw new ApiError('invalid_response');}
 }
}
export const apiClient=new ApiClient();
