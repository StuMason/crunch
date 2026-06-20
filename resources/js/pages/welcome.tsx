import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="crunch — self-hosted inference API" />
            <div className="relative flex min-h-screen flex-col bg-background text-foreground">
                <header className="flex items-center justify-end gap-4 p-6 text-sm">
                    <a
                        href="/docs/api"
                        className="text-muted-foreground transition-colors hover:text-foreground"
                    >
                        API docs
                    </a>
                    <Link
                        href={auth.user ? dashboard() : login()}
                        className="rounded-md border border-border px-4 py-1.5 transition-colors hover:bg-muted"
                    >
                        {auth.user ? 'Dashboard' : 'Log in'}
                    </Link>
                </header>

                <main className="flex flex-1 flex-col items-center justify-center px-6 pb-24 text-center">
                    <h1 className="font-display text-[clamp(4.5rem,23vw,22rem)] leading-none font-extrabold tracking-tighter select-none">
                        CRUNCH
                    </h1>
                    <p className="mt-6 max-w-xl text-lg text-muted-foreground sm:text-xl">
                        One bite encoder models. Fast, warm and delicious.
                    </p>
                </main>
            </div>
        </>
    );
}
