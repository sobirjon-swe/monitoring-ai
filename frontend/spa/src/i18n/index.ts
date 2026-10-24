import en from './en';import uz from './uz';import ru from './ru';
export type Locale='uz'|'ru'|'en';export type TranslationKey=keyof typeof en;
export const dictionaries={uz,ru,en};
export function t(locale:Locale,key:TranslationKey,params?:Record<string,string|number>):string{return dictionaries[locale][key].replace(/\{(\w+)\}/g,(match,key)=>String(params?.[key]??match));}
export function readLocale():Locale{try{const value=localStorage.getItem('monitoring.locale');if(value==='uz'||value==='ru'||value==='en')return value;}catch{}return 'uz';}
export function saveLocale(locale:Locale){try{localStorage.setItem('monitoring.locale',locale);}catch{}}
export function formatDate(locale:Locale,value:string){const date=new Date(value);return Number.isNaN(date.getTime())?'—':new Intl.DateTimeFormat({uz:'uz-UZ',ru:'ru-RU',en:'en-GB'}[locale],{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',timeZone:'Asia/Tashkent'}).format(date);}
export function formatNumber(locale:Locale,value:number){return new Intl.NumberFormat({uz:'uz-UZ',ru:'ru-RU',en:'en-GB'}[locale]).format(value);}
