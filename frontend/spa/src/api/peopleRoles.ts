import {invalid,object,strings} from './readAdapters';
export type CatalogRole={id:string;name:string;description:string;status:'active'|'archived';revision:number};
export type Assignment={id:string;role_catalog_id:string;name:string;revision:number;valid_from:string;valid_to:string|null};
export type Employee={id:string;display_name:string;membership:{id:string;valid_from:string;valid_to:string|null};roles:Assignment[];system_roles:string[]};
export type PeoplePage<T>={items:T[];next_cursor:string|null;visible_record_count:number|null};
export type CreateRole={name:string;description:string;request_id:string};
export type AssignRole={employee_id:string;role_catalog_id:string;expected_role_revision:number;request_id:string;valid_from?:string;valid_to?:string|null};
const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const isId=(v:unknown):v is string=>typeof v==='string'&&uuid.test(v);
const date=(v:unknown)=>typeof v==='string'&&Number.isFinite(Date.parse(v));
export function parseRole(value:unknown):CatalogRole{const v=object(value);if(!isId(v.id)||typeof v.name!=='string'||typeof v.description!=='string'||!['active','archived'].includes(String(v.status))||!Number.isSafeInteger(v.revision)||Number(v.revision)<0)invalid();return v as unknown as CatalogRole;}
export function parseAssignment(value:unknown):Assignment{const v=object(value);if(!isId(v.id)||!isId(v.role_catalog_id)||typeof v.name!=='string'||!Number.isSafeInteger(v.revision)||!date(v.valid_from)||(v.valid_to!==null&&!date(v.valid_to)))invalid();return v as unknown as Assignment;}
export function parseEmployee(value:unknown):Employee{const v=object(value),m=object(v.membership);if(!isId(v.id)||typeof v.display_name!=='string'||!isId(m.id)||!date(m.valid_from)||(m.valid_to!==null&&!date(m.valid_to))||!Array.isArray(v.roles))invalid();v.roles.forEach(parseAssignment);strings(v.system_roles);return v as unknown as Employee;}
export function parsePeoplePage<T>(value:unknown,parse:(v:unknown)=>T):PeoplePage<T>{const v=object(value);if(!Array.isArray(v.items)||(v.next_cursor!==null&&typeof v.next_cursor!=='string')||(v.visible_record_count!==null&&(!Number.isSafeInteger(v.visible_record_count)||Number(v.visible_record_count)<0)))invalid();return {items:v.items.map(parse),next_cursor:v.next_cursor as string|null,visible_record_count:v.visible_record_count as number|null};}
