import type {
    EvidenceFileKind,
    EvidenceReviewStatus,
    FindingSeverity,
    FindingStatus,
    MeasurePriority,
    MeasureStatus,
} from '@/types/work-items';

export type AnswerType =
    | 'boolean'
    | 'single_choice'
    | 'multiple_choice'
    | 'text'
    | 'number';

export type ComplianceStatus =
    | 'fulfilled'
    | 'partial'
    | 'not_fulfilled'
    | 'not_applicable';

export type AnswerValue = boolean | string | number | string[] | null;

export interface AssessmentOption {
    value: string;
    label: string;
    score: number | null;
    sort_order: number;
}

export interface AssessmentQuestion {
    id: string;
    question_key: string;
    title: string;
    question_text: string;
    help_text: string | null;
    answer_type: AnswerType;
    severity: 'low' | 'medium' | 'high' | 'critical';
    evidence_expected: boolean;
    options: AssessmentOption[];
    answer: AnswerValue;
    compliance_status: ComplianceStatus | null;
    comment: string | null;
    work_items: QuestionWorkItems;
}

export interface QuestionEvidenceSummary {
    id: string;
    original_name: string;
    mime_type: string;
    file_kind: EvidenceFileKind;
    size_bytes: number;
    status: EvidenceReviewStatus;
}

export interface QuestionLinkedEvidence extends QuestionEvidenceSummary {
    uploaded_at: string;
    download_url: string;
    review_url: string;
    can_review: boolean;
}

export interface QuestionEvidenceCandidate extends QuestionEvidenceSummary {
    link_url: string;
}

export interface QuestionMeasure {
    id: string;
    title: string;
    description: string;
    priority: MeasurePriority;
    responsible_name: string;
    responsible_email: string | null;
    due_date: string;
    status: MeasureStatus;
    can_edit: boolean;
    allowed_transitions: MeasureStatus[];
    update_url: string;
    transition_url: string;
}

export interface QuestionFinding {
    id: string;
    title: string;
    description: string;
    severity: FindingSeverity;
    status: FindingStatus;
    proposed_at: string;
    can_edit: boolean;
    can_decide: boolean;
    can_close: boolean;
    can_create_measure: boolean;
    can_link_evidence: boolean;
    update_url: string;
    decision_url: string;
    close_url: string;
    create_measure_url: string;
    evidence: QuestionLinkedEvidence[];
    available_evidence: QuestionEvidenceCandidate[];
    measures: {
        total: number;
        terminal: number;
        items: QuestionMeasure[];
    };
}

export interface QuestionWorkItems {
    can_upload_evidence: boolean;
    can_propose_finding: boolean;
    upload_url: string;
    linked_evidence: QuestionLinkedEvidence[];
    available_evidence: QuestionEvidenceCandidate[];
    findings: QuestionFinding[];
    propose_finding_url: string;
}

export interface AssessmentCategory {
    key: string;
    name: string;
    questions: AssessmentQuestion[];
}

export interface CategoryProgress {
    key: string;
    name: string;
    answered: number;
    total: number;
    percentage: number;
}

export interface AssessmentProgressData {
    answered: number;
    total: number;
    percentage: number;
    categories: CategoryProgress[];
}
