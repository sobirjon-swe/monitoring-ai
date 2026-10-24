export type SessionView = { authenticated:false; csrf_token:string } | { authenticated:true; csrf_token:string; user:{id:string;tenant_id:string;employee_id:string|null;display_name:string};expires_at:string };
export type ProjectSummary={id:string;name:string;revision:number};
export type ProjectPage={items:ProjectSummary[];next_cursor:string|null};
export type Capabilities={project_id:string;purposes:string[];observed_at:string};
