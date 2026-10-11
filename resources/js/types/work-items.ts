export type ProjectWorkTab =
    | 'assessment'
    | 'evidence'
    | 'findings'
    | 'measures'
    | 'processes'
    | 'assets'
    | 'dependencies'
    | 'protection-needs'
    | 'risks';

export type EvidenceReviewStatus = 'pending_review' | 'verified' | 'rejected';
export type EvidenceFileKind =
    | 'pdf'
    | 'png'
    | 'jpeg'
    | 'txt'
    | 'csv'
    | 'docx'
    | 'xlsx'
    | 'zip';

export type FindingStatus = 'proposed' | 'accepted' | 'rejected' | 'closed';
export type FindingSeverity = 'low' | 'medium' | 'high' | 'critical';
export type FindingOrigin = 'assessment_question' | 'bcm_exercise_result';

export interface FindingOriginReference {
    type: FindingOrigin;
    id: string;
    label: string;
}

export type MeasureStatus =
    | 'planned'
    | 'in_progress'
    | 'blocked'
    | 'completed'
    | 'cancelled';
export type MeasurePriority = 'low' | 'medium' | 'high' | 'critical';

export interface ProjectWorkReference {
    id: string;
    name: string;
}

export interface QuestionReference {
    id: string;
    question_key: string;
    title: string;
}

export interface EvidenceRegisterItem {
    id: string;
    original_name: string;
    mime_type: string;
    file_kind: EvidenceFileKind;
    size_bytes: number;
    status: EvidenceReviewStatus;
    uploaded_at: string;
    download_url: string;
    questions: QuestionReference[];
    findings: Array<{
        id: string;
        title: string;
        status: FindingStatus;
    }>;
}

export interface FindingRegisterItem {
    id: string;
    title: string;
    description: string;
    severity: FindingSeverity;
    status: FindingStatus;
    proposed_at: string;
    origin_type: FindingOrigin;
    origin: FindingOriginReference;
    question_id: string | null;
    question_key: string | null;
    question_title: string | null;
    measures: {
        total: number;
        terminal: number;
    };
}

export interface MeasureRegisterItem {
    id: string;
    title: string;
    description: string;
    priority: MeasurePriority;
    responsible_name: string;
    responsible_email: string | null;
    due_date: string;
    status: MeasureStatus;
    finding: {
        id: string;
        title: string;
        status: FindingStatus;
        origin_type: FindingOrigin;
        origin: FindingOriginReference;
        question_id: string | null;
        question_key: string | null;
        question_title: string | null;
    };
}
