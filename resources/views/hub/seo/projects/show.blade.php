@extends('hub.layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Route;

    // Accepteer zowel $seoProject als $project (voorkomt "Undefined variable" errors)
    $project = $seoProject ?? ($project ?? null);

    if (! $project) {
        echo '<div class="p-8 bg-white border border-gray-200 rounded-4xl text-red-700">Project niet gevonden.</div>';
        return;
    }

    $domain      = (string) ($project->domain ?? 'Onbekend domein');
    $company     = $project->company ?? null;
    $companyName = $company?->name ?? 'Onbekend bedrijf';

    // Routes (allemaal optioneel via Route::has)
    $rIndex   = Route::has('support.seo.projects.index') ? route('support.seo.projects.index') : url()->previous();
    $rEdit    = Route::has('support.seo.projects.edit') ? route('support.seo.projects.edit', $project) : null;

    $rConnect = Route::has('support.seo.projects.seranking.connect') ? route('support.seo.projects.seranking.connect', $project) : null;
    $rSync    = Route::has('support.seo.projects.seranking.sync') ? route('support.seo.projects.seranking.sync', $project) : null;
    $rRecheck = Route::has('support.seo.projects.seranking.recheck') ? route('support.seo.projects.seranking.recheck', $project) : null;
    $rAddKw   = Route::has('support.seo.projects.seranking.keywords.add') ? route('support.seo.projects.seranking.keywords.add', $project) : null;

    $rMcpChat  = Route::has('support.seo.projects.mcp.chat') ? route('support.seo.projects.mcp.chat', $project) : null;
    $rMcpClear = Route::has('support.seo.projects.mcp.clear') ? route('support.seo.projects.mcp.clear', $project) : null;

    // Data uit controller (kan ontbreken)
    $stat  = $serankingStat ?? null;
    $rows  = is_array($serankingKeywordRows ?? null) ? $serankingKeywordRows : [];
    $sites = is_array($serankingSites ?? null) ? $serankingSites : [];

    // Koppeling
    $siteId = isset($serankingSiteId) ? (int) $serankingSiteId : (int) ($project->seranking_project_id ?? 0);
    $isConnected = $siteId > 0;

    // BELANGRIJK: alleen "zoekmachine ok" als we het echt hebben gecontroleerd.
    // Als controller deze variabelen niet meegeeft, is het "nog niet gecontroleerd".
    $engineChecked = isset($serankingNeedsSearchEngine) || isset($serankingSiteEngineId);

    $serankingNeedsSearchEngine = $engineChecked ? (bool) ($serankingNeedsSearchEngine ?? false) : null;
    $siteEngineId               = $engineChecked ? (int) ($serankingSiteEngineId ?? 0) : 0;

    // Connected site info (guest link + keyword_count) alleen als sites geladen zijn
    $connectedSite = null;
    if ($isConnected && count($sites) > 0) {
        foreach ($sites as $s) {
            if ((int) ($s['id'] ?? 0) === $siteId) { $connectedSite = $s; break; }
        }
    }
    $guestLink    = $connectedSite['guest_link'] ?? null;
    $keywordCount = (int) ($connectedSite['keyword_count'] ?? 0);

    // Alleen "keywords aanwezig" als we ofwel rows hebben (posities) ofwel sites hebben opgehaald (keyword_count)
    $keywordsChecked = count($rows) > 0 || $connectedSite !== null;
    $positionsChecked = count($rows) > 0 || $stat !== null;

    // KPI’s uit stat (als aanwezig)
    $visibilityPercent = data_get($stat, 'visibility_percent'); // kan null zijn
    $avgPosToday       = data_get($stat, 'today_avg');
    $top10Stat         = data_get($stat, 'top10');
    $top30Stat         = data_get($stat, 'top30');
    $upStat            = data_get($stat, 'total_up');
    $downStat          = data_get($stat, 'total_down');

    // KPI’s uit rows
    $tracked = count($rows) > 0 ? count($rows) : ($keywordsChecked ? $keywordCount : 0);

    $top3  = 0; $top10 = 0; $top30 = 0; $top100 = 0;
    $up = 0; $down = 0;
    $sumVolume = 0;
    $sumPotentialTraffic = 0.0;

    $ctr = function (int $pos): float {
        if ($pos <= 0) return 0.0;
        if ($pos === 1) return 0.28;
        if ($pos === 2) return 0.15;
        if ($pos === 3) return 0.10;
        if ($pos >= 4 && $pos <= 10) return 0.05;
        if ($pos >= 11 && $pos <= 20) return 0.015;
        if ($pos >= 21 && $pos <= 30) return 0.01;
        return 0.003;
    };

    foreach ($rows as $r) {
        $pos = (int) ($r['pos'] ?? 0);
        $chg = (int) ($r['change'] ?? 0);
        $vol = (int) ($r['volume'] ?? 0);

        if ($pos > 0) $top100++;
        if ($pos > 0 && $pos <= 30) $top30++;
        if ($pos > 0 && $pos <= 10) $top10++;
        if ($pos > 0 && $pos <= 3)  $top3++;

        if ($chg > 0) $up++;
        if ($chg < 0) $down++;

        $sumVolume += max(0, $vol);
        $sumPotentialTraffic += max(0, $vol) * $ctr($pos);
    }

    if ($top10Stat !== null) $top10 = (int) $top10Stat;
    if ($top30Stat !== null) $top30 = (int) $top30Stat;
    if ($upStat !== null) $up = (int) $upStat;
    if ($downStat !== null) $down = (int) $downStat;

    $avgPosDisplay = $avgPosToday !== null
        ? number_format((float) $avgPosToday, 1, ',', '.')
        : (count($rows) ? number_format(collect($rows)->pluck('pos')->filter(fn($p)=> (int)$p>0)->avg() ?? 0, 1, ',', '.') : null);

    $visibilityDisplay = $visibilityPercent !== null
        ? number_format((float) $visibilityPercent, 1, ',', '.') . '%'
        : (count($rows) ? number_format(min(100, ($top10 / max(1, $tracked)) * 100), 1, ',', '.') . '%' : 'Geen data');

    $potentialTrafficDisplay = number_format((float) $sumPotentialTraffic, 0, ',', '.');

    // Kansen: volume hoog, positie 0 of > 10
    $opps = collect($rows)
        ->filter(function ($r) {
            $pos = (int) ($r['pos'] ?? 0);
            $vol = (int) ($r['volume'] ?? 0);
            return $vol > 0 && ($pos === 0 || $pos > 10);
        })
        ->sortByDesc(fn ($r) => (int) ($r['volume'] ?? 0))
        ->take(8)
        ->values()
        ->all();

    // MCP thread uit session (per project)
    $mcpThreadKey = 'seo_mcp_thread_' . $project->id;
    $mcpThread = session($mcpThreadKey, []);
    $mcpThread = is_array($mcpThread) ? $mcpThread : [];
    $mcpError = session('mcp_error');

    // Traject status (evidence-based)
    $stepConnectOk = $isConnected;

    // Zoekmachine is alleen "ok" als we hem gecontroleerd hebben
    $stepEngineOk = $isConnected
        && $engineChecked
        && ($siteEngineId > 0)
        && ($serankingNeedsSearchEngine === false);

    // Keywords alleen "ok" als we het opgehaald/gecheckt hebben
    $stepKwOk = $stepEngineOk && $keywordsChecked && ($tracked > 0);

    // Nulmeting alleen "ok" als posities echt opgehaald zijn
    $stepMeasureOk = $stepKwOk && $positionsChecked && (count($rows) > 0);

    // Kansen alleen "ok" als er kansen zijn berekend op echte rows
    $stepOppsOk = $stepMeasureOk && (count($opps) > 0);

    // UI
    $card = 'bg-white border border-gray-200 rounded-4xl';
    $soft = 'bg-[#f3f8f8] border border-[#d7ecec] rounded-4xl';

    $btnPrimary = 'px-5 py-3 rounded-full text-xs font-semibold text-white bg-[#0F9B9F] hover:bg-[#215558] transition disabled:opacity-50 disabled:cursor-not-allowed';
    $btnDark    = 'px-5 py-3 rounded-full text-xs font-semibold text-white bg-[#215558] hover:bg-[#0F9B9F] transition disabled:opacity-50 disabled:cursor-not-allowed';
    $btnGhost   = 'px-5 py-3 rounded-full text-xs font-semibold border border-gray-200 text-[#215558] bg-white hover:bg-gray-100 transition disabled:opacity-50 disabled:cursor-not-allowed';

    $pillBase   = 'inline-flex items-center gap-2 px-3 py-1 rounded-full text-[11px] border';

    $inputClass  = 'w-full rounded-2xl border border-gray-300 bg-white px-4 py-3 text-sm text-[#215558] placeholder-gray-400 shadow-sm outline-none focus:border-[#0F9B9F] focus:ring-4 focus:ring-[#0F9B9F]/15 transition';

    $stepUi = function (string $title, string $status, string $sub) {
        // status: done | todo | warn | blocked
        if ($status === 'done') {
            return ['bg-emerald-50 border-emerald-200', 'bg-emerald-500', 'text-emerald-700', $title, $sub];
        }
        if ($status === 'warn') {
            return ['bg-amber-50 border-amber-200', 'bg-amber-500', 'text-amber-900', $title, $sub];
        }
        if ($status === 'blocked') {
            return ['bg-white border-gray-200 opacity-70', 'bg-gray-300', 'text-gray-600', $title, $sub];
        }
        return ['bg-white border-gray-200', 'bg-gray-300', 'text-[#215558]', $title, $sub];
    };

    // Bepaal labels per stap
    $s1 = $stepConnectOk ? ['done','Klaar'] : ['todo','Nog te doen'];

    $s2 = ['blocked','Wacht op koppeling'];
    if ($isConnected) {
        if (! $engineChecked) $s2 = ['todo','Nog niet gecontroleerd'];
        elseif ($serankingNeedsSearchEngine) $s2 = ['warn','Actie nodig'];
        elseif ($stepEngineOk) $s2 = ['done','Klaar'];
        else $s2 = ['todo','Nog te doen'];
    }

    $s3 = ['blocked','Wacht op zoekmachine'];
    if ($stepEngineOk) {
        if (! $keywordsChecked) $s3 = ['todo','Nog niet opgehaald'];
        elseif ($tracked > 0) $s3 = ['done','Klaar'];
        else $s3 = ['todo','Nog te doen'];
    }

    $s4 = ['blocked','Wacht op keywords'];
    if ($stepKwOk) {
        if (! $positionsChecked) $s4 = ['todo','Nog niet gemeten'];
        elseif (count($rows) > 0) $s4 = ['done','Klaar'];
        else $s4 = ['todo','Nog te doen'];
    }

    $s5 = ['blocked','Wacht op nulmeting'];
    if ($stepMeasureOk) {
        $s5 = count($opps) > 0 ? ['done','Klaar'] : ['todo','Nog te doen'];
    }

    // IDs voor losse forms (fix nested forms)
    $aiKeywordsFormId = 'ai-keywords-form-' . $project->id;
@endphp

<div class="col-span-5 flex-1 min-h-0">
    <div class="w-full p-8 bg-white border border-gray-200 rounded-4xl h-full min-h-0">
        <div class="h-full min-h-0 overflow-y-auto pr-2">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-4 mb-6">
                <div class="min-w-0">
                    <h1 class="text-3xl font-bold text-[#215558] leading-tight">SEO dashboard</h1>
                    <div class="mt-1 text-[#215558] font-semibold truncate">{{ $domain }}</div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if($isConnected)
                            <span class="{{ $pillBase }} bg-emerald-50 border-emerald-200 text-emerald-700">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                SE Ranking gekoppeld
                            </span>
                        @else
                            <span class="{{ $pillBase }} bg-amber-50 border-amber-200 text-amber-800">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                Nog niet gekoppeld
                            </span>
                        @endif

                        <span class="{{ $pillBase }} bg-gray-50 border-gray-200 text-gray-700">
                            {{ $companyName }}
                        </span>

                        @if($project->last_synced_at)
                            <span class="{{ $pillBase }} bg-gray-50 border-gray-200 text-gray-700">
                                Laatst bijgewerkt: {{ $project->last_synced_at->format('d-m-Y H:i') }}
                            </span>
                        @endif
                    </div>

                    @if($isConnected && $engineChecked && $serankingNeedsSearchEngine)
                        <div class="mt-3 px-4 py-3 rounded-3xl bg-amber-50 border border-amber-200 text-[12px] text-amber-900">
                            Zoekmachine ontbreekt of is niet geldig. Voeg Google Nederland toe in SE Ranking en klik daarna op “Ververs data”.
                            @if($siteEngineId > 0)
                                <span class="ml-2 font-semibold">Huidige site_engine_id: {{ $siteEngineId }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    <a href="{{ $rIndex }}" class="px-4 py-2 text-xs font-semibold rounded-full border border-gray-200 text-[#215558] bg-white hover:bg-gray-100 transition">Terug</a>

                    @if($rEdit)
                        <a href="{{ $rEdit }}" class="px-4 py-2 text-xs font-semibold rounded-full bg-[#f3f8f8] text-[#215558] hover:bg-[#e5f1f1] transition">Bewerken</a>
                    @endif

                    @if($guestLink)
                        <a href="{{ $guestLink }}" target="_blank" rel="noopener" class="px-4 py-2 text-xs font-semibold rounded-full bg-[#215558] text-white hover:bg-[#0F9B9F] transition">
                            Open SE Ranking
                        </a>
                    @endif

                    @if($rMcpChat)
                        <a href="#ai-assistent" class="px-4 py-2 text-xs font-semibold rounded-full bg-[#0F9B9F] text-white hover:bg-[#215558] transition">
                            AI assistent
                        </a>
                    @endif
                </div>
            </div>

            {{-- Flash --}}
            @if (session('status'))
                <div class="mb-5 px-4 py-3 rounded-3xl bg-emerald-50 border border-emerald-200 text-[12px] text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif
            @if($mcpError)
                <div class="mb-5 px-4 py-3 rounded-3xl bg-rose-50 border border-rose-200 text-[12px] text-rose-700">
                    {{ $mcpError }}
                </div>
            @endif

            {{-- Traject --}}
            <div class="{{ $soft }} p-6 mb-6">
                <div class="flex items-start justify-between gap-6 flex-wrap">
                    <div class="min-w-[280px] flex-1">
                        <div class="text-sm font-bold text-[#215558]">Trajectoverzicht</div>
                        <div class="text-[12px] text-gray-600 mt-1">
                            Stappen worden pas “klaar” als ze gecontroleerd zijn en data beschikbaar is.
                        </div>

                        <div class="mt-4 grid grid-cols-1 sm:grid-cols-5 gap-3">
                            @php
                                [$b1,$d1,$t1,$h1,$sub1] = $stepUi('1. Koppeling', $s1[0], $s1[1]);
                                [$b2,$d2,$t2,$h2,$sub2] = $stepUi('2. Zoekmachine', $s2[0], $s2[1]);
                                [$b3,$d3,$t3,$h3,$sub3] = $stepUi('3. Keywords', $s3[0], $s3[1]);
                                [$b4,$d4,$t4,$h4,$sub4] = $stepUi('4. Nulmeting', $s4[0], $s4[1]);
                                [$b5,$d5,$t5,$h5,$sub5] = $stepUi('5. Kansen', $s5[0], $s5[1]);
                            @endphp

                            <div class="rounded-3xl border {{ $b1 }} p-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $d1 }}"></span>
                                    <div class="text-[12px] font-bold {{ $t1 }}">{{ $h1 }}</div>
                                </div>
                                <div class="text-[11px] text-gray-600 mt-1">{{ $sub1 }}</div>
                            </div>

                            <div class="rounded-3xl border {{ $b2 }} p-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $d2 }}"></span>
                                    <div class="text-[12px] font-bold {{ $t2 }}">{{ $h2 }}</div>
                                </div>
                                <div class="text-[11px] text-gray-600 mt-1">{{ $sub2 }}</div>
                            </div>

                            <div class="rounded-3xl border {{ $b3 }} p-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $d3 }}"></span>
                                    <div class="text-[12px] font-bold {{ $t3 }}">{{ $h3 }}</div>
                                </div>
                                <div class="text-[11px] text-gray-600 mt-1">{{ $sub3 }}</div>
                            </div>

                            <div class="rounded-3xl border {{ $b4 }} p-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $d4 }}"></span>
                                    <div class="text-[12px] font-bold {{ $t4 }}">{{ $h4 }}</div>
                                </div>
                                <div class="text-[11px] text-gray-600 mt-1">{{ $sub4 }}</div>
                            </div>

                            <div class="rounded-3xl border {{ $b5 }} p-4">
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full {{ $d5 }}"></span>
                                    <div class="text-[12px] font-bold {{ $t5 }}">{{ $h5 }}</div>
                                </div>
                                <div class="text-[11px] text-gray-600 mt-1">{{ $sub5 }}</div>
                            </div>
                        </div>
                    </div>

                    {{-- Volgende stap --}}
                    <div class="w-full sm:w-[360px] rounded-4xl border border-gray-200 bg-white p-5">
                        <div class="text-[12px] text-gray-500 font-semibold">Aanbevolen volgende stap</div>

                        @if(! $isConnected)
                            <div class="text-sm font-bold text-[#215558] mt-1">SE Ranking koppelen</div>
                            <div class="text-[12px] text-gray-600 mt-1">Koppel het project om posities en kansen te kunnen ophalen.</div>

                            @if($rConnect)
                                <form method="POST" action="{{ $rConnect }}" class="mt-4 space-y-3">
                                    @csrf
                                    <input
                                        name="site_id"
                                        type="number"
                                        inputmode="numeric"
                                        class="{{ $inputClass }}"
                                        placeholder="SE Ranking site_id (bijv. 11063750)"
                                        value="{{ old('site_id') }}"
                                    >
                                    @error('site_id')
                                        <div class="text-[12px] text-red-700">{{ $message }}</div>
                                    @enderror
                                    <button class="w-full {{ $btnPrimary }}">Koppelen</button>
                                </form>
                            @endif

                        @elseif(! $engineChecked)
                            <div class="text-sm font-bold text-[#215558] mt-1">Zoekmachine controleren</div>
                            <div class="text-[12px] text-gray-600 mt-1">Haal projectdata op om te controleren of Google Nederland juist staat.</div>
                            <div class="mt-4 flex flex-col gap-2">
                                @if($rSync)
                                    <form method="POST" action="{{ $rSync }}">
                                        @csrf
                                        <button class="w-full {{ $btnPrimary }}">Ververs data</button>
                                    </form>
                                @endif
                                @if($guestLink)
                                    <a href="{{ $guestLink }}" target="_blank" rel="noopener" class="w-full text-center {{ $btnGhost }}">Open SE Ranking</a>
                                @endif
                            </div>

                        @elseif($serankingNeedsSearchEngine)
                            <div class="text-sm font-bold text-[#215558] mt-1">Zoekmachine instellen</div>
                            <div class="text-[12px] text-gray-600 mt-1">Voeg Google Nederland toe in SE Ranking en klik daarna op verversen.</div>
                            <div class="mt-4 flex flex-col gap-2">
                                @if($guestLink)
                                    <a href="{{ $guestLink }}" target="_blank" rel="noopener" class="w-full text-center {{ $btnDark }}">Open SE Ranking</a>
                                @endif
                                @if($rSync)
                                    <form method="POST" action="{{ $rSync }}">
                                        @csrf
                                        <button class="w-full {{ $btnPrimary }}">Ververs data</button>
                                    </form>
                                @endif
                            </div>

                        @elseif(! $keywordsChecked || $tracked === 0)
                            <div class="text-sm font-bold text-[#215558] mt-1">Keyword set maken</div>
                            <div class="text-[12px] text-gray-600 mt-1">Voeg 10 tot 25 keywords toe. Daarna kan de nulmeting draaien.</div>
                            <div class="mt-4 flex flex-col gap-2">
                                <a href="#keywords" class="w-full text-center {{ $btnPrimary }}">Keywords toevoegen</a>
                                @if($rMcpChat)
                                    <form method="POST" action="{{ $rMcpChat }}">
                                        @csrf
                                        <input type="hidden" name="mode" value="keyword_plan">
                                        <input type="hidden" name="message" value="Geef 25 keywords voor {{ $domain }}. Zet per keyword: intentie (koop, info, lokaal), volume-inschatting, en prioriteit (hoog/middel/laag).">
                                        <button class="w-full {{ $btnGhost }}">AI: keywords voorstellen</button>
                                    </form>
                                @endif
                            </div>

                        @elseif(! $positionsChecked || count($rows) === 0)
                            <div class="text-sm font-bold text-[#215558] mt-1">Nulmeting starten</div>
                            <div class="text-[12px] text-gray-600 mt-1">Start een meting om posities en kansen te tonen.</div>
                            @if($rRecheck)
                                <form method="POST" action="{{ $rRecheck }}" class="mt-4">
                                    @csrf
                                    <button class="w-full {{ $btnPrimary }}">Nieuwe meting starten</button>
                                </form>
                            @endif

                        @else
                            <div class="text-sm font-bold text-[#215558] mt-1">Kans selecteren</div>
                            <div class="text-[12px] text-gray-600 mt-1">Kies een keyword met volume waar je nog niet in de top 10 staat.</div>
                            <div class="mt-4 flex flex-col gap-2">
                                <a href="#kansen" class="w-full text-center {{ $btnPrimary }}">Ga naar kansen</a>
                                @if($rMcpChat)
                                    <a href="#ai-assistent" class="w-full text-center {{ $btnGhost }}">Open AI assistent</a>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Layout: main + sidebar --}}
            <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6">

                {{-- MAIN --}}
                <div class="space-y-6">

                    {{-- Organisch zoekverkeer --}}
                    <div class="{{ $card }} p-6">
                        <div class="flex items-start justify-between gap-4 mb-5">
                            <div>
                                <h2 class="text-lg font-bold text-[#215558]">Organisch zoekverkeer</h2>
                                <p class="text-[12px] text-gray-500 mt-1">
                                    Samenvatting op basis van keywordposities en zichtbaarheid.
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                @if($isConnected && $rSync)
                                    <form method="POST" action="{{ $rSync }}">
                                        @csrf
                                        <button type="submit" class="{{ $btnGhost }}">Ververs data</button>
                                    </form>
                                @endif

                                @if($isConnected && $rRecheck)
                                    <form method="POST" action="{{ $rRecheck }}">
                                        @csrf
                                        <button type="submit" class="{{ $btnPrimary }}">Nieuwe meting</button>
                                    </form>
                                @endif

                                @if($rMcpChat)
                                    <a href="#ai-assistent" class="{{ $btnDark }}">AI assistent</a>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6">
                            {{-- KPI’s --}}
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <div class="text-[12px] text-gray-500">Zichtbaarheid</div>
                                    <div class="text-sm font-bold text-[#215558]">{{ $visibilityDisplay }}</div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <div class="text-[12px] text-gray-500">Keywords gevolgd</div>
                                    <div class="text-sm font-bold text-[#215558]">{{ $tracked ?: 'Geen data' }}</div>
                                </div>

                                <div class="flex items-center justify-between">
                                    <div class="text-[12px] text-gray-500">Gem. positie</div>
                                    <div class="text-sm font-bold text-[#215558]">{{ $avgPosDisplay ?? 'Geen data' }}</div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 pt-2">
                                    <div class="rounded-3xl border border-gray-200 p-4">
                                        <div class="text-[11px] text-gray-500">Top 10</div>
                                        <div class="text-xl font-bold text-[#215558] mt-1">{{ $top10 }}</div>
                                    </div>
                                    <div class="rounded-3xl border border-gray-200 p-4">
                                        <div class="text-[11px] text-gray-500">Stijgers / dalers</div>
                                        <div class="text-xl font-bold text-[#215558] mt-1">{{ $up }} / {{ $down }}</div>
                                    </div>
                                </div>

                                {{-- Verdeling --}}
                                <div class="pt-1">
                                    <div class="text-[11px] text-gray-500 mb-2">Positie verdeling</div>

                                    @php
                                        $pct = fn($n) => $tracked ? min(100, round(($n / max(1,$tracked)) * 100)) : 0;
                                        $p3 = $pct($top3);
                                        $p10 = $pct($top10);
                                        $p30 = $pct($top30);
                                    @endphp

                                    <div class="space-y-2">
                                        <div>
                                            <div class="flex justify-between text-[11px] text-gray-500 mb-1">
                                                <span>Top 3</span><span>{{ $top3 }}</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full bg-[#0F9B9F]" style="width: {{ $p3 }}%"></div>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="flex justify-between text-[11px] text-gray-500 mb-1">
                                                <span>Top 10</span><span>{{ $top10 }}</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full bg-[#215558]" style="width: {{ $p10 }}%"></div>
                                            </div>
                                        </div>

                                        <div>
                                            <div class="flex justify-between text-[11px] text-gray-500 mb-1">
                                                <span>Top 30</span><span>{{ $top30 }}</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                                <div class="h-full bg-[#0F9B9F]" style="width: {{ $p30 }}%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Trend placeholder --}}
                            <div class="rounded-4xl border border-gray-200 p-5">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="text-[12px] font-semibold text-[#215558]">Trend</div>
                                    <div class="text-[11px] text-gray-500">Laatste 90 dagen</div>
                                </div>

                                <div class="relative h-[210px] rounded-3xl bg-gradient-to-b from-[#f3f8f8] to-white border border-gray-100 overflow-hidden">
                                    <div class="absolute inset-0 opacity-40"
                                         style="background:
                                         linear-gradient(to right, rgba(15,155,159,0.12), rgba(15,155,159,0)),
                                         repeating-linear-gradient(to right, rgba(0,0,0,0.04) 0 1px, transparent 1px 48px),
                                         repeating-linear-gradient(to top, rgba(0,0,0,0.04) 0 1px, transparent 1px 36px);">
                                    </div>

                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div class="text-center px-8">
                                            <div class="text-sm font-bold text-[#215558]">Trendgrafiek</div>
                                            <div class="text-[12px] text-gray-500 mt-1">
                                                Snapshot-data tonen we hier als echte grafiek.
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4 grid grid-cols-2 gap-3">
                                    <div class="rounded-3xl bg-[#f3f8f8] border border-[#d7ecec] p-4">
                                        <div class="text-[11px] text-gray-600">Zoekvolume totaal</div>
                                        <div class="text-xl font-bold text-[#215558] mt-1">{{ number_format($sumVolume, 0, ',', '.') }}</div>
                                    </div>
                                    <div class="rounded-3xl bg-[#f3f8f8] border border-[#d7ecec] p-4">
                                        <div class="text-[11px] text-gray-600">Potentieel verkeer</div>
                                        <div class="text-xl font-bold text-[#215558] mt-1">{{ $potentialTrafficDisplay }}</div>
                                        <div class="text-[10px] text-gray-500 mt-1">Schatting op basis van posities</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Zoekwoord kansen --}}
                    <div id="kansen" class="{{ $card }} p-6">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div>
                                <h3 class="text-lg font-bold text-[#215558]">Zoekwoord kansen</h3>
                                <p class="text-[12px] text-gray-500 mt-1">
                                    Keywords met volume waar je nog niet in de top 10 staat.
                                </p>
                            </div>

                            @if($rMcpChat && $stepMeasureOk)
                                <form method="POST" action="{{ $rMcpChat }}">
                                    @csrf
                                    <input type="hidden" name="mode" value="keyword_plan">
                                    <input type="hidden" name="message" value="Maak een shortlist van de 8 beste keywords om nu op te focussen voor {{ $domain }}. Gebruik: volume hoog, pos > 10 of 0, en duidelijke koop/lokale intentie. Geef per keyword: waarom, prioriteit, en welke landingspage je zou maken.">
                                    <button type="submit" class="{{ $btnGhost }}">AI: shortlist</button>
                                </form>
                            @endif
                        </div>

                        @if(! $isConnected)
                            <div class="rounded-3xl bg-amber-50 border border-amber-200 p-4 text-[12px] text-amber-900">
                                Verbind eerst met SE Ranking om kansen te kunnen tonen.
                            </div>
                        @elseif(! $stepMeasureOk)
                            <div class="rounded-3xl bg-[#f3f8f8] border border-[#d7ecec] p-4 text-[12px] text-[#215558]">
                                Nog geen nulmeting. Start een meting om kansen te tonen.
                            </div>
                        @elseif(count($opps) === 0)
                            <div class="rounded-3xl bg-[#f3f8f8] border border-[#d7ecec] p-4 text-[12px] text-[#215558]">
                                Geen duidelijke kansen gevonden. Voeg meer keywords toe of laat AI een betere set maken.
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach($opps as $r)
                                    @php
                                        $kw = (string) ($r['keyword'] ?? '-');
                                        $pos = (int) ($r['pos'] ?? 0);
                                        $vol = (int) ($r['volume'] ?? 0);
                                        $chg = (int) ($r['change'] ?? 0);

                                        $posLabel = $pos > 0 ? (string) $pos : '0';
                                        $chgLabel = $chg === 0 ? '0' : ($chg > 0 ? '+' . $chg : (string) $chg);
                                        $chgClass = $chg > 0 ? 'text-emerald-700' : ($chg < 0 ? 'text-red-700' : 'text-gray-500');
                                    @endphp

                                    <div class="rounded-3xl border border-gray-200 p-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="min-w-0">
                                                <div class="text-sm font-bold text-[#215558] truncate">{{ $kw }}</div>
                                                <div class="mt-1 flex flex-wrap items-center gap-3 text-[12px] text-gray-600">
                                                    <span>Positie: <span class="font-semibold text-[#215558]">{{ $posLabel }}</span></span>
                                                    <span class="{{ $chgClass }}">Verschil: <span class="font-semibold">{{ $chgLabel }}</span></span>
                                                    <span>Volume: <span class="font-semibold text-[#215558]">{{ number_format($vol, 0, ',', '.') }}</span></span>
                                                </div>
                                            </div>

                                            @if($rMcpChat)
                                                <form method="POST" action="{{ $rMcpChat }}">
                                                    @csrf
                                                    <input type="hidden" name="mode" value="chat">
                                                    <input type="hidden" name="message" value="Maak een landingspage-brief voor het keyword: {{ $kw }}. Domein: {{ $domain }}. Geef: titel, H1, H2 structuur, korte teksten, CTA en interne links.">
                                                    <button type="submit" class="px-4 py-2 rounded-full text-[11px] font-semibold bg-[#f3f8f8] text-[#215558] hover:bg-[#e5f1f1] transition">
                                                        Brief maken
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Keywords toevoegen --}}
                    <div id="keywords" class="{{ $soft }} p-6">
                        <div class="flex items-start justify-between gap-4 mb-3">
                            <div>
                                <h3 class="text-sm font-bold text-[#215558]">Keywords toevoegen</h3>
                                <p class="text-[12px] text-gray-600 mt-1">Plak 10 tot 25 keywords, 1 per regel.</p>
                            </div>
                        </div>

                        @if(! $isConnected)
                            <div class="text-[12px] text-gray-600">Eerst koppelen met SE Ranking.</div>
                        @elseif(! $rAddKw)
                            <div class="text-[12px] text-gray-600">Deze actie is nog niet beschikbaar.</div>
                        @else
                            {{-- Losse AI form (hidden) om nested forms te voorkomen --}}
                            @if($rMcpChat)
                                <form id="{{ $aiKeywordsFormId }}" method="POST" action="{{ $rMcpChat }}" class="hidden">
                                    @csrf
                                    <input type="hidden" name="mode" value="keyword_plan">
                                    <input type="hidden" name="message" value="Geef 25 keywords voor {{ $domain }} met intentie (koop, info, lokaal) en prioriteit (hoog/middel/laag).">
                                </form>
                            @endif

                            <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto] gap-3 items-start">
                                <form method="POST" action="{{ $rAddKw }}" class="space-y-3">
                                    @csrf
                                    <textarea name="keywords_text" rows="4" class="{{ $inputClass }}" placeholder="Voorbeeld:
seo bureau amsterdam
seo specialist arnhem
linkbuilding uitbesteden">{{ old('keywords_text') }}</textarea>

                                    @error('keywords_text')
                                        <div class="text-[12px] text-red-700">{{ $message }}</div>
                                    @enderror

                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="submit" class="{{ $btnPrimary }}">Opslaan</button>

                                        @if($rMcpChat)
                                            <button type="submit" form="{{ $aiKeywordsFormId }}" class="{{ $btnGhost }}">AI: keywords</button>
                                        @endif
                                    </div>
                                </form>

                                <div class="flex flex-col gap-2">
                                    @if($rRecheck)
                                        <form method="POST" action="{{ $rRecheck }}">
                                            @csrf
                                            <button type="submit" class="{{ $btnGhost }} w-full">Nieuwe meting</button>
                                        </form>
                                    @endif
                                    @if($rSync)
                                        <form method="POST" action="{{ $rSync }}">
                                            @csrf
                                            <button type="submit" class="{{ $btnGhost }} w-full">Ververs data</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                </div>

                {{-- SIDEBAR --}}
                <div class="space-y-6">

                    {{-- AI Assistent --}}
                    <div id="ai-assistent" class="{{ $card }} p-6">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-[12px] text-gray-500 font-semibold">AI assistent</div>
                                <div class="text-sm font-bold text-[#215558] mt-1">Chat</div>
                                <div class="text-[12px] text-gray-500 mt-1">Voor keywords, prioriteit en landingspage-briefs.</div>
                            </div>

                            @if($rMcpClear)
                                <form method="POST" action="{{ $rMcpClear }}">
                                    @csrf
                                    <button type="submit" class="px-3 py-2 rounded-full border border-gray-200 text-[#215558] hover:bg-gray-100 transition">
                                        Wis
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if(! $rMcpChat)
                            <div class="mt-4 rounded-3xl bg-gray-50 border border-gray-200 p-4 text-[12px] text-gray-700">
                                MCP route is nog niet beschikbaar.
                            </div>
                        @else
                            <div class="mt-4 rounded-3xl border border-gray-200 overflow-hidden">
                                <div class="max-h-[260px] overflow-y-auto p-4 space-y-3 bg-white">
                                    @if(count($mcpThread) === 0)
                                        <div class="bg-[#f3f8f8] border border-[#d7ecec] rounded-3xl p-4 text-[12px] text-[#215558]">
                                            Voorbeelden:
                                            <ul class="mt-2 list-disc pl-5 text-gray-700">
                                                <li>Geef 25 keywords voor {{ $domain }} met prioriteit.</li>
                                                <li>Welke 3 landingspages pak ik als eerste en waarom?</li>
                                                <li>Maak een landingspage-brief voor keyword X.</li>
                                            </ul>
                                        </div>
                                    @else
                                        @foreach($mcpThread as $m)
                                            @php
                                                $role = (string) ($m['role'] ?? '');
                                                $content = (string) ($m['content'] ?? '');
                                                $isUser = $role === 'user';
                                            @endphp
                                            <div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
                                                <div class="{{ $isUser ? 'bg-[#215558] text-white' : 'bg-[#f3f8f8] text-[#215558] border border-[#d7ecec]' }} rounded-3xl px-4 py-3 max-w-[92%] text-[12px] whitespace-pre-line">
                                                    {{ $content }}
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>

                                <div class="p-4 bg-white border-t border-gray-200">
                                    <form method="POST" action="{{ $rMcpChat }}" class="space-y-3">
                                        @csrf
                                        <textarea name="message" rows="3" class="{{ $inputClass }}" placeholder="Typ je vraag...">{{ old('message') }}</textarea>
                                        @error('message')
                                            <div class="text-[12px] text-red-700">{{ $message }}</div>
                                        @enderror

                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="submit" name="mode" value="chat" class="{{ $btnPrimary }}">Verstuur</button>
                                            <button type="submit" name="mode" value="keyword_plan" class="{{ $btnGhost }}">Maak shortlist</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Snelle acties --}}
                    <div class="{{ $card }} p-6">
                        <div class="text-[12px] text-gray-500 font-semibold">Snelle acties</div>
                        <div class="text-sm font-bold text-[#215558] mt-1">Project</div>

                        <div class="mt-4 flex flex-col gap-2">
                            @if($guestLink)
                                <a href="{{ $guestLink }}" target="_blank" rel="noopener" class="w-full text-center {{ $btnDark }}">Open SE Ranking</a>
                            @endif
                            @if($isConnected && $rSync)
                                <form method="POST" action="{{ $rSync }}">
                                    @csrf
                                    <button class="w-full {{ $btnGhost }}">Ververs data</button>
                                </form>
                            @endif
                            @if($isConnected && $rRecheck)
                                <form method="POST" action="{{ $rRecheck }}">
                                    @csrf
                                    <button class="w-full {{ $btnPrimary }}">Nieuwe meting</button>
                                </form>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>


@endsection
