<?php

namespace Database\Seeders;

use App\Enums\AnswerType;
use App\Enums\CatalogStatus;
use App\Enums\RuleAction;
use App\Enums\RuleOperator;
use App\Models\CatalogQuestion;
use App\Models\CatalogVersion;
use App\Models\Framework;
use App\Models\QuestionCategory;
use App\Models\QuestionOption;
use App\Models\QuestionRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class AssessmentCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $framework = Framework::query()->updateOrCreate(
            ['key' => 'BSI'],
            [
                'name' => 'BSI-orientiertes ISMS',
                'description' => 'Interner Fragenkatalog zur strukturierten ISMS-Erstbewertung von KMU.',
                'is_active' => true,
            ],
        );
        $publishedCatalogExists = CatalogVersion::query()
            ->where('framework_id', $framework->id)
            ->where('version', '2026.1')
            ->where('status', CatalogStatus::Published->value)
            ->exists();

        if ($publishedCatalogExists) {
            return;
        }

        $catalog = CatalogVersion::query()->updateOrCreate(
            [
                'framework_id' => $framework->id,
                'version' => '2026.1',
            ],
            [
                'status' => CatalogStatus::Draft,
                'published_at' => null,
            ],
        );

        $questions = [];

        foreach ($this->categories() as $categoryData) {
            $category = QuestionCategory::query()->updateOrCreate(
                [
                    'catalog_version_id' => $catalog->id,
                    'key' => $categoryData['key'],
                ],
                [
                    'name' => $categoryData['name'],
                    'description' => $categoryData['description'],
                    'sort_order' => $categoryData['sort_order'],
                ],
            );

            foreach ($categoryData['questions'] as $questionData) {
                $options = $questionData['options'] ?? [];
                unset($questionData['options']);

                $question = CatalogQuestion::query()->updateOrCreate(
                    [
                        'catalog_version_id' => $catalog->id,
                        'question_key' => $questionData['question_key'],
                    ],
                    [
                        ...$questionData,
                        'question_category_id' => $category->id,
                    ],
                );
                $questions[$question->question_key] = $question;

                foreach ($options as $sortOrder => $option) {
                    QuestionOption::query()->updateOrCreate(
                        [
                            'catalog_question_id' => $question->id,
                            'value' => $option['value'],
                        ],
                        [
                            'label' => $option['label'],
                            'score' => $option['score'],
                            'sort_order' => $sortOrder + 1,
                        ],
                    );
                }
            }
        }

        foreach ($this->rules() as $ruleData) {
            QuestionRule::query()->updateOrCreate(
                [
                    'trigger_question_id' => $questions[$ruleData['trigger']]->id,
                    'target_question_id' => $questions[$ruleData['target']]->id,
                    'operator' => $ruleData['operator'],
                    'action' => $ruleData['action'],
                ],
                [
                    'catalog_version_id' => $catalog->id,
                    'expected_value' => $ruleData['expected_value'],
                ],
            );
        }

        $catalog->update([
            'status' => CatalogStatus::Published,
            'published_at' => CarbonImmutable::parse('2026-09-01T00:00:00+00:00'),
        ]);
    }

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     sort_order: int,
     *     questions: list<array<string, mixed>>
     * }>
     */
    private function categories(): array
    {
        return [
            [
                'key' => 'governance',
                'name' => 'Governance',
                'description' => 'Leitlinie, Ziele und Verantwortung für Informationssicherheit.',
                'sort_order' => 10,
                'questions' => [
                    $this->question(
                        'governance.policy_exists',
                        'Informationssicherheitsleitlinie',
                        'Ist eine freigegebene Informationssicherheitsleitlinie vorhanden?',
                        AnswerType::Boolean,
                        101,
                        'high',
                        true,
                        'Die Leitlinie sollte Geltungsbereich, Ziele und Verantwortlichkeiten benennen.',
                    ),
                    $this->question(
                        'governance.objectives',
                        'Sicherheitsziele',
                        'Welche wesentlichen Informationssicherheitsziele verfolgt die Organisation?',
                        AnswerType::Text,
                        102,
                        'medium',
                        false,
                        'Beschreiben Sie konkrete und überprüfbare Ziele in kurzer Form.',
                    ),
                ],
            ],
            [
                'key' => 'organization',
                'name' => 'Organisation',
                'description' => 'Rollen, Zuständigkeiten und Sensibilisierung.',
                'sort_order' => 20,
                'questions' => [
                    $this->question(
                        'organization.responsibility',
                        'Verantwortung',
                        'Ist die Verantwortung für Informationssicherheit eindeutig zugewiesen?',
                        AnswerType::Boolean,
                        201,
                        'high',
                    ),
                    $this->question(
                        'organization.training_frequency',
                        'Sensibilisierung',
                        'Wie häufig werden Mitarbeitende zur Informationssicherheit sensibilisiert?',
                        AnswerType::SingleChoice,
                        202,
                        'medium',
                        false,
                        'Berücksichtigen Sie geplante, dokumentierte Maßnahmen für alle Beschäftigten.',
                        $this->frequencyOptions(),
                    ),
                ],
            ],
            [
                'key' => 'assets',
                'name' => 'Asset-Management',
                'description' => 'Überblick über Informationen, Systeme und kritische Werte.',
                'sort_order' => 30,
                'questions' => [
                    $this->question(
                        'assets.inventory',
                        'Asset-Inventar',
                        'Existiert ein aktuelles Inventar der informationsverarbeitenden Assets?',
                        AnswerType::Boolean,
                        301,
                        'high',
                        true,
                    ),
                    $this->question(
                        'assets.critical_count',
                        'Kritische Assets',
                        'Wie viele Assets wurden vorläufig als besonders kritisch eingestuft?',
                        AnswerType::Number,
                        302,
                        'medium',
                        false,
                        'Eine genauere Schutzbedarfsfeststellung folgt in einem späteren Arbeitsschritt.',
                    ),
                ],
            ],
            [
                'key' => 'access',
                'name' => 'Identitäten und Berechtigungen',
                'description' => 'Authentisierung und regelmäßige Berechtigungsprüfung.',
                'sort_order' => 40,
                'questions' => [
                    $this->question(
                        'access.admin_mfa',
                        'MFA für Administration',
                        'Ist für alle administrativen Konten Mehrfaktor-Authentisierung verpflichtend?',
                        AnswerType::Boolean,
                        401,
                        'critical',
                    ),
                    $this->question(
                        'access.review_frequency',
                        'Berechtigungsprüfung',
                        'Wie häufig werden Benutzerkonten und Berechtigungen überprüft?',
                        AnswerType::SingleChoice,
                        402,
                        'high',
                        false,
                        null,
                        $this->frequencyOptions(),
                    ),
                ],
            ],
            [
                'key' => 'cloud',
                'name' => 'Cloud und Microsoft 365',
                'description' => 'Nutzung und Absicherung cloudbasierter Dienste.',
                'sort_order' => 50,
                'questions' => [
                    $this->question(
                        'cloud.m365_used',
                        'Microsoft-365-Nutzung',
                        'Wird Microsoft 365 im betrachteten Geltungsbereich eingesetzt?',
                        AnswerType::Boolean,
                        501,
                        'medium',
                    ),
                    $this->question(
                        'cloud.m365_mfa',
                        'MFA in Microsoft 365',
                        'Ist Mehrfaktor-Authentisierung für Microsoft 365 verbindlich durchgesetzt?',
                        AnswerType::Boolean,
                        502,
                        'critical',
                        true,
                    ),
                ],
            ],
            [
                'key' => 'backup',
                'name' => 'Datensicherung und Wiederherstellung',
                'description' => 'Verfügbarkeit, Schutz und Prüfung von Sicherungen.',
                'sort_order' => 60,
                'questions' => [
                    $this->question(
                        'backup.available',
                        'Datensicherung',
                        'Werden geschäftskritische Daten regelmäßig gesichert?',
                        AnswerType::Boolean,
                        601,
                        'critical',
                    ),
                    $this->question(
                        'backup.frequency',
                        'Sicherungsintervall',
                        'In welchem Intervall werden geschäftskritische Daten gesichert?',
                        AnswerType::SingleChoice,
                        602,
                        'high',
                        false,
                        null,
                        [
                            ['value' => 'continuous', 'label' => 'Fortlaufend', 'score' => 3],
                            ['value' => 'daily', 'label' => 'Täglich', 'score' => 2],
                            ['value' => 'weekly', 'label' => 'Wöchentlich', 'score' => 1],
                            ['value' => 'less_often', 'label' => 'Seltener', 'score' => 0],
                        ],
                    ),
                    $this->question(
                        'backup.retention',
                        'Aufbewahrung',
                        'Wie lange werden Sicherungen aufbewahrt und gegen Überschreiben geschützt?',
                        AnswerType::Text,
                        603,
                        'medium',
                    ),
                    $this->question(
                        'backup.offline_copy',
                        'Getrennte Sicherung',
                        'Existiert mindestens eine technisch getrennte oder unveränderbare Sicherungskopie?',
                        AnswerType::Boolean,
                        604,
                        'critical',
                        true,
                    ),
                    $this->question(
                        'backup.restore_test',
                        'Wiederherstellungstest',
                        'Werden Wiederherstellungen regelmäßig praktisch getestet und dokumentiert?',
                        AnswerType::Boolean,
                        605,
                        'high',
                        true,
                    ),
                ],
            ],
            [
                'key' => 'patching',
                'name' => 'Patch-Management',
                'description' => 'Planung und Nachverfolgung von Sicherheitsaktualisierungen.',
                'sort_order' => 70,
                'questions' => [
                    $this->question(
                        'patching.cycle',
                        'Regelmäßiger Patch-Zyklus',
                        'In welchem festen Zyklus werden verfügbare Sicherheitsaktualisierungen bewertet und eingespielt?',
                        AnswerType::SingleChoice,
                        701,
                        'high',
                        false,
                        null,
                        [
                            ['value' => 'weekly', 'label' => 'Mindestens wöchentlich', 'score' => 3],
                            ['value' => 'monthly', 'label' => 'Mindestens monatlich', 'score' => 2],
                            ['value' => 'quarterly', 'label' => 'Mindestens quartalsweise', 'score' => 1],
                            ['value' => 'ad_hoc', 'label' => 'Nur bei Bedarf', 'score' => 0],
                        ],
                    ),
                ],
            ],
            [
                'key' => 'logging',
                'name' => 'Protokollierung',
                'description' => 'Erkennung und Nachvollziehbarkeit sicherheitsrelevanter Ereignisse.',
                'sort_order' => 80,
                'questions' => [
                    $this->question(
                        'logging.centralized',
                        'Zentrale Auswertung',
                        'Werden sicherheitsrelevante Protokolle zentral gesammelt und regelmäßig ausgewertet?',
                        AnswerType::Boolean,
                        801,
                        'high',
                    ),
                ],
            ],
            [
                'key' => 'incidents',
                'name' => 'Sicherheitsvorfälle',
                'description' => 'Meldung, Behandlung und Nachbereitung von Vorfällen.',
                'sort_order' => 90,
                'questions' => [
                    $this->question(
                        'incidents.process',
                        'Vorfallprozess',
                        'Ist ein dokumentierter Prozess zur Meldung und Behandlung von Sicherheitsvorfällen etabliert?',
                        AnswerType::Boolean,
                        901,
                        'high',
                        true,
                    ),
                ],
            ],
            [
                'key' => 'suppliers',
                'name' => 'Lieferanten',
                'description' => 'Sicherheitsanforderungen an externe Leistungserbringer.',
                'sort_order' => 100,
                'questions' => [
                    $this->question(
                        'suppliers.requirements',
                        'Sicherheitsanforderungen',
                        'Welche Sicherheitsanforderungen werden bei kritischen Lieferanten verbindlich geregelt?',
                        AnswerType::MultipleChoice,
                        1001,
                        'medium',
                        false,
                        'Mehrere Antworten sind möglich.',
                        [
                            ['value' => 'confidentiality', 'label' => 'Vertraulichkeit', 'score' => 1],
                            ['value' => 'incident_reporting', 'label' => 'Meldung von Vorfällen', 'score' => 1],
                            ['value' => 'availability', 'label' => 'Verfügbarkeit und Wiederanlauf', 'score' => 1],
                            ['value' => 'audit_rights', 'label' => 'Prüf- und Nachweisrechte', 'score' => 1],
                        ],
                    ),
                ],
            ],
            [
                'key' => 'bcm',
                'name' => 'Business Continuity',
                'description' => 'Vorsorge für Unterbrechungen kritischer Geschäftsabläufe.',
                'sort_order' => 110,
                'questions' => [
                    $this->question(
                        'bcm.plan_available',
                        'Notfallplan',
                        'Existiert ein freigegebener Notfall- oder Wiederanlaufplan für kritische Abläufe?',
                        AnswerType::Boolean,
                        1101,
                        'high',
                        true,
                    ),
                    $this->question(
                        'bcm.exercise_frequency',
                        'Übungen',
                        'Wie häufig werden Notfall- oder Wiederanlaufpläne praktisch geübt?',
                        AnswerType::SingleChoice,
                        1102,
                        'medium',
                        false,
                        null,
                        $this->frequencyOptions(),
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  list<array{value: string, label: string, score: int}>  $options
     * @return array<string, mixed>
     */
    private function question(
        string $key,
        string $title,
        string $text,
        AnswerType $answerType,
        int $sortOrder,
        string $severity,
        bool $evidenceExpected = false,
        ?string $helpText = null,
        array $options = [],
    ): array {
        return [
            'question_key' => $key,
            'title' => $title,
            'question_text' => $text,
            'help_text' => $helpText,
            'answer_type' => $answerType,
            'severity' => $severity,
            'evidence_expected' => $evidenceExpected,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'options' => $options,
        ];
    }

    /**
     * @return list<array{value: string, label: string, score: int}>
     */
    private function frequencyOptions(): array
    {
        return [
            ['value' => 'never', 'label' => 'Nicht regelmäßig', 'score' => 0],
            ['value' => 'annual', 'label' => 'Jährlich', 'score' => 1],
            ['value' => 'semiannual', 'label' => 'Halbjährlich', 'score' => 2],
            ['value' => 'quarterly', 'label' => 'Quartalsweise oder häufiger', 'score' => 3],
        ];
    }

    /**
     * @return list<array{
     *     trigger: string,
     *     target: string,
     *     operator: RuleOperator,
     *     expected_value: bool,
     *     action: RuleAction
     * }>
     */
    private function rules(): array
    {
        return [
            $this->includeWhenTrue('cloud.m365_used', 'cloud.m365_mfa'),
            $this->includeWhenTrue('backup.available', 'backup.frequency'),
            $this->includeWhenTrue('backup.available', 'backup.retention'),
            $this->includeWhenTrue('backup.available', 'backup.offline_copy'),
            $this->includeWhenTrue('backup.available', 'backup.restore_test'),
        ];
    }

    /**
     * @return array{
     *     trigger: string,
     *     target: string,
     *     operator: RuleOperator,
     *     expected_value: bool,
     *     action: RuleAction
     * }
     */
    private function includeWhenTrue(string $trigger, string $target): array
    {
        return [
            'trigger' => $trigger,
            'target' => $target,
            'operator' => RuleOperator::Equals,
            'expected_value' => true,
            'action' => RuleAction::Include,
        ];
    }
}
