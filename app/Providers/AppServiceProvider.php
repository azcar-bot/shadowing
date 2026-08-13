<?php

namespace App\Providers;

use App\Modules\Entitlement\Application\Contracts\EntitlementChecker;
use App\Modules\Entitlement\Application\Queries\CheckEntitlement;
use App\Modules\Identity\Application\Contracts\AuditLogger;
use App\Modules\Identity\Application\Contracts\DeviceTracker;
use App\Modules\Identity\Application\Contracts\OtpChallenger;
use App\Modules\Identity\Infrastructure\Security\EloquentAuditLogger;
use App\Modules\Identity\Infrastructure\Security\EloquentDeviceTracker;
use App\Modules\Identity\Infrastructure\Security\EmailOtpHandler;
use App\Modules\Classroom\Application\Contracts\ClassroomAssignmentReader;
use App\Modules\Classroom\Application\Queries\FindClassroomAssignmentForStudent;
use App\Modules\Classroom\Application\Listeners\RebuildClassroomProgressOnSubmission;
use App\Modules\Assessment\Application\Contracts\AssessmentAttemptGateway;
use App\Modules\Assessment\Application\Contracts\AssessmentAttemptTimingReader;
use App\Modules\Assessment\Application\Contracts\AssessmentResponseGateway;
use App\Modules\Assessment\Application\Contracts\AssessmentResultReader;
use App\Modules\Assessment\Application\Contracts\AssessmentProgressReader;
use App\Modules\Assessment\Application\QuestionTypes\AssessmentResultDetailWriterRegistry;
use App\Modules\Assessment\Application\QuestionTypes\AssessmentScorerRegistry;
use App\Modules\Assessment\Infrastructure\Persistence\Repositories\EloquentAssessmentAttemptGateway;
use App\Modules\Assessment\Infrastructure\Persistence\Repositories\EloquentAssessmentAttemptTimingReader;
use App\Modules\Assessment\Infrastructure\Persistence\Repositories\EloquentAssessmentResponseGateway;
use App\Modules\Assessment\Infrastructure\Persistence\Repositories\EloquentAssessmentResultReader;
use App\Modules\Assessment\Infrastructure\Persistence\Repositories\EloquentAssessmentProgressReader;
use App\Modules\Classroom\Application\Contracts\ClassroomAssignmentFacts;
use App\Modules\Classroom\Infrastructure\Persistence\Repositories\EloquentClassroomAssignmentFacts;
use App\Modules\Reading\Application\Contracts\PublishedReadingVersionReader;
use App\Modules\Reading\Application\Contracts\PublishedReadingContentReader;
use App\Modules\Reading\Application\Contracts\ReadingAuthoringAccess;
use App\Modules\Reading\Application\Contracts\ReadingAuthoringLibrary;
use App\Modules\Reading\Application\Contracts\ReadingVersionMutationGuard;
use App\Modules\Reading\Application\Policies\TeacherReadingAuthoringAccess;
use App\Modules\Reading\Application\QuestionTypes\MultipleChoiceSingleQuestionType;
use App\Modules\Reading\Application\QuestionTypes\QuestionTypeRegistry;
use App\Modules\Reading\Application\QuestionTypes\SentenceCompletionQuestionType;
use App\Modules\Reading\Application\QuestionTypes\TrueFalseNotGivenQuestionType;
use App\Modules\Reading\Application\Scoring\ReadingAssessmentResultWriter;
use App\Modules\Reading\Application\Scoring\ReadingAssessmentScorer;
use App\Modules\Reading\Domain\Services\ReadingAnswerNormalizer;
use App\Modules\Reading\Domain\Services\ReadingAnswerWordCounter;
use App\Modules\Reading\Infrastructure\Persistence\Repositories\EloquentPublishedReadingVersionReader;
use App\Modules\Reading\Application\Queries\FindPublishedReadingContent;
use App\Modules\Reading\Infrastructure\Persistence\Repositories\EloquentReadingAuthoringLibrary;
use App\Modules\Reading\Infrastructure\Persistence\Repositories\EloquentReadingVersionMutationGuard;
use App\Modules\Reading\Presentation\QuestionTypes\MultipleChoiceSingleQuestionRenderer;
use App\Modules\Reading\Presentation\QuestionTypes\QuestionRendererRegistry;
use App\Modules\Reading\Presentation\QuestionTypes\SentenceCompletionQuestionRenderer;
use App\Modules\Reading\Presentation\QuestionTypes\TrueFalseNotGivenQuestionRenderer;
use App\Modules\Listening\Application\QuestionTypes\ListeningQuestionTypeAdapter;
use App\Modules\Listening\Application\QuestionTypes\ListeningQuestionTypeRegistry;
use App\Modules\Writing\Application\Contracts\WritingAiEvaluator;
use App\Modules\Writing\Application\Contracts\WritingPromptMediaInspector;
use App\Modules\Writing\Infrastructure\Adapters\FakeWritingAiEvaluator;
use App\Modules\Writing\Infrastructure\Adapters\MediaModulePromptMediaInspector;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Shared\Application\Events\AssessmentAttemptSubmitted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AssessmentAttemptGateway::class, EloquentAssessmentAttemptGateway::class);
        $this->app->bind(AssessmentAttemptTimingReader::class, EloquentAssessmentAttemptTimingReader::class);
        $this->app->bind(AssessmentResultReader::class, EloquentAssessmentResultReader::class);
        $this->app->bind(AssessmentProgressReader::class, EloquentAssessmentProgressReader::class);
        $this->app->bind(ClassroomAssignmentFacts::class, EloquentClassroomAssignmentFacts::class);
        $this->app->bind(AssessmentResponseGateway::class, EloquentAssessmentResponseGateway::class);
        $this->app->bind(PublishedReadingVersionReader::class, EloquentPublishedReadingVersionReader::class);
        $this->app->bind(PublishedReadingContentReader::class, FindPublishedReadingContent::class);
        $this->app->bind(ReadingAuthoringAccess::class, TeacherReadingAuthoringAccess::class);
        $this->app->bind(ReadingAuthoringLibrary::class, EloquentReadingAuthoringLibrary::class);
        $this->app->bind(ReadingVersionMutationGuard::class, EloquentReadingVersionMutationGuard::class);
        $this->app->bind(WritingPromptMediaInspector::class, MediaModulePromptMediaInspector::class);
        $this->app->bind(WritingAiEvaluator::class, FakeWritingAiEvaluator::class);
        $this->app->bind(\App\Modules\Speaking\Application\Contracts\SpeakingAiEvaluator::class, \App\Modules\Speaking\Infrastructure\Adapters\FakeSpeakingAiEvaluator::class);
        $this->app->bind(EntitlementChecker::class, CheckEntitlement::class);

        $this->app->bind(ClassroomAssignmentReader::class, FindClassroomAssignmentForStudent::class);
        $this->app->bind(\App\Modules\Shadowing\Domain\Contracts\TranslationProviderContract::class, \App\Modules\Shadowing\Infrastructure\Adapters\DeepSeekTranslationAdapter::class);
        // Identity module contracts
        $this->app->bind(AuditLogger::class, EloquentAuditLogger::class);
        $this->app->bind(OtpChallenger::class, EmailOtpHandler::class);
        $this->app->bind(DeviceTracker::class, EloquentDeviceTracker::class);
        $this->app->singleton(AssessmentScorerRegistry::class, fn ($app): AssessmentScorerRegistry => new AssessmentScorerRegistry([
            new ReadingAssessmentScorer($app->make(QuestionTypeRegistry::class)),
        ]));
        $this->app->singleton(AssessmentResultDetailWriterRegistry::class, static fn (): AssessmentResultDetailWriterRegistry => new AssessmentResultDetailWriterRegistry([
            new ReadingAssessmentResultWriter,
        ]));
        $this->app->singleton(QuestionTypeRegistry::class, static function (): QuestionTypeRegistry {
            $normalizer = new ReadingAnswerNormalizer;
            $wordCounter = new ReadingAnswerWordCounter;

            return new QuestionTypeRegistry([
                new TrueFalseNotGivenQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\YesNoNotGivenQuestionType,
                new MultipleChoiceSingleQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\MultipleChoiceMultipleQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\MatchingHeadingsQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\MatchingInformationQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\MatchingFeaturesQuestionType,
                new \App\Modules\Reading\Application\QuestionTypes\MatchingSentenceEndingsQuestionType,
                new SentenceCompletionQuestionType($normalizer, $wordCounter),
                new \App\Modules\Reading\Application\QuestionTypes\SummaryCompletionQuestionType($normalizer, $wordCounter),
                new \App\Modules\Reading\Application\QuestionTypes\NoteCompletionQuestionType($normalizer),
                new \App\Modules\Reading\Application\QuestionTypes\TableCompletionQuestionType($normalizer),
                new \App\Modules\Reading\Application\QuestionTypes\FlowChartCompletionQuestionType($normalizer),
                new \App\Modules\Reading\Application\QuestionTypes\DiagramLabelCompletionQuestionType($normalizer),
                new \App\Modules\Reading\Application\QuestionTypes\ShortAnswerQuestionType($normalizer),
            ]);
        });
        // Listening Question Type Registry (wraps Reading implementations via adapter)
        $this->app->singleton(ListeningQuestionTypeRegistry::class, function ($app): ListeningQuestionTypeRegistry {
            $readingRegistry = $app->make(QuestionTypeRegistry::class);
            $listeningTypes = [];

            // Listening IELTS uses a subset of Reading question types
            $listeningKeys = [
                'multiple_choice_single', 'multiple_choice_multiple',
                'sentence_completion', 'note_completion', 'summary_completion',
                'table_completion', 'flow_chart_completion', 'short_answer',
                'matching_information', 'matching_features',
            ];

            foreach ($listeningKeys as $key) {
                if ($readingRegistry->has($key, '1')) {
                    $listeningTypes[] = new ListeningQuestionTypeAdapter($readingRegistry->resolve($key, '1'));
                }
            }

            return new ListeningQuestionTypeRegistry($listeningTypes);
        });

        $this->app->singleton(QuestionRendererRegistry::class, static fn (): QuestionRendererRegistry => new QuestionRendererRegistry([
            new TrueFalseNotGivenQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\YesNoNotGivenQuestionRenderer,
            new MultipleChoiceSingleQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\MultipleChoiceMultipleQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\MatchingHeadingsQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\MatchingInformationQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\MatchingFeaturesQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\MatchingSentenceEndingsQuestionRenderer,
            new SentenceCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\SummaryCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\NoteCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\TableCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\FlowChartCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\DiagramLabelCompletionQuestionRenderer,
            new \App\Modules\Reading\Presentation\QuestionTypes\ShortAnswerQuestionRenderer,
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(AssessmentAttemptSubmitted::class, RebuildClassroomProgressOnSubmission::class);

        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
            app(\App\Modules\Operations\Application\Actions\ValidateProductionConfig::class)->handle();
        }

        \Illuminate\Support\Facades\RateLimiter::for('ai', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('sepay', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->ip());
        });
    }
}
