<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Whatspend') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body { font-family: 'Inter', sans-serif; }
            .font-voice { font-family: 'Fraunces', serif; }
            .font-num { font-family: 'IBM Plex Mono', monospace; }
        </style>
    </head>
    <body class="antialiased bg-[#15120E] text-[#EDE6D6]">

        <!-- Nav -->
        <nav class="border-b border-[#332C1F]">
            <div class="max-w-5xl mx-auto px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-voice text-xl text-[#C9A227]">₹</span>
                    <span class="font-voice font-medium text-[#EDE6D6] tracking-tight">Whatspend</span>
                </div>
                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('transactions.index') }}" class="rounded-md bg-[#C9A227] text-[#15120E] text-sm font-medium px-4 py-2 hover:bg-[#dab438] transition">
                            Open Ledger
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-[#B9AF98] hover:text-[#EDE6D6] transition">Log in</a>
                        <a href="{{ route('register') }}" class="rounded-md border border-[#C9A227] text-[#C9A227] text-sm font-medium px-4 py-2 hover:bg-[#C9A227] hover:text-[#15120E] transition">
                            Register
                        </a>
                    @endauth
                </div>
            </div>
        </nav>

        <!-- Hero -->
        <div class="max-w-3xl mx-auto px-6 pt-24 pb-20 text-center">
            <div class="text-xs tracking-widest uppercase text-[#8C7018] mb-5">Personal finance, texted in</div>
            <h1 class="font-voice text-4xl sm:text-5xl font-medium text-[#EDE6D6] leading-tight">
                Log an expense the way you already talk about money —
                <span class="text-[#C9A227]">on WhatsApp.</span>
            </h1>
            <p class="mt-6 text-[#B9AF98] text-lg leading-relaxed">
                Text "420 for groceries" and it's in your ledger. No app to open, no form to fill.
                Whatspend parses what you send, keeps a running dashboard, and sends you a daily
                and weekly summary — automatically.
            </p>
            <div class="mt-10 flex items-center justify-center gap-4">
                @auth
                    <a href="{{ route('transactions.index') }}" class="rounded-md bg-[#C9A227] text-[#15120E] font-medium px-6 py-3 hover:bg-[#dab438] transition">
                        Open your ledger
                    </a>
                @else
                    <a href="{{ route('register') }}" class="rounded-md bg-[#C9A227] text-[#15120E] font-medium px-6 py-3 hover:bg-[#dab438] transition">
                        Get started
                    </a>
                    <a href="{{ route('login') }}" class="rounded-md border border-[#332C1F] text-[#B9AF98] font-medium px-6 py-3 hover:border-[#C9A227] hover:text-[#EDE6D6] transition">
                        I already have an account
                    </a>
                @endauth
            </div>
        </div>

        <!-- Feature strip -->
        <div class="border-t border-[#332C1F]">
            <div class="max-w-5xl mx-auto px-6 py-16 grid grid-cols-1 sm:grid-cols-3 gap-8">
                <div>
                    <div class="font-num text-[#C9A227] text-sm mb-2">01</div>
                    <h3 class="font-voice text-lg text-[#EDE6D6] mb-2">Text it in</h3>
                    <p class="text-sm text-[#B9AF98] leading-relaxed">
                        Send a message like you would to a friend. Whatspend figures out the amount,
                        category, and type — no rigid syntax to remember.
                    </p>
                </div>
                <div>
                    <div class="font-num text-[#C9A227] text-sm mb-2">02</div>
                    <h3 class="font-voice text-lg text-[#EDE6D6] mb-2">Watch the ledger update</h3>
                    <p class="text-sm text-[#B9AF98] leading-relaxed">
                        A dashboard with real charts — category breakdown, monthly trend — built off
                        your actual transactions, not a spreadsheet you forgot to update.
                    </p>
                </div>
                <div>
                    <div class="font-num text-[#C9A227] text-sm mb-2">03</div>
                    <h3 class="font-voice text-lg text-[#EDE6D6] mb-2">Get the recap, unprompted</h3>
                    <p class="text-sm text-[#B9AF98] leading-relaxed">
                        A daily and weekly summary lands in the same chat, so you always know where
                        you stand without opening anything.
                    </p>
                </div>
            </div>
        </div>

        <footer class="border-t border-[#332C1F]">
            <div class="max-w-5xl mx-auto px-6 py-8 text-xs text-[#6b6355] text-center">
                Whatspend — a personal ledger, not a bank. Your data stays yours.
            </div>
        </footer>

    </body>
</html>