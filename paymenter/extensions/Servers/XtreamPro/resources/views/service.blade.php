<div class="mt-4">
    @if ($error)
        <p class="text-red-500">{{ $error }}</p>
    @else
        <div class="grid md:grid-cols-2 gap-2 mb-4">
            <div>Username: <span class="font-semibold">{{ $username }}</span></div>
            <div>Password: <span class="font-semibold">{{ $password !== '' ? $password : '-' }}</span></div>
            <div>Status: <span class="font-semibold">{{ $status !== '' ? $status : '-' }}</span></div>
            @if ($reseller)
                <div>Credit balance: <span class="font-semibold">{{ $credits !== '' ? $credits : '-' }}</span></div>
            @else
                <div>Expires: <span class="font-semibold">{{ $expiry }}</span></div>
                <div>Max connections: <span class="font-semibold">{{ $maxConnections !== '' ? $maxConnections : '-' }}</span></div>
            @endif
        </div>

        @if (!$reseller)
            @foreach ($links as $label => $url)
                <div class="mb-2">
                    <label class="block text-sm mb-1">{{ $label }}</label>
                    <input type="text" readonly value="{{ $url }}" onfocus="this.select()"
                        class="w-full rounded-md border border-neutral bg-background px-3 py-2 text-sm">
                </div>
            @endforeach
        @endif
    @endif
</div>
