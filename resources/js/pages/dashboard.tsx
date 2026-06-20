import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';

interface Token {
    id: number;
    name: string;
    last_used_at: string | null;
    monthly_limit: number | null;
    rate_limit_per_minute: number;
    used_this_month: number;
    created_at: string | null;
}

interface DashboardProps {
    tokens: Token[];
    newToken: { name: string; plainText: string } | null;
    usage: {
        total_this_month: number;
        avg_duration_ms: number;
        by_endpoint: { endpoint: string; count: number }[];
        daily: { date: string; count: number }[];
    };
    [key: string]: unknown;
}

export default function Dashboard() {
    const { tokens, newToken, usage } = usePage<DashboardProps>().props;
    const [copied, setCopied] = useState(false);

    const form = useForm({ name: '', monthly_limit: '', rate_limit_per_minute: '120' });

    const createToken = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/tokens', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const revoke = (id: number) => {
        if (confirm('Revoke this token? Apps using it will stop working.')) {
            router.delete(`/tokens/${id}`, { preserveScroll: true });
        }
    };

    const copy = (text: string) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                {/* Usage summary */}
                <div className="grid gap-4 md:grid-cols-3">
                    <Stat label="Requests this month" value={usage.total_this_month.toLocaleString()} />
                    <Stat label="Avg latency" value={`${usage.avg_duration_ms} ms`} />
                    <Stat
                        label="Top endpoint"
                        value={usage.by_endpoint[0]?.endpoint ?? '—'}
                    />
                </div>

                {/* One-time new token reveal */}
                {newToken && (
                    <Card className="border-emerald-500/40 bg-emerald-500/5">
                        <CardHeader>
                            <CardTitle className="text-base">
                                New token “{newToken.name}” — copy it now, it won’t be shown again
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 font-mono text-sm">
                                {newToken.plainText}
                            </code>
                            <Button type="button" onClick={() => copy(newToken.plainText)}>
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Create token */}
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle className="text-base">Create API token</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={createToken} className="space-y-4">
                                <div className="space-y-1.5">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                        placeholder="e.g. peptides-prod"
                                        required
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="rate">Rate / min</Label>
                                        <Input
                                            id="rate"
                                            type="number"
                                            value={form.data.rate_limit_per_minute}
                                            onChange={(e) => form.setData('rate_limit_per_minute', e.target.value)}
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="monthly">Monthly cap</Label>
                                        <Input
                                            id="monthly"
                                            type="number"
                                            value={form.data.monthly_limit}
                                            onChange={(e) => form.setData('monthly_limit', e.target.value)}
                                            placeholder="unlimited"
                                        />
                                    </div>
                                </div>
                                <Button type="submit" disabled={form.processing} className="w-full">
                                    Create token
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Tokens table */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="text-base">API tokens</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {tokens.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No tokens yet.</p>
                            ) : (
                                <div className="divide-y divide-border">
                                    {tokens.map((t) => (
                                        <div key={t.id} className="flex items-center justify-between gap-4 py-3">
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{t.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {t.last_used_at ? `Last used ${t.last_used_at}` : 'Never used'} ·
                                                    created {t.created_at}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <Badge variant="secondary">
                                                    {t.used_this_month}
                                                    {t.monthly_limit ? ` / ${t.monthly_limit}` : ''} this mo
                                                </Badge>
                                                <Badge variant="outline">{t.rate_limit_per_minute}/min</Badge>
                                                <Button variant="ghost" size="sm" onClick={() => revoke(t.id)}>
                                                    Revoke
                                                </Button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Usage by endpoint */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Usage by endpoint (this month)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {usage.by_endpoint.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No requests yet this month.</p>
                        ) : (
                            <div className="space-y-2">
                                {usage.by_endpoint.map((row) => (
                                    <div key={row.endpoint} className="flex items-center gap-3 text-sm">
                                        <code className="w-44 shrink-0 text-muted-foreground">{row.endpoint}</code>
                                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full bg-foreground/70"
                                                style={{
                                                    width: `${Math.max(2, (row.count / usage.by_endpoint[0].count) * 100)}%`,
                                                }}
                                            />
                                        </div>
                                        <span className="w-12 text-right tabular-nums">{row.count}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardContent className="pt-6">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="mt-1 truncate text-2xl font-semibold">{value}</p>
            </CardContent>
        </Card>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
