import { usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { buttonClasses } from '@/components/ui/button';

/** Native links download binary files without treating them as Inertia page responses. */
export function RecordDownloads({ baseUrl }: { baseUrl: string }) {
    const { errors } = usePage().props;
    return <>
        <a href={`${baseUrl}/registration`} className={buttonClasses('secondary')}><Download className="size-4" aria-hidden="true" />Registration PDF</a>
        <a href={`${baseUrl}/academic`} className={buttonClasses('secondary')}><Download className="size-4" aria-hidden="true" />Academic Record PDF</a>
        {errors.pdf && <p role="alert" className="w-full text-sm text-danger-fg">{errors.pdf}</p>}
    </>;
}
