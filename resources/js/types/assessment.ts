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
