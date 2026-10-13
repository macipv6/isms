export type BcmRevisionStatus = 'draft' | 'approved';
export type BiaImpactArea =
    | 'financial'
    | 'operational'
    | 'legal'
    | 'reputation';
export type BiaImpactLevel = 1 | 2 | 3 | 4;
export interface BcmPagination<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}
export interface BcmFreshness {
    current: boolean;
    reasons: string[];
}
export interface BcmCapabilities {
    write: boolean;
    edit?: boolean;
    approve?: boolean;
    revise?: boolean;
    draft?: boolean;
    plan?: boolean;
    record?: boolean;
    complete?: boolean;
    cancel?: boolean;
}
export interface BcmConfiguration {
    id: string;
    version: number;
    minutes: number[];
    approved_at: string | null;
    lock_version: number;
}
export interface BcmHistory {
    id: string;
    version: number;
    status: BcmRevisionStatus;
    approved_at: string | null;
}
export interface RecoveryTargets {
    rto_minutes: number | null;
    rpo_minutes: number | null;
    rpo_not_applicable: boolean;
    rpo_reason: string | null;
    target_reason: string | null;
}
export interface RecoveryRevision extends RecoveryTargets, BcmHistory {
    lock_version: number;
    freshness: BcmFreshness;
}
export interface BiaImpact {
    area: BiaImpactArea;
    minutes: number;
    level: BiaImpactLevel | null;
    reason: string | null;
}
export interface BiaRevision extends RecoveryRevision {
    business_impact_analysis_id: string;
    bia_configuration_version_id: string;
    owner_name: string | null;
    mtpd_minutes: number | null;
    process: BcmTarget;
    minutes: number[];
    impacts: BiaImpact[];
}
export interface BcmTarget {
    id: string;
    name: string;
    is_active: boolean;
}
export interface BiaHead extends Pick<BcmHistory, 'id' | 'version' | 'status'> {
    lock_version: number;
    freshness: BcmFreshness;
}
export interface BiaProcess extends BcmTarget {
    draft: BiaHead | null;
    approved: BiaHead | null;
}
export interface BcmSource {
    target_name: string | null;
    target_type: 'process' | 'asset';
    target_id: string;
    revision_id: string | null;
    version: number | null;
    critical?: boolean;
    freshness?: BcmFreshness;
}
export interface BcmStep {
    instruction: string | null;
    owner_name: string | null;
    expected_minutes: number | string | null;
}
export interface BcmRole {
    role: string | null;
    name: string | null;
    email: string | null;
    phone: string | null;
    deputy_name: string | null;
}
export interface PlanHead extends Pick<
    BcmHistory,
    'id' | 'version' | 'status'
> {
    continuity_plan_id: string;
    lock_version: number;
    title: string | null;
    freshness: BcmFreshness;
}
export interface PlanRevision extends PlanHead {
    approved_at: string | null;
    scenario: string | null;
    activation_conditions: string | null;
    strategy: string | null;
    minimum_operation: string | null;
    normal_operation_return: string | null;
    steps: BcmStep[];
    roles: BcmRole[];
    process_ids: string[];
    evidence_ids: string[];
    risk_analysis_ids: string[];
    lists_complete: boolean;
}
export type PlanOption = Pick<
    PlanHead,
    'id' | 'title' | 'version' | 'status' | 'freshness'
>;
export interface BcmRiskOption {
    id: string;
    title: string;
}
export interface PlanPage extends BcmPage {
    revision: PlanRevision | null;
    entries: BcmPagination<PlanHead>;
    process_options: BcmPagination<BcmTarget>;
    risk_options: BcmPagination<BcmRiskOption>;
    linked_processes?: BcmTarget[];
    linked_risks?: BcmRiskOption[];
}
export type ExerciseStatus =
    | 'draft'
    | 'planned'
    | 'conducted'
    | 'completed'
    | 'cancelled';
export type ExerciseOutcome = 'passed' | 'partial' | 'failed' | 'not_tested';
export interface ExerciseCriterion {
    id: string;
    position: number;
    expected_result: string | null;
    expected_minutes: number | string | null;
}
export interface ExerciseHead {
    id: string;
    status: ExerciseStatus;
    lock_version: number;
    scenario: string | null;
    planned_at: string | null;
    plan_revision_id: string;
    freshness: BcmFreshness;
}
export interface Exercise extends ExerciseHead {
    exercise_type: 'tabletop' | 'recovery_test' | null;
    leader_name: string | null;
    participants: string | null;
    actual_at: string | null;
    completed_by: string | null;
    completed_at: string | null;
    cancelled_by: string | null;
    cancelled_at: string | null;
    cancelled_reason: string | null;
    source_change_reason: string | null;
    source_change_acknowledged_by: string | null;
    source_change_acknowledged_at: string | null;
    criteria: ExerciseCriterion[];
    evidence_ids: string[];
    lists_complete: boolean;
}
export interface BcmFinding {
    id: string;
    title: string;
    severity: string;
    status: string;
    capabilities: {
        can_decide: boolean;
        can_close: boolean;
        can_create_measure: boolean;
    };
    measures: BcmPagination<{
        id: string;
        title: string;
        status: string;
        can_transition: boolean;
    }>;
}
export interface ExerciseResult {
    id: string;
    criterion_id: string;
    outcome: ExerciseOutcome | null;
    observations: string | null;
    reason: string | null;
    actual_minutes: number | string | null;
    finding_waiver_reason: string | null;
    finding_waived_by: string | null;
    finding_waived_at: string | null;
    capabilities: {
        edit: boolean;
        propose_finding: boolean;
        waive_finding: boolean;
    };
    findings: BcmPagination<BcmFinding>;
}
export interface ExerciseHistory {
    id: string;
    status: ExerciseStatus;
    planned_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
}
export interface ExercisePage extends Omit<BcmPage, 'history'> {
    exercise: Exercise | null;
    entries: BcmPagination<ExerciseHead>;
    plan_options?: BcmPagination<PlanOption>;
    results?: BcmPagination<ExerciseResult>;
    unrecorded_criteria?: BcmPagination<ExerciseCriterion>;
    history: BcmPagination<ExerciseHistory | BcmHistory>;
}
export interface BcmConflict {
    target_name: string | null;
    code: string;
    target_type: 'process' | 'asset';
    target_id: string;
    revision_id: string | null;
    blocking: boolean;
    path_edge_ids: string[];
    fingerprint: string;
    accepted: boolean;
    acceptance_id: string | null;
}
export interface BcmOverview {
    processes: number;
    assets: number;
    bia_drafts: number;
    bia_approved: number;
    plan_drafts: number;
    plan_approved: number;
    exercises: Record<string, number>;
    bia_review_required: number;
    plan_review_required: number;
    exercise_review_required: number;
    conflicts: number;
    blocking_conflicts: number;
    processes_missing_bia: number;
    assets_missing_recovery: number;
}
export interface BcmPage {
    project: {
        id: string;
        organization_id: string;
        name: string;
        status: string;
        bcm_level: string;
    };
    configuration: BcmConfiguration | null;
    capabilities: BcmCapabilities;
    overview: BcmOverview;
    history: BcmPagination<BcmHistory>;
    sources: BcmPagination<BcmSource>;
    conflicts: BcmPagination<BcmConflict>;
    lock_version: number;
}
export interface BiaPage extends BcmPage {
    entries: BcmPagination<BiaProcess>;
    assets?: BcmPagination<BcmTarget>;
    revision: BiaRevision | null;
}
export interface AssetRecoveryPage extends BcmPage {
    asset: BcmTarget;
    revision: RecoveryRevision | null;
}
export const impactAreas: Record<BiaImpactArea, string> = {
    financial: 'Finanzielle Schäden',
    operational: 'Betriebsunterbrechung',
    legal: 'Rechtliche / vertragliche Folgen',
    reputation: 'Reputation',
};
export const impactLevels: Record<BiaImpactLevel, string> = {
    1: 'Gering',
    2: 'Mittel',
    3: 'Hoch',
    4: 'Kritisch',
};
export const bcmBase = (projectId: string): string =>
    `/projects/${projectId}/bcm`;
