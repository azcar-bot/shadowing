<?php

use App\Modules\Identity\Presentation\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Modules\Reading\Presentation\Controllers\ReadingController;
use App\Modules\Assessment\Presentation\Controllers\AssessmentAttemptController;
use App\Modules\Classroom\Presentation\Controllers\ClassroomController;
use App\Modules\Reading\Presentation\Controllers\ReadingAuthoringController;
use App\Modules\Reading\Presentation\Controllers\ReadingExplanationController;
use App\Http\Controllers\SkillCatalogController;
use Illuminate\Support\Facades\Route;
use App\Modules\Identity\Presentation\Middleware\AdminAuthenticated;

Route::get('/health', HealthController::class)->name('health');

Route::view('/', 'welcome')->name('home');
Route::view('/terms', 'terms')->name('terms');
Route::view('/privacy', 'privacy')->name('privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
});

Route::get('/pmh9453/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
Route::post('/pmh9453/login', [AdminAuthController::class, 'login'])->name('admin.login.attempt');
Route::get('/pmh9453/passkey', [AdminAuthController::class, 'showPasskeyChallenge'])->name('admin.passkey.challenge');
Route::post('/pmh9453/passkey', [AdminAuthController::class, 'verifyPasskey'])->name('admin.passkey.verify');
Route::get('/pmh9453/passkey/register', [AdminAuthController::class, 'showPasskeyRegistration'])->name('admin.passkey.register');
Route::post('/pmh9453/passkey/register', [AdminAuthController::class, 'registerPasskey'])->name('admin.passkey.register.store');
Route::post('/pmh9453/passkey/dev-bypass', [AdminAuthController::class, 'devBypassPasskey'])->name('admin.passkey.dev-bypass');
Route::middleware(AdminAuthenticated::class)->group(function (): void {
    Route::get('/pmh9453', [AdminAuthController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('/pmh9453/users', \App\Livewire\Admin\Users\AdminUserControl::class)->name('admin.users');
    Route::get('/pmh9453/notifications', \App\Livewire\Admin\Notifications\AdminNotificationMonitor::class)->name('admin.notifications');
    Route::get('/pmh9453/analytics', \App\Livewire\Admin\Analytics\AdminAnalyticsDashboard::class)->name('admin.analytics.dashboard');
    Route::get('/pmh9453/ai-costs', \App\Livewire\Admin\AiCosts\AdminAiCostCenter::class)->name('admin.ai-costs.index');

    // Admin IELTS Content Studios & Sub-routes
    Route::get('/pmh9453/content/reading', \App\Livewire\Admin\Reading\AdminReadingTestList::class)->name('admin.reading.index');
    Route::get('/pmh9453/content/reading/create', \App\Livewire\Admin\Reading\AdminReadingTestCreate::class)->name('admin.reading.create');

    Route::prefix('/pmh9453/content/reading')->name('admin.reading.')->middleware(\App\Modules\Reading\Presentation\Middleware\EnsureReadingAdminAccess::class)->group(function (): void {
        Route::post('/tests', [ReadingAuthoringController::class, 'createTest'])->name('tests.store');
        Route::delete('/{publicId}', [ReadingAuthoringController::class, 'deleteTest'])->name('tests.delete');
        Route::get('/{publicId}/authoring', [ReadingAuthoringController::class, 'workspace'])->name('authoring.workspace');
        Route::post('/{publicId}/authoring/draft-version', [ReadingAuthoringController::class, 'createDraftVersion'])->name('authoring.draft-version');
        Route::post('/{publicId}/authoring/autosave', [ReadingAuthoringController::class, 'autosave'])->name('authoring.autosave');
        Route::patch('/{publicId}/authoring/title', [ReadingAuthoringController::class, 'updateTitle'])->name('authoring.title.update');
        Route::post('/{publicId}/authoring/restore', [ReadingAuthoringController::class, 'restore'])->name('authoring.restore');
        Route::post('/{publicId}/authoring/validate', [ReadingAuthoringController::class, 'validateDraft'])->name('authoring.validate');
        Route::get('/{publicId}/authoring/preview', [ReadingAuthoringController::class, 'preview'])->name('authoring.preview');
        Route::post('/{publicId}/authoring/passages', [ReadingAuthoringController::class, 'storePassage'])->name('authoring.passages.store');
        Route::post('/{publicId}/authoring/passages/reorder', [ReadingAuthoringController::class, 'reorderPassages'])->name('authoring.passages.reorder');
        Route::post('/{publicId}/authoring/blocks', [ReadingAuthoringController::class, 'storeBlock'])->name('authoring.blocks.store');
        Route::patch('/{publicId}/authoring/blocks/{blockId}', [ReadingAuthoringController::class, 'updateBlock'])->name('authoring.blocks.update');
        Route::delete('/{publicId}/authoring/blocks/{blockId}', [ReadingAuthoringController::class, 'deleteBlock'])->name('authoring.blocks.delete');
        Route::post('/{publicId}/authoring/passages/{passageVersionId}/blocks/reorder', [ReadingAuthoringController::class, 'reorderBlocks'])->name('authoring.blocks.reorder');
        Route::post('/{publicId}/authoring/groups', [ReadingAuthoringController::class, 'storeGroup'])->name('authoring.groups.store');
        Route::patch('/{publicId}/authoring/groups/{groupId}', [ReadingAuthoringController::class, 'updateGroup'])->name('authoring.groups.update');
        Route::put('/{publicId}/authoring/groups/{groupId}/shared-content', [ReadingAuthoringController::class, 'updateGroupSharedContent'])->name('authoring.groups.shared-content');
        Route::delete('/{publicId}/authoring/groups/{groupId}', [ReadingAuthoringController::class, 'deleteGroup'])->name('authoring.groups.delete');
        Route::post('/{publicId}/authoring/passages/{placementId}/groups/reorder', [ReadingAuthoringController::class, 'reorderGroups'])->name('authoring.groups.reorder');
        Route::post('/{publicId}/authoring/questions', [ReadingAuthoringController::class, 'storeQuestion'])->name('authoring.questions.store');
        Route::patch('/{publicId}/authoring/questions/{questionId}', [ReadingAuthoringController::class, 'updateQuestion'])->name('authoring.questions.update');
        Route::put('/{publicId}/authoring/questions/{questionId}/answer-spec', [ReadingAuthoringController::class, 'saveAnswerSpec'])->name('authoring.questions.answer-spec');
        Route::post('/{publicId}/authoring/questions/{questionId}/duplicate', [ReadingAuthoringController::class, 'duplicateQuestion'])->name('authoring.questions.duplicate');
        Route::get('/{publicId}/authoring/questions/{questionId}/explanation', [ReadingAuthoringController::class, 'getExplanation'])->name('authoring.questions.explanation.get');
        Route::post('/{publicId}/authoring/questions/{questionId}/explanation', [ReadingAuthoringController::class, 'saveExplanation'])->name('authoring.questions.explanation.store');
        Route::delete('/{publicId}/authoring/questions/{questionId}', [ReadingAuthoringController::class, 'deleteQuestion'])->name('authoring.questions.delete');
        Route::post('/{publicId}/authoring/groups/{groupId}/questions/reorder', [ReadingAuthoringController::class, 'reorderQuestions'])->name('authoring.questions.reorder');

        Route::post('/{publicId}/authoring/questions/{questionId}/explanations', [ReadingExplanationController::class, 'saveExplanation'])->name('authoring.explanations.save');
        Route::get('/{publicId}/authoring/questions/{questionId}/explanations/{id}', [ReadingExplanationController::class, 'getExplanation'])->name('authoring.explanations.show');
        Route::post('/{publicId}/authoring/questions/{questionId}/evidences', [ReadingExplanationController::class, 'saveEvidence'])->name('authoring.evidences.save');
        Route::get('/{publicId}/authoring/questions/{questionId}/evidences/{id}', [ReadingExplanationController::class, 'getEvidence'])->name('authoring.evidences.show');
        Route::post('/{publicId}/authoring/questions/{questionId}/paraphrases', [ReadingExplanationController::class, 'saveParaphrase'])->name('authoring.paraphrases.save');
        Route::get('/{publicId}/authoring/questions/{questionId}/paraphrases/{id}', [ReadingExplanationController::class, 'getParaphrase'])->name('authoring.paraphrases.show');
        Route::get('/{publicId}/authoring/passage-blocks', [ReadingExplanationController::class, 'getPassageBlocks'])->name('authoring.passage_blocks.index');

        Route::post('/{publicId}/authoring/questions/{questionId}/inline-explanation', [ReadingExplanationController::class, 'saveInlineExplanation'])->name('authoring.inline_explanation.save');
        Route::get('/{publicId}/authoring/questions/{questionId}/inline-explanation', [ReadingExplanationController::class, 'getInlineExplanation'])->name('authoring.inline_explanation.show');
    });

    // ---------------------------------------------------------------------
    // CANONICAL ADMIN CONTENT STUDIO: LISTENING (/pmh9453/content/listening/*)
    // ---------------------------------------------------------------------
    Route::get('/pmh9453/content/listening', \App\Livewire\Admin\Listening\AdminListeningList::class)->name('admin.listening.index');
    Route::get('/pmh9453/content/listening/create', \App\Livewire\Admin\Listening\AdminListeningCreate::class)->name('admin.listening.create');
    Route::get('/pmh9453/content/listening/{public_id}/edit', \App\Livewire\Admin\Listening\AdminListeningEdit::class)->name('admin.listening.edit');
    Route::prefix('/pmh9453/content/listening')->name('admin.listening.')->middleware(\App\Modules\Listening\Presentation\Middleware\EnsureListeningAdminAccess::class)->group(function (): void {
        Route::get('/{publicId}/authoring', [\App\Modules\Listening\Presentation\Controllers\ListeningAuthoringController::class, 'workspace'])->name('authoring.workspace');
    });

    Route::get('/pmh9453/dictation', \App\Livewire\Admin\Dictation\AdminDictationList::class)->name('admin.dictation.index');
    Route::get('/pmh9453/dictation/{public_id}/edit', \App\Livewire\Admin\Dictation\AdminDictationEdit::class)->name('admin.dictation.edit');

    // ---------------------------------------------------------------------
    // CANONICAL ADMIN CONTENT STUDIO: SPEAKING (/pmh9453/content/speaking/*)
    // ---------------------------------------------------------------------
    Route::get('/pmh9453/content/speaking', \App\Livewire\Admin\Speaking\AdminSpeakingList::class)->name('admin.speaking.index');
    Route::get('/pmh9453/content/speaking/create', \App\Livewire\Admin\Speaking\AdminSpeakingCreate::class)->name('admin.speaking.create');
    Route::get('/pmh9453/content/speaking/{public_id}/edit', \App\Livewire\Admin\Speaking\AdminSpeakingEdit::class)->name('admin.speaking.edit');
    Route::prefix('/pmh9453/content/speaking')->name('admin.speaking.')->middleware(\App\Modules\Speaking\Presentation\Middleware\EnsureSpeakingAdminAccess::class)->group(function (): void {
        Route::get('/{publicId}/authoring', [\App\Modules\Speaking\Presentation\Controllers\SpeakingAuthoringController::class, 'workspace'])->name('authoring.workspace');
    });

    // ---------------------------------------------------------------------
    // CANONICAL ADMIN CONTENT STUDIO: WRITING (/pmh9453/content/writing/*)
    // ---------------------------------------------------------------------
    Route::get('/pmh9453/content/writing', \App\Livewire\Admin\Writing\AdminWritingList::class)->name('admin.writing.index');
    Route::get('/pmh9453/content/writing/dashboard', \App\Livewire\Admin\Writing\WritingAdminDashboard::class)->name('admin.writing.dashboard');
    Route::get('/pmh9453/content/writing/create', \App\Livewire\Admin\Writing\AdminWritingCreate::class)->name('admin.writing.create');
    Route::get('/pmh9453/content/writing/moderation', \App\Livewire\Admin\Writing\WritingModerationQueue::class)->name('admin.writing.moderation');
    Route::get('/pmh9453/content/writing/{public_id}/edit', \App\Livewire\Admin\Writing\AdminWritingEdit::class)->name('admin.writing.edit');
    Route::prefix('/pmh9453/content/writing')->name('admin.writing.')->middleware(\App\Modules\Writing\Presentation\Middleware\EnsureWritingAdminAccess::class)->group(function (): void {
        Route::get('/{publicId}/authoring', [\App\Modules\Writing\Presentation\Controllers\WritingAuthoringController::class, 'workspace'])->name('authoring.workspace');
    });

    Route::get('/pmh9453/content-imports', \App\Livewire\Admin\ContentImport\AdminContentImportList::class)->name('admin.content-imports.index');
    Route::get('/pmh9453/content-imports/create', \App\Livewire\Admin\ContentImport\AdminContentImportCreate::class)->name('admin.content-imports.create');
    Route::get('/pmh9453/content-imports/sample/{skill}', function ($skill) {
        return response()->streamDownload(function () use ($skill) {
            echo "sample,content,{$skill}\n1,test,sample";
        }, "sample_{$skill}.csv");
    })->name('admin.content-imports.sample');

    // Admin Billing Control Plane
    Route::prefix('/pmh9453/billing')->name('admin.billing.')->group(function (): void {
        Route::get('/', \App\Livewire\Admin\Billing\AdminBillingDashboard::class)->name('dashboard');
        Route::get('/orders', \App\Livewire\Admin\Billing\AdminOrderList::class)->name('orders');
        Route::get('/plans', \App\Livewire\Admin\Billing\AdminPlanControl::class)->name('plans');
        Route::get('/promotions', \App\Livewire\Admin\Billing\AdminPromotionList::class)->name('promotions');
        Route::get('/webhooks', \App\Livewire\Admin\Billing\AdminWebhookList::class)->name('webhooks');
        Route::get('/refunds', \App\Livewire\Admin\Billing\AdminRefundControl::class)->name('refunds');
    });

    Route::get('/pmh9453/media', \App\Livewire\Admin\Media\AdminMediaList::class)->name('admin.media.index');
    Route::get('/pmh9453/media/upload', \App\Livewire\Admin\Media\AdminMediaUpload::class)->name('admin.media.upload');
    Route::get('/pmh9453/media/{public_id}', \App\Livewire\Admin\Media\AdminMediaDetail::class)->name('admin.media.detail');

    Route::get('/pmh9453/question-bank', \App\Livewire\Admin\AdminQuestionBank::class)->name('admin.question-bank');
    Route::get('/pmh9453/classrooms', \App\Livewire\Admin\Classroom\AdminClassroomList::class)->name('admin.classrooms.index');
    Route::get('/pmh9453/classrooms/{public_id}', \App\Livewire\Admin\Classroom\AdminClassroomDetail::class)->name('admin.classrooms.show');
    Route::get('/pmh9453/teacher-libraries', \App\Livewire\Admin\AdminTeacherLibraries::class)->name('admin.teacher-libraries.index');
    Route::get('/pmh9453/teacher-libraries/{teacher}', \App\Livewire\Admin\AdminTeacherLibraryDetail::class)->name('admin.teacher-libraries.show');

    Route::get('/pmh9453/vocab', \App\Livewire\Admin\AdminVocabManager::class)->name('admin.vocab');
    Route::get('/pmh9453/mistakes', \App\Livewire\Admin\AdminMistakeTaxonomy::class)->name('admin.mistakes');
    Route::get('/pmh9453/rules', \App\Livewire\Admin\AdminLearningRules::class)->name('admin.rules');
    Route::get('/pmh9453/diagnostics', \App\Livewire\Admin\AdminLearningDiagnostics::class)->name('admin.diagnostics');
    Route::get('/pmh9453/simulate', \App\Livewire\Admin\AdminLearningSimulate::class)->name('admin.simulate');
    Route::get('/pmh9453/ai-quality', \App\Livewire\Admin\AiQualityDashboard::class)->name('admin.ai-quality');

    // Admin Releases & Infrastructure Detail Routes
    Route::get('/pmh9453/releases', \App\Livewire\Admin\Releases\AdminReleaseList::class)->name('admin.releases.index');
    Route::get('/pmh9453/releases/{version}', \App\Livewire\Admin\Releases\AdminReleaseDetail::class)->name('admin.releases.show');
    Route::get('/pmh9453/rollouts', \App\Livewire\Admin\Releases\AdminRolloutList::class)->name('admin.rollouts.index');
    Route::get('/pmh9453/incidents', \App\Livewire\Admin\Releases\AdminIncidentList::class)->name('admin.incidents.index');
    Route::get('/pmh9453/incidents/{public_id}', \App\Livewire\Admin\Releases\AdminIncidentDetail::class)->name('admin.incidents.show');
    Route::get('/pmh9453/system', \App\Livewire\Admin\System\AdminSystemDashboard::class)->name('admin.system.dashboard');
    Route::get('/pmh9453/security/2fa', \App\Livewire\Admin\Security\TwoFactorSettings::class)->name('admin.security.2fa');
    Route::get('/pmh9453/security/audit', \App\Livewire\Admin\SecurityAudit\AdminSecurityAuditList::class)->name('admin.security.audit.index');
    Route::get('/pmh9453/security/audit/{public_id}', \App\Livewire\Admin\SecurityAudit\AdminSecurityAuditDetail::class)->name('admin.security.audit.show');

    Route::post('/pmh9453/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
});

Route::get('/reading', [ReadingController::class, 'index'])->name('reading.index');

Route::middleware('auth')->group(function (): void {
    Route::get('/notifications', \App\Livewire\Notifications\LearnerNotificationList::class)->name('notifications.index');
    Route::get('/settings/notifications', \App\Livewire\Notifications\NotificationPreferences::class)->name('notifications.preferences');
    Route::get('/email/verify', [AuthController::class, 'showVerificationNotice'])
        ->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
    Route::post('/email/verify/dev-bypass', [AuthController::class, 'devBypassVerification'])
        ->name('verification.dev-bypass');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('verified')->group(function (): void {
        Route::get('/app', [DashboardController::class, 'student'])->name('app.dashboard');

        // Skill Catalog Pages (4 skills + Dictation + Vocabulary)
        Route::get('/listening', [SkillCatalogController::class, 'listening'])->name('listening.index');
        Route::get('/speaking', [SkillCatalogController::class, 'speaking'])->name('speaking.index');
        Route::get('/writing', [SkillCatalogController::class, 'writing'])->name('writing.index');
        Route::get('/vocabulary', [SkillCatalogController::class, 'vocabulary'])->name('vocabulary.index');
        Route::get('/vocabulary/flashcards/{topicCode?}', [SkillCatalogController::class, 'vocabularyFlashcards'])->name('vocabulary.flashcards');
        Route::get('/dictation', [SkillCatalogController::class, 'dictation'])->name('dictation.index');
        Route::get('/pro/dictation', [SkillCatalogController::class, 'dictation'])->name('dictation.pro');
        Route::get('/dictation/practice/{lessonCode}', [SkillCatalogController::class, 'dictationPractice'])->name('dictation.practice');
        Route::get('/pro/dictation/practice/{lessonCode}', [SkillCatalogController::class, 'dictationPractice'])->name('dictation.pro.practice');
        Route::get('/shadowing', [SkillCatalogController::class, 'shadowing'])->name('shadowing.index');
        Route::get('/pro/shadowing', [SkillCatalogController::class, 'shadowing'])->name('shadowing.pro');
        Route::get('/shadowing/practice/{lessonCode?}', [SkillCatalogController::class, 'shadowingPractice'])->name('shadowing.practice');
        Route::get('/pro/shadowing/practice/{lessonCode?}', [SkillCatalogController::class, 'shadowingPractice'])->name('shadowing.pro.practice');

        // Shadowing Student Recording Media Persistence Routes
        Route::post('/shadowing/recordings/upload', [\App\Modules\Shadowing\Http\Controllers\ShadowingRecordingController::class, 'upload'])->name('shadowing.recordings.upload');
        Route::get('/shadowing/recordings/{publicId}/playback-url', [\App\Modules\Shadowing\Http\Controllers\ShadowingRecordingController::class, 'getPlaybackUrl'])->name('shadowing.recordings.playback-url');
        Route::delete('/shadowing/recordings/{publicId}', [\App\Modules\Shadowing\Http\Controllers\ShadowingRecordingController::class, 'destroy'])->name('shadowing.recordings.destroy');

        Route::get('/teacher', [DashboardController::class, 'teacher'])->name('teacher.dashboard');

        // ---------------------------------------------------------------------
        // LMS TEACHER ROUTES (/lms/teacher/*)
        // ---------------------------------------------------------------------
        Route::prefix('/lms/teacher')->group(function (): void {
            Route::get('/classes', [ClassroomController::class, 'teacherIndex'])->name('lms.teacher.classes.index');
            Route::get('/classes/create', [ClassroomController::class, 'create'])->name('lms.teacher.classes.create');
            Route::post('/classes', [ClassroomController::class, 'store'])->name('lms.teacher.classes.store');
            Route::get('/classes/{classroom:public_id}', [ClassroomController::class, 'showTeacher'])->name('lms.teacher.classes.show');
            Route::patch('/classes/{classroom:public_id}/lifecycle', [ClassroomController::class, 'updateLifecycle'])
                ->name('lms.teacher.classes.lifecycle.update');
            Route::post('/classes/{classroom:public_id}/assignments/reading', [ClassroomController::class, 'storeReadingAssignment'])
                ->name('lms.teacher.classes.assignments.reading.store');
            Route::post('/classroom-memberships/{membership}/approve', [ClassroomController::class, 'approve'])
                ->name('lms.teacher.memberships.approve');

            // Teacher grading & progress
            Route::get('/classes/{classroom:public_id}/assignments/{assignment:public_id}/grade', [ClassroomController::class, 'teacherGradeIndex'])
                ->name('lms.teacher.classes.assignments.grade');
            Route::post('/classes/{classroom:public_id}/assignments/{assignment:public_id}/grade/{attempt:public_id}', [ClassroomController::class, 'teacherGradeSubmission'])
                ->name('lms.teacher.classes.assignments.grade.store');
            Route::post('/classes/{classroom:public_id}/assignments/{assignment:public_id}/regrade/{attempt:public_id}', [ClassroomController::class, 'teacherRegrade'])
                ->name('lms.teacher.classes.assignments.regrade');
            Route::post('/classes/{classroom:public_id}/rebuild-progress', [ClassroomController::class, 'rebuildProgress'])
                ->name('lms.teacher.classes.rebuild-progress');

            // Teacher LMS Vocabulary Studio
            Route::get('/vocabulary', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'index'])->name('lms.teacher.vocabulary.index');
            Route::post('/vocabulary', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'store'])->name('lms.teacher.vocabulary.store');
            Route::get('/vocabulary/{set:public_id}', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'builder'])->name('lms.teacher.vocabulary.builder');
            Route::post('/vocabulary/{set:public_id}/items', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'addItem'])->name('lms.teacher.vocabulary.add_item');
            Route::post('/vocabulary/{set:public_id}/publish', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'publish'])->name('lms.teacher.vocabulary.publish');
            Route::post('/vocabulary/{set:public_id}/versions/{version:public_id}/assign', [\App\Modules\Vocabulary\Presentation\Controllers\TeacherVocabularyController::class, 'assign'])->name('lms.teacher.vocabulary.assign');

            // Teacher LMS Grammar Studio
            Route::get('/grammar', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'index'])->name('lms.teacher.grammar.index');
            Route::post('/grammar', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'store'])->name('lms.teacher.grammar.store');
            Route::get('/grammar/{lesson:public_id}', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'builder'])->name('lms.teacher.grammar.builder');
            Route::post('/grammar/{lesson:public_id}/exercises', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'addExercise'])->name('lms.teacher.grammar.add_exercise');
            Route::post('/grammar/{lesson:public_id}/publish', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'publish'])->name('lms.teacher.grammar.publish');
            Route::post('/grammar/{lesson:public_id}/versions/{version:public_id}/assign', [\App\Modules\Grammar\Presentation\Controllers\TeacherGrammarController::class, 'assign'])->name('lms.teacher.grammar.assign');
        });

        // ---------------------------------------------------------------------
        // LMS STUDENT ROUTES (/lms/student/*)
        // ---------------------------------------------------------------------
        Route::prefix('/lms/student')->group(function (): void {
            Route::get('/classes', [ClassroomController::class, 'studentIndex'])->name('lms.student.classes.index');
            Route::post('/classes/join', [ClassroomController::class, 'join'])->name('lms.student.classes.join');
            Route::get('/classes/{classroom:public_id}', [ClassroomController::class, 'showStudent'])->name('lms.student.classes.show');
            Route::post('/classroom-memberships/{membership}/leave', [ClassroomController::class, 'leave'])
                ->name('lms.student.memberships.leave');

            // Student assignment flow
            Route::get('/classes/{classroom:public_id}/assignments/{assignment:public_id}', [ClassroomController::class, 'showStudentAssignment'])
                ->name('lms.student.classes.assignments.show');
            Route::post('/classes/{classroom:public_id}/assignments/{assignment:public_id}/start', [ClassroomController::class, 'startAssignment'])
                ->name('lms.student.classes.assignments.start');
            Route::post('/classes/{classroom:public_id}/assignments/{assignment:public_id}/submit', [ClassroomController::class, 'submitAssignment'])
                ->name('lms.student.classes.assignments.submit');

            // Student LMS Vocabulary & Grammar
            Route::get('/classes/{classroom:public_id}/vocabulary/{assignment:public_id}', [\App\Modules\Vocabulary\Presentation\Controllers\StudentVocabularyController::class, 'show'])
                ->name('lms.student.vocabulary.show');
            Route::post('/classes/{classroom:public_id}/vocabulary/{assignment:public_id}/progress', [\App\Modules\Vocabulary\Presentation\Controllers\StudentVocabularyController::class, 'updateProgress'])
                ->name('lms.student.vocabulary.update_progress');

            Route::get('/classes/{classroom:public_id}/grammar/{assignment:public_id}', [\App\Modules\Grammar\Presentation\Controllers\StudentGrammarController::class, 'show'])
                ->name('lms.student.grammar.show');
            Route::post('/classes/{classroom:public_id}/grammar/{assignment:public_id}/submit', [\App\Modules\Grammar\Presentation\Controllers\StudentGrammarController::class, 'submitAttempt'])
                ->name('lms.student.grammar.submit');
        });

        // ---------------------------------------------------------------------
        // LEGACY URL 302 REDIRECTS (Backward Compatibility for old URLs)
        // ---------------------------------------------------------------------
        Route::get('/teacher/classrooms', function () {
            return redirect()->route('lms.teacher.classes.index', [], 302);
        });

        Route::get('/teacher/classrooms/create', function () {
            return redirect()->route('lms.teacher.classes.create', [], 302);
        });

        Route::get('/teacher/classrooms/{classroom:public_id}', function (\App\Modules\Classroom\Infrastructure\Persistence\Models\Classroom $classroom) {
            return redirect()->route('lms.teacher.classes.show', $classroom, 302);
        });

        Route::get('/student/classrooms', function () {
            return redirect()->route('lms.student.classes.index', [], 302);
        });

        Route::get('/student/classrooms/{classroom:public_id}', function (\App\Modules\Classroom\Infrastructure\Persistence\Models\Classroom $classroom) {
            return redirect()->route('lms.student.classes.show', $classroom, 302);
        });

        Route::get('/student/classrooms/{classroom:public_id}/assignments/{assignment:public_id}', function (
            \App\Modules\Classroom\Infrastructure\Persistence\Models\Classroom $classroom,
            \App\Modules\Classroom\Infrastructure\Persistence\Models\Assignment $assignment
        ) {
            return redirect()->route('lms.student.classes.assignments.show', [$classroom, $assignment], 302);
        });
        // Student practice & exam routes
        Route::get('/reading/{publicId}/practice', [ReadingController::class, 'practice'])->name('reading.practice');
        Route::get('/reading/{publicId}/exam', [ReadingController::class, 'exam'])->name('reading.exam');
        Route::post('/reading/{publicId}/submit', [ReadingController::class, 'submit'])->name('reading.submit');
        Route::get('/reading/{publicId}/result', [ReadingController::class, 'result'])->name('reading.result');

        Route::get('/listening/{publicId}/practice', [\App\Modules\Listening\Presentation\Controllers\ListeningController::class, 'practice'])->name('listening.practice');
        Route::get('/listening/{publicId}/exam', [\App\Modules\Listening\Presentation\Controllers\ListeningController::class, 'exam'])->name('listening.exam');
        Route::post('/listening/{publicId}/submit', [\App\Modules\Listening\Presentation\Controllers\ListeningController::class, 'submit'])->name('listening.submit');
        Route::get('/listening/{publicId}/result', [\App\Modules\Listening\Presentation\Controllers\ListeningController::class, 'result'])->name('listening.result');

        Route::get('/assessment/attempts/{attemptPublicId}/responses', [AssessmentAttemptController::class, 'responses'])
            ->name('assessment.responses.index');
        Route::post('/assessment/attempts/{attemptPublicId}/responses', [AssessmentAttemptController::class, 'store'])
            ->name('assessment.responses.store');
        Route::get('/assessment/attempts/{attemptPublicId}/timing', [AssessmentAttemptController::class, 'timing'])
            ->name('assessment.attempts.timing');
    });
});

// =========================================================================
// LEGACY TEACHER AUTHORING REDIRECTS / 403 ISOLATION
// (Outside Web auth group so Admin session redirects 302 & non-Admin gets 403)
// =========================================================================
Route::prefix('/teacher/reading')->name('teacher.reading.')->middleware(\App\Modules\Reading\Presentation\Middleware\EnsureReadingTeacherAccess::class)->group(function (): void {
    Route::get('/', function () { return redirect()->route('admin.reading.index', [], 302); })->name('index');
    Route::post('/tests', function () { return redirect()->route('admin.reading.index', [], 302); })->name('tests.store');
    Route::delete('/{publicId}', function ($publicId) { return redirect()->route('admin.reading.index', [], 302); })->name('tests.delete');
    Route::get('/{publicId}/authoring', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.workspace');
    Route::post('/{publicId}/authoring/draft-version', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.draft-version');
    Route::post('/{publicId}/authoring/autosave', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.autosave');
    Route::patch('/{publicId}/authoring/title', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.title.update');
    Route::post('/{publicId}/authoring/restore', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.restore');
    Route::post('/{publicId}/authoring/validate', function ($publicId) { return redirect()->route('admin.reading.authoring.workspace', $publicId, 302); })->name('authoring.validate');
    Route::get('/{publicId}/authoring/preview', function ($publicId) { return redirect()->route('admin.reading.authoring.preview', $publicId, 302); })->name('authoring.preview');
    Route::any('/{any?}', function () { return redirect()->route('admin.reading.index', [], 302); })->where('any', '.*');
});

Route::prefix('/teacher/listening')->name('teacher.listening.')->middleware(\App\Modules\Listening\Presentation\Middleware\EnsureListeningTeacherAccess::class)->group(function (): void {
    Route::get('/', function () { return redirect()->route('admin.listening.index', [], 302); })->name('index');
    Route::get('/{publicId}/authoring', function ($publicId) { return redirect()->route('admin.listening.authoring.workspace', $publicId, 302); })->name('authoring.workspace');
    Route::any('/{any?}', function () { return redirect()->route('admin.listening.index', [], 302); })->where('any', '.*');
});

Route::prefix('/teacher/speaking')->name('teacher.speaking.')->middleware(\App\Modules\Speaking\Presentation\Middleware\EnsureSpeakingTeacherAccess::class)->group(function (): void {
    Route::get('/', function () { return redirect()->route('admin.speaking.index', [], 302); })->name('index');
    Route::any('/{any?}', function () { return redirect()->route('admin.speaking.index', [], 302); })->where('any', '.*');
});

Route::prefix('/teacher/writing')->name('teacher.writing.')->middleware(\App\Modules\Writing\Presentation\Middleware\EnsureWritingTeacherAccess::class)->group(function (): void {
    Route::get('/', function () { return redirect()->route('admin.writing.index', [], 302); })->name('index');
    Route::any('/{any?}', function () { return redirect()->route('admin.writing.index', [], 302); })->where('any', '.*');
});
