export class ApiError extends Error {
 constructor(public readonly code:string, public readonly status=0, public readonly requestId?:string){super(code);this.name='ApiError';}
}
const uuid='[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const projectPath=new RegExp(`^/browser/v1/projects/${uuid}/(?:capabilities|requirements|lifecycle-tasks|progress|qa/(?:verification|queue|history/(?:[a-z_]+)/${uuid})|ba/(?:queue|history/${uuid})|management/(?:queue|history/${uuid}))$`);
const uuidValue=new RegExp(`^${uuid}$`);
type BaSlot={attempt_id:string;state:'claimed'|'completed'};
export type BaResult={receipt_id:string;clarification_id:string;event_id:string;replayed:boolean};
export class ApiClient {
 private csrf='';
 private sessionEpoch=0;
 onUnauthorized?:()=>void;
 constructor(private readonly fetcher:typeof fetch=(...args)=>fetch(...args)){}
 setCsrf(value:string){this.csrf=value;}
 advanceSessionEpoch(){this.sessionEpoch++;}
 private baPath(project:string,clarification:string){if(!uuidValue.test(project)||!uuidValue.test(clarification))throw new ApiError('invalid_path');return `/browser/v1/projects/${project}/ba/clarifications/${clarification}/attempt`;}
 private slot(value:unknown):BaSlot {const v=value as BaSlot;if(!v || !uuidValue.test(v.attempt_id) || !['claimed','completed'].includes(v.state))throw new ApiError('invalid_response');return v;}
 async claimBaAttempt(project:string,clarification:string,revision:number,context:string):Promise<BaSlot>{
  if(!Number.isSafeInteger(revision)||revision<0||!context.trim()||new TextEncoder().encode(context).length>4000)throw new ApiError('invalid_request');
  return this.slot(await this.send(this.baPath(project,clarification),{method:'POST',body:{clarification_id:clarification,expected_revision:revision,kind:'context.add',context}},true,[200,201]));
 }
 async showBaAttempt(project:string,clarification:string):Promise<BaSlot>{return this.slot(await this.send(this.baPath(project,clarification),{},true));}
 async executeBaAttempt(project:string,clarification:string,attempt:string):Promise<BaResult>{
  if(!uuidValue.test(attempt))throw new ApiError('invalid_request');
  const v=await this.send<Record<string,unknown>>(this.baPath(project,clarification)+'/execute',{method:'POST',body:{attempt_id:attempt}},true,200);
  if(!v || typeof v.receipt_id!=='string' || !uuidValue.test(v.receipt_id) || v.clarification_id!==clarification || typeof v.event_id!=='string' || !uuidValue.test(v.event_id) || typeof v.replayed!=='boolean' || v.synthetic!==true || v.ai_interpretation!=='unavailable' || v.customer_authenticity!=='not_verified')throw new ApiError('invalid_response');
  return v as BaResult;
 }
 async ackBaAttempt(project:string,clarification:string,attempt:string):Promise<void>{if(!uuidValue.test(attempt))throw new ApiError('invalid_request');await this.send(this.baPath(project,clarification)+'/ack',{method:'POST',body:{attempt_id:attempt}},true,204);}
 async request<T>(path:string,options:{method?:string;body?:unknown;signal?:AbortSignal}={}):Promise<T>{return this.send(path,options,false);}
 private async send<T>(path:string,options:{method?:string;body?:unknown;signal?:AbortSignal},coordinator:boolean,expectedStatus?:number|number[]):Promise<T>{
  // Parse only after rejecting separators/encoding that could normalize into a different route.
  if(!path.startsWith('/browser/v1/') || /[\\#\s]/.test(path) || /%(?:2f|5c|2e)/i.test(path.split('?')[0]) || path.split('?')[0].includes('..'))throw new ApiError('invalid_path');
  const [route,query,...extra]=path.split('?');
  if(extra.length || !(coordinator || ['/browser/v1/session','/browser/v1/login','/browser/v1/logout','/browser/v1/projects'].includes(route) || projectPath.test(route)))throw new ApiError('invalid_path');
  const method=options.method??'GET';
  if(!['GET','POST'].includes(method) || (method==='POST' && !coordinator && !['/browser/v1/login','/browser/v1/logout'].includes(route)) || (method==='GET' && ['/browser/v1/login','/browser/v1/logout'].includes(route)) || (method==='GET' && options.body!==undefined) || (query && (coordinator || ['/browser/v1/session','/browser/v1/login','/browser/v1/logout'].includes(route))))throw new ApiError('invalid_request');
  const originatingSessionEpoch=this.sessionEpoch;
  let response:Response;
  try{response=await this.fetcher(path,{method,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json',...(method==='POST'?{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf}:{})},body:options.body===undefined?undefined:JSON.stringify(options.body),signal:options.signal});}
  catch(error){if(error instanceof DOMException && error.name==='AbortError')throw error;throw new ApiError('network');}
  if(response.status===401){
   // Old-session failures still reject their caller, but cannot invalidate a newer session.
   if(originatingSessionEpoch===this.sessionEpoch){this.csrf='';this.onUnauthorized?.();}
   throw new ApiError('unauthenticated',401);
  }
  if(!response.ok){
   let code=({403:'forbidden',404:'not_found',409:'conflict',413:'invalid_request',419:'session_expired',422:'invalid_request',429:'too_many_requests'} as Record<number,string>)[response.status]??'unavailable';
   if(coordinator && route.endsWith('/attempt/execute') && response.status===422){try{const body=await response.json();if(body?.error?.code==='attempt_rejected')code='attempt_rejected';}catch{/* Keep ambiguous responses blocked. */}}
   const id=response.headers.get('X-Request-ID');throw new ApiError(code,response.status,id && /^[0-9a-f-]{36}$/i.test(id)?id:undefined);
  }
  if(expectedStatus!==undefined && !(Array.isArray(expectedStatus)?expectedStatus.includes(response.status):response.status===expectedStatus))throw new ApiError('invalid_response');
  if(response.status===204)return undefined as T;
  try{const envelope=await response.json();if(!envelope || !Object.hasOwn(envelope,'data'))throw new Error();return envelope.data as T;}catch{throw new ApiError('invalid_response');}
 }
}
export const apiClient=new ApiClient();
