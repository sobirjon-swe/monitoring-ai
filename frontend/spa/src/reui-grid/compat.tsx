import type { ButtonHTMLAttributes, InputHTMLAttributes, HTMLAttributes } from 'react';

export function cn(...values: (string | false | null | undefined)[]) { return values.filter(Boolean).join(' '); }
export function Button({variant: _variant, size: _size, ...props}: ButtonHTMLAttributes<HTMLButtonElement> & {variant?: string; size?: string}) { return <button {...props}/>; }
export function Checkbox({indeterminate: _indeterminate, onCheckedChange, ...props}: Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> & {indeterminate?: boolean; onCheckedChange?: (value: boolean) => void}) { return <input {...props} type="checkbox" onChange={event => onCheckedChange?.(event.currentTarget.checked)}/>; }
export function Spinner(props: HTMLAttributes<HTMLSpanElement>) { return <span {...props}/>; }
export function IconPlaceholder({lucide: _lucide, tabler: _tabler, hugeicons: _hugeicons, phosphor: _phosphor, remixicon: _remixicon, ...props}: HTMLAttributes<HTMLSpanElement> & {lucide?: string; tabler?: string; hugeicons?: string; phosphor?: string; remixicon?: string}) { return <span {...props}/>; }
