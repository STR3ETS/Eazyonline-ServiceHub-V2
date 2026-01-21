<?php

use Illuminate\Support\Facades\Route;

use App\Models\Offerte;
use App\Models\WorkSession;
use App\Models\AanvraagWebsite;
use App\Models\User;

use App\Http\Controllers\AanvraagController;
use App\Http\Controllers\PotentieleKlantenController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GebruikersController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\InstellingenController;
use App\Http\Controllers\TeamInviteController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\TaskQuestionController;
use App\Http\Controllers\AanvraagFileController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\ProjectenController;
use App\Http\Controllers\ProjectPreviewController;
use App\Http\Controllers\OfferteController;
use App\Http\Controllers\WorkSessionController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MailingController;
use App\Http\Controllers\SeoProjectController;
use App\Http\Controllers\SocialsController;
use App\Http\Controllers\AanvraagTaskController;
use App\Http\Controllers\AanvraagWebsiteOwnerController;
use App\Http\Controllers\AanvraagCommentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SeoChatKitController;

// eazyonline.nl website
Route::view('/', 'website.home')->name('pages.home');
Route::post('/aanvraag/website', [AanvraagController::class, 'storeWebsiteAanvraag']);

// Login
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('support.login');

Route::middleware(['auth'])->prefix('app')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('app.notifications.index');
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])->name('app.notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('app.notifications.readAll');
});

Route::middleware('guest')
    ->prefix('register')
    ->name('onboarding.')
    ->controller(OnboardingController::class)
    ->group(function () {
        Route::get('/account', 'account')->name('account');

        Route::get ('/step-1', 'step1')->name('step1');
        Route::post('/step-1', 'storeStep1')->name('step1.store');

        Route::get ('/step-2', 'step2')->name('step2');
        Route::post('/step-2', 'storeStep2')->name('step2.store');

        Route::get ('/step-3', 'step3')->name('step3');
        Route::post('/step-3', 'storeStep3')->name('step3.store');

        Route::post('/finish', 'finish')->name('finish');
});

Route::prefix('preview')
    ->name('preview.')
    ->controller(ProjectPreviewController::class)
    ->group(function () {
        Route::get('/{token}', 'show')->name('show');
        Route::post('/{token}/feedback', 'storeFeedback')->name('feedback.store');
        Route::post('/{token}/approve', 'approve')->name('approve');
    });

Route::prefix('offerte')
    ->name('offerte.')
    ->controller(OfferteController::class)
    ->group(function () {
        Route::get('/{token}', 'klant')->name('klant.show');
        Route::post('/{token}/sign', 'sign')->name('sign');
        Route::get('/{token}/edit', 'beheerder')->name('beheerder.show');
        Route::get('/{token}/download', 'download')->name('download');
        Route::post('/{token}/regenerate', 'regenerate')->name('regenerate');
        Route::post('/{token}/inline', 'inlineUpdate')->name('inline-update');
        Route::post('/{token}/revoke', 'revoke')->name('revoke');
        Route::post('/{token}/send', 'send')->name('send');
    });

Route::prefix('app')->group(function () {

    // OTP
    Route::post('/email',  [AuthController::class, 'sendLoginToken'])->name('support.send_token');
    Route::post('/verify', [AuthController::class, 'verifyLoginToken'])->name('support.verify_token');
    Route::post('/resend', [AuthController::class, 'resendLoginToken'])->name('support.resend_token');
    
    Route::middleware('auth')->group(function () {
        Route::patch('/first-login-dismiss', [AuthController::class, 'dismissFirstLogin'])->name('support.first_login.dismiss');
        
        Route::get('/', function () {
            $user = auth()->user();
            $now  = now();

            $todayDate  = $now->toDateString();
            $weekStart  = $now->copy()->startOfWeek();
            $weekEnd    = $now->copy()->endOfWeek();
            $monthStart = $now->copy()->startOfMonth();
            $monthEnd   = $now->copy()->endOfMonth();

            // Actieve work session
            $activeSession = $user->workSessions()
                ->whereNull('clock_out_at')
                ->latest('clock_in_at')
                ->first();

            $activeSeconds = 0;
            if ($activeSession) {
                $activeSeconds = $now->diffInSeconds($activeSession->clock_in_at);
            }

            // Totalen helper
            $calcTotal = function ($sessions) {
                return $sessions->reduce(function ($carry, \App\Models\WorkSession $session) {
                    if ($session->clock_out_at) {
                        $seconds = $session->worked_seconds
                            ?? $session->clock_out_at->diffInSeconds($session->clock_in_at);
                    } else {
                        $seconds = now()->diffInSeconds($session->clock_in_at);
                    }

                    return $carry + max(0, $seconds);
                }, 0);
            };

            $todaySeconds = $calcTotal(
                $user->workSessions()
                    ->whereDate('clock_in_at', $todayDate)
                    ->get()
            );

            $weekSeconds = $calcTotal(
                $user->workSessions()
                    ->whereBetween('clock_in_at', [$weekStart, $weekEnd])
                    ->get()
            );

            $monthSeconds = $calcTotal(
                $user->workSessions()
                    ->whereBetween('clock_in_at', [$monthStart, $monthEnd])
                    ->get()
            );

            // ✅ Intakes van vandaag (AanvraagWebsite is je "intake")
            $intakesToday = AanvraagWebsite::with('owner')
                ->whereNotNull('intake_at')
                ->whereDate('intake_at', $todayDate)
                ->whereHas('owner', function ($q) use ($user) {
                    $q->where('id', $user->id); // alleen intakes die aan deze user gekoppeld zijn
                })
                ->orderBy('intake_at')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | Teamleden + online/offline status
            |--------------------------------------------------------------------------
            | Als de ingelogde user een company_id heeft: toon alle users binnen diezelfde company.
            | Anders (intern): fallback naar users zonder company_id.
            */
            $teamMembersQuery = User::query();

            if (!empty($user->company_id)) {
                $teamMembersQuery->where('company_id', $user->company_id);
            } else {
                $teamMembersQuery->whereNull('company_id');
            }

            $teamMembers = $teamMembersQuery
                ->orderBy('name')
                ->get()
                ->map(function (User $member) use ($now) {
                    // Laatste work session
                    $lastSession = $member->workSessions()
                        ->orderByDesc('clock_in_at')
                        ->first();

                    $isOnline   = false;
                    $statusText = 'Nog geen sessies';

                    if ($lastSession) {
                        $isOnline = is_null($lastSession->clock_out_at);

                        if ($isOnline) {
                            $statusText = 'Online sinds ' . $lastSession->clock_in_at->format('H:i');
                        } else {
                            $clockOut = $lastSession->clock_out_at;

                            if ($clockOut->isYesterday()) {
                                $statusText = 'Offline sinds gisteren ' . $clockOut->format('H:i');
                            } elseif ($clockOut->lt($now->copy()->startOfDay())) {
                                $statusText = 'Offline sinds ' . $clockOut->format('d-m H:i');
                            } else {
                                $statusText = 'Offline sinds ' . $clockOut->format('H:i');
                            }
                        }
                    }

                    // Avatar via DB (met fallback)
                    $avatar = trim((string) $member->avatar_url);

                    if ($avatar === '') {
                        $avatar = '/assets/eazyonline/memojis/default.webp';
                    } else {
                        // Als het een relatief pad is, forceer een leading slash
                        if (!preg_match('~^https?://~i', $avatar) && ($avatar[0] ?? '') !== '/') {
                            $avatar = '/' . $avatar;
                        }

                        // Optioneel: als het een lokaal pad is en het bestand bestaat niet → fallback
                        if (!preg_match('~^https?://~i', $avatar)) {
                            $rel = ltrim($avatar, '/');
                            if (!file_exists(public_path($rel))) {
                                $avatar = '/assets/eazyonline/memojis/default.webp';
                            }
                        }
                    }

                    return (object) [
                        'id'          => $member->id,
                        'name'        => $member->name,
                        'avatar'      => $avatar,
                        'is_online'   => $isOnline,
                        'status_text' => $statusText,
                    ];
                });

            /*
            |--------------------------------------------------------------------------
            | Timeline layout config
            |--------------------------------------------------------------------------
            |
            | Eén uur-slot = hoogte van een rij (h-7 = 28px) + grid-gap (gap-4 = 16px)
            | => 44px per uur. We rekenen alles t.o.v. 09:00.
            */
            $startHour   = 9;
            $endHour     = 17;

            $rowHeightPx = 28; // h-7
            $gapPx       = 16; // gap-4
            $slotPx      = $rowHeightPx + $gapPx; // 44px per uur
            $pxPerMinute = $slotPx / 60;

            $leftOffsetPx = 65;

            /*
            |--------------------------------------------------------------------------
            | Cards voorbereiden
            |--------------------------------------------------------------------------
            |
            | Center van een uur-lijn in de grid:
            |   centerY(h) = ( (h - $startHour) * 60 / 60 ) * $slotPx + ($rowHeightPx / 2)
            |              = (h - 9) * 44 + 14
            |
            | Dus:
            |   09:00 -> 14px
            |   10:00 -> 58px
            |   11:00 -> 102px
            |   ...
            */
            $intakeCards = $intakesToday
                ->map(function (AanvraagWebsite $aanvraag) use (
                    $startHour,
                    $pxPerMinute,
                    $leftOffsetPx,
                    $rowHeightPx,
                    $slotPx
                ) {
                    if (!$aanvraag->intake_at) {
                        return null;
                    }

                    $start    = \Carbon\Carbon::parse($aanvraag->intake_at);
                    $duration = (int) ($aanvraag->intake_duration ?? 30);

                    $companyName = $aanvraag->company ?? 'bedrijf';

                    $intakeUrl = \Illuminate\Support\Facades\Route::has('support.potentiele-klanten.show')
                        ? route('support.potentiele-klanten.show', ['aanvraag' => $aanvraag->id])
                        : '#';

                    // Minuten vanaf startHour (09:00)
                    $minutesFromStart = max(
                        0,
                        (($start->hour - $startHour) * 60) + $start->minute
                    );

                    // Card-hoogte (minimaal 1 uur-slot hoog)
                    $heightPx = (int) max(44, round(($duration / 60) * $slotPx));

                    // Middelpunt van de card precies op de juiste uur-lijn
                    $centerY = ($minutesFromStart / 60) * $slotPx + ($rowHeightPx / 2);

                    // Top = center - helft van de hoogte
                    $topPx = (int) max(0, round($centerY - ($heightPx / 2)));

                    // We schuiven alles 1 slot omhoog (jouw fix)
                    $finalTopPx = $topPx - $slotPx;

                    // Extra mini-fix: bij exact 09:00 nog een beetje omhoog
                    if ($minutesFromStart === 0) {
                        $finalTopPx -= 8;
                    }

                    return (object) [
                        'aanvraag_id'  => $aanvraag->id,
                        'company'      => $companyName,
                        'start'        => $start,
                        'duration'     => $duration,
                        'topPx'        => $finalTopPx,
                        'heightPx'     => $heightPx,
                        'leftOffsetPx' => $leftOffsetPx,
                        'url'          => $intakeUrl,
                    ];
                })
                ->filter()
                ->values();

            // Formatter (voor je kaarten bovenaan)
            $formatDuration = function (int $seconds): string {
                $h = intdiv($seconds, 3600);
                $m = intdiv($seconds % 3600, 60);
                return sprintf('%d uur %02d minuten', $h, $m);
            };

            return view('hub.index', [
                'user'            => $user,
                'activeSession'   => $activeSession,
                'activeSeconds'   => $activeSeconds,
                'todaySeconds'    => $todaySeconds,
                'weekSeconds'     => $weekSeconds,
                'monthSeconds'    => $monthSeconds,

                'intakesToday'    => $intakesToday,
                'intakeTimeline'  => [
                    'startHour'     => $startHour,
                    'endHour'       => $endHour,
                    'rowHeightPx'   => $rowHeightPx,
                    'gapPx'         => $gapPx,
                    'slotPx'        => $slotPx,
                    'pxPerMinute'   => $pxPerMinute,
                    'leftOffsetPx'  => $leftOffsetPx,
                ],
                'intakeCards'     => $intakeCards,

                'formatDuration'  => $formatDuration,

                // 👇 deze erbij
                'teamMembers'     => $teamMembers,
            ]);
        })->name('support.dashboard');

        Route::post('/work/clock-in',  [WorkSessionController::class, 'clockIn'])->name('support.work.clock_in');
        Route::post('/work/clock-out', [WorkSessionController::class, 'clockOut'])->name('support.work.clock_out');

        Route::get('/sales/offertes', function () {
            $user = auth()->user();
            $offertes = Offerte::with('project')
                ->orderByDesc('created_at')
                ->get();
            return view('hub.overzicht.offertes', compact('user', 'offertes'));
        })->name('support.dashboard.offertes');

        Route::patch('/tasks/questions/{question}', [TaskQuestionController::class, 'update'])->name('support.tasks.questions.update');

        // Intake
        Route::prefix('support/intake')
            ->name('support.intake.')
            ->controller(IntakeController::class)
            ->group(function () {
                Route::get('/availability', 'availability')->name('availability');
                Route::patch('/{aanvraag}/complete', 'complete')->name('complete');
                Route::patch('/{aanvraag}/clear', 'clear')->name('clear');
        });

        // Support
        Route::prefix('support')
            ->name('support.')
            ->controller(SupportController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');

                Route::prefix('tickets')
                    ->as('tickets.')
                    ->group(function () {
                        Route::get('/openstaand',     'open')->name('openstaand');
                        Route::get('/in-behandeling', 'inBehandeling')->name('in_behandeling');
                        Route::get('/gesloten',       'gesloten')->name('gesloten');
                    });
        });

        // Potentiële klanten
        Route::prefix('potentiele-klanten')
            ->middleware('company_id:1')
            ->name('support.potentiele-klanten.')
            ->controller(PotentieleKlantenController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{aanvraag}', 'show')->name('show');
                Route::patch('/{aanvraag}/status', 'updateStatus')->name('status.update');
                Route::patch('/{aanvraag}/owner', [AanvraagWebsiteOwnerController::class, 'update'])->name('owner.update');
                Route::post('/{aanvraag}/calls', 'storeCall')->name('calls.store');
                Route::patch('/{aanvraag}/tasks/status', [AanvraagTaskController::class, 'updateStatus'])->name('tasks.status.update');
                Route::post('/{aanvraag}/files', [AanvraagFileController::class, 'store'])->name('files.store');
                Route::delete('/files/{file}', [AanvraagFileController::class, 'destroy'])->name('files.destroy');
                Route::get('/files/{file}/download', [AanvraagFileController::class, 'download'])->name('files.download');
                Route::get('/{aanvraag}/comments', [AanvraagCommentController::class, 'index'])->name('comments.index');
                Route::post('/{aanvraag}/comments', [AanvraagCommentController::class, 'store'])->name('comments.store');
        });

        // Projecten
        Route::prefix('projecten')
            ->middleware('company_id:1')
            ->name('support.projecten.')
            ->controller(ProjectenController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::patch('/{project}/status', 'updateStatus')->name('status.update');
                Route::patch('/{project}/tasks/status', 'updateTaskStatus')->name('tasks.status.update');
                Route::patch('/{project}/assignee', 'updateAssignee')->name('assignee.update');
                Route::patch('/{project}/preview', 'updatePreview')->name('preview.update');
                Route::patch('/{project}/offerte-notes', 'updateOfferteNotes')->name('offerte_notes.update');
                Route::patch('/{project}/offerte-complete', 'completeOfferteTask')->name('offerte.complete');
                Route::post('/{project}/calls', 'storeCall')->name('calls.store');
                Route::post('/{project}/offerte-generate', 'generateOfferte')->name('offerte.generate');
        });

        Route::prefix('seo')
            ->middleware('company_id:1')
            ->name('support.seo.')
            ->controller(SeoProjectController::class)
            ->group(function () {
                Route::get('/projects', 'index')->name('projects.index');
                Route::get('/projects/create', 'create')->name('projects.create');
                Route::post('/projects', 'store')->name('projects.store');

                Route::get('/projects/{seoProject}', 'show')->name('projects.show');
                Route::get('/projects/{seoProject}/edit', 'edit')->name('projects.edit');
                Route::patch('/projects/{seoProject}', 'update')->name('projects.update');
                
                Route::post('/projects/{seoProject}/audits', 'startAudit')->name('projects.audits.start');

                Route::post('/projects/{seoProject}/seranking/connect', 'connectSeranking')->name('projects.seranking.connect');
                Route::post('/projects/{seoProject}/seranking/sync', 'syncSeranking')->name('projects.seranking.sync');
                Route::post('/projects/{seoProject}/seranking/keywords', 'addSerankingKeywords')->name('projects.seranking.keywords.add');
                Route::post('/projects/{seoProject}/seranking/recheck', 'recheckSeranking')->name('projects.seranking.recheck');

                // MCP (AI assistent)
                Route::post('/projects/{seoProject}/mcp/chat', 'mcpChat')->name('projects.mcp.chat');
                Route::post('/projects/{seoProject}/mcp/clear', 'mcpClear')->name('projects.mcp.clear');
                Route::post('/chatkit/session', [SeoChatKitController::class, 'session'])->name('chatkit.session');

            });

        // Marketing
        Route::prefix('marketing')
            ->name('support.marketing.')
            ->group(function () {
                Route::get('/', [MarketingController::class, 'index'])->name('index');

                Route::prefix('mailing')
                    ->name('mailing.')
                    ->controller(MailingController::class)
                    ->group(function () {
                        Route::get('/', 'index')->name('index');

                        Route::get('/nieuwsbrieven', 'nieuwsbrievenIndex')->name('nieuwsbrievenIndex');

                        Route::get('/templates', 'templatesIndex')->name('templatesIndex');
                        Route::get('/templates/nieuwsbrief-templates', 'nieuwsbriefTemplates')->name('nieuwsbriefTemplates');
                        Route::post('/templates/nieuwsbrief-templates/quick-create', 'quickCreateNieuwsbriefTemplate')->name('nieuwsbriefTemplates.quickCreate');
                        Route::get('/templates/actie-aanbod-templates', 'actieAanbodTemplates')->name('actieAanbodTemplates');
                        Route::get('/templates/onboarding-opvolg-templates', 'onboardingOpvolgTemplates')->name('onboardingOpvolgTemplates');
                        Route::patch('/templates/nieuwsbrief-templates/{template}', 'updateNieuwsbriefTemplate')->name('nieuwsbriefTemplates.update');

                        Route::get('/campagnes', 'campagnesIndex')->name('campagnesIndex');
                    });

                Route::prefix('socials')
                    ->name('socials.')
                    ->controller(SocialsController::class)
                    ->group(function () {
                        Route::get('/', 'index')->name('index');

                        Route::get('/contentkalender', 'contentkalenderIndex')->name('contentkalenderIndex');
                        Route::get('/posts', 'postsIndex')->name('postsIndex');
                        Route::get('/activiteiten', 'activiteitenIndex')->name('activiteitenIndex');
                    });
        });

        // Gebruikers
        Route::prefix('gebruikers')
            ->name('support.gebruikers.')
            ->controller(GebruikersController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');

                Route::get('/klanten', 'klanten')->name('klanten');
                Route::get('/medewerkers', 'medewerkers')->name('medewerkers');

                Route::post('/', 'store')->name('store');

                Route::get   ('/klanten/{klant}', 'showKlant')->name('klanten.show');
                Route::patch ('/klanten/{klant}', 'updateKlant')->name('klanten.update');
                Route::delete('/klanten/{klant}', 'destroyKlant')->name('klanten.destroy');

                Route::get   ('/medewerkers/{medewerker}', 'showMedewerker')->name('medewerkers.show');
                Route::patch ('/medewerkers/{medewerker}', 'updateMedewerker')->name('medewerkers.update');
                Route::delete('/medewerkers/{medewerker}', 'destroyMedewerker')->name('medewerkers.destroy');

                Route::get('/bedrijven', 'bedrijven')->name('bedrijven');
                Route::get('/bedrijven/{company}', 'bedrijfShow')->name('bedrijven.show');
                Route::post('/bedrijven', 'storeBedrijf')->name('bedrijven.store');
                Route::get ('/bedrijven/{company}/personen', 'bedrijfPersonen')->name('bedrijven.personen');
                Route::post('/bedrijven/{company}/personen/koppel', 'bedrijfPersonenKoppel')->name('bedrijven.personen.koppel');
                Route::delete('/bedrijven/{company}/personen/{user}', 'bedrijfPersonenOntkoppel')->name('bedrijven.personen.ontkoppel');
                Route::get('/bedrijven/{company}/personen/lijst', 'bedrijfPersonenLijst')->name('bedrijven.personen.lijst');
                Route::post('/bedrijven/{company}/personen/{user}/toggle-admin', 'bedrijfToggleAdmin')->name('bedrijven.admin.toggle');
        });

        // Instellingen
        Route::prefix('instellingen')
            ->name('support.instellingen.')
            ->controller(InstellingenController::class)
            ->group(function () {
                Route::get('/', 'index')->name('index');

                Route::get('/persoonlijk', 'personal')->name('personal');
                Route::get('/bedrijf', 'company')->name('company');
                Route::get('/team', 'team')->name('team');
                Route::get('/billing', 'billing')->name('billing');
                Route::get('/documenten', 'documents')->name('documents');

                Route::patch('/persoonlijke-gegevens', 'update')->name('update');
                Route::patch('/bedrijf', 'updateCompany')->name('company.update');
                Route::post('/team/invite', [TeamInviteController::class, 'send'])->middleware('throttle:10,1')->name('team.invite');
        });

        // Logout
        Route::post('/logout', [AuthController::class, 'logout'])->name('support.logout');
    });

    Route::get ('/instellingen/team/invite/{token}', [TeamInviteController::class, 'showAccept'])->name('support.instellingen.team.invite.accept');
    Route::post('/instellingen/team/invite/{token}', [TeamInviteController::class, 'handleAccept'])->name('support.instellingen.team.invite.handle');
});

// Redirects (bestaande)
Route::redirect('/support', '/service-hub');
Route::redirect('/support/login', '/login');
