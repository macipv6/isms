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
    critical: boolean;
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
