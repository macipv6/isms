import type { OrganizationReference } from '@/types/organization';
import type { RegisterImportPreviewData } from '@/types/registers';

export type ProtectionObjective =
    | 'confidentiality'
    | 'integrity'
    | 'availability';
export type ProtectionLevel = 'normal' | 'high' | 'very_high';
export type ProtectionNeedStatus = 'draft' | 'assessed' | 'approved';
export type ProtectionTargetType = 'process' | 'asset';

export interface ProtectionVersions {
    confidentiality: number;
    integrity: number;
    availability: number;
}

export interface PropagationSource {
    source: {
        type: ProtectionTargetType;
        id: string | null;
        key: string;
        name: string;
    };
    level: ProtectionLevel;
    path: string[];
}

export interface PendingDowngrade {
    id: string;
    status: 'pending';
    requestedLevel: ProtectionLevel;
    inheritedLevel: ProtectionLevel;
    canDecideInternal: boolean;
    canRecordExternal: boolean;
    actions: { decide?: string };
}

export interface ProtectionNeedRow {
    id: string;
    targetType: ProtectionTargetType;
    targetId: string;
    target: { key: string; name: string; active: boolean };
    objective: ProtectionObjective;
    directLevel: ProtectionLevel | null;
    inheritedLevel: ProtectionLevel | null;
    effectiveLevel: ProtectionLevel | null;
    directRationale: string;
    status: ProtectionNeedStatus;
    needsReview: boolean;
    lockVersion: number;
    expectedVersions: ProtectionVersions;
    sources: PropagationSource[];
    downgrade: PendingDowngrade | null;
    actions: { rate?: string; approve?: string; requestDowngrade?: string };
}

export interface ProtectionNeedPage {
    organization: OrganizationReference;
    project: { id: string; name: string; status: string };
    filters: {
        targetType: ProtectionTargetType | null;
        state: 'all' | 'active' | 'inactive';
    };
    capabilities: { write: boolean; import: boolean };
    actions: { previewImport: string };
    needs: {
        data: ProtectionNeedRow[];
        meta: { currentPage: number; lastPage: number; perPage: number };
        links: { previous: string | null; next: string | null };
    };
    importPreview: RegisterImportPreviewData | null;
}

export const objectiveLabels: Record<ProtectionObjective, string> = {
    confidentiality: 'Vertraulichkeit',
    integrity: 'Integrität',
    availability: 'Verfügbarkeit',
};

export const levelLabels: Record<ProtectionLevel, string> = {
    normal: 'Normal',
    high: 'Hoch',
    very_high: 'Sehr hoch',
};

export interface RiskScale {
    key: string;
    label: string;
    description: string | null;
    sort_order: number;
}
export interface RiskMatrixCell {
    frequency_key: string;
    impact_key: string;
    risk_key: string;
}
export interface RiskVersion {
    id: string;
    name: string;
    version: number;
    status: 'draft' | 'published';
    description: string;
    sourceReference: string | null;
    publishedAt: string | null;
}
export interface MethodologyVersion extends RiskVersion {
    levels: { frequency: RiskScale[]; impact: RiskScale[]; risk: RiskScale[] };
    cells: RiskMatrixCell[];
}
export interface CatalogEntry {
    code: string;
    title: string;
    description: string;
    confidentiality: boolean;
    integrity: boolean;
    availability: boolean;
    sort_order: number;
    source_edition: string | null;
    source_reference: string | null;
}
export interface CatalogVersion extends RiskVersion {
    entryPages: { current: number; last: number };
    threatCount: number;
    sourceEdition: string | null;
    entries: CatalogEntry[];
}
export interface RiskTrigger {
    id: string;
    targetId: string;
    targetType: ProtectionTargetType;
    type: string;
    status: string;
    rationale: string;
}
export interface RiskThreat {
    id: string;
    code: string;
    title: string;
    description: string;
}
export interface ThreatReview {
    id: string;
    threatId: string;
    status: string;
    rationale: string | null;
}
export interface RiskTarget {
    id: string;
    type: ProtectionTargetType;
    key: string;
    name: string;
    active: boolean;
    reviews: ThreatReview[];
    protectionNeeds: Array<{
        objective: ProtectionObjective;
        level: ProtectionLevel | null;
        needsReview: boolean;
        sources: Array<{
            source_type: string;
            source_id: string;
            path: string[];
        }>;
    }>;
}
export interface RiskResource {
    id: string;
    label: string;
    status: string;
}
export type RiskResourceKind = 'finding' | 'measure' | 'evidence';
export type RiskResources = Record<RiskResourceKind, RiskResource[]>;
export interface RiskTreatment {
    id: string;
    action: string;
    rationale: string;
    counterparty_name: string;
    counterparty_email: string;
    cessation_statement: string;
    cessation_evidence_id: string | null;
}
export interface RiskCycle {
    completionBlockers: string[];
    id: string;
    analysisId: string;
    targetType: ProtectionTargetType;
    targetId: string;
    threatId: string;
    title: string;
    cause: string;
    event: string;
    impact: string;
    status: string;
    current: boolean;
    needsReview: boolean;
    lockVersion: number;
    cycleNumber: number;
    residualLevel: string | null;
    treatment: RiskTreatment | null;
    decisionRequired: boolean;
    canDecideInternal: boolean;
    canDecideExternal: boolean;
    links: Record<RiskResourceKind, string[]>;
    assessments: Array<{
        id: string;
        phase: string;
        revision: number;
        category: string;
        rationale: string;
        methodologyVersionId: string;
    }>;
    decisions: Array<{
        id: string;
        status: string;
        channel: string;
        date: string;
        rationale: string;
        invalidated: boolean;
        name: string | null;
        function: string | null;
    }>;
}
export interface RiskWorkspacePage {
    referencePages: {
        target: { current: number; last: number };
        resource: { current: number; last: number };
    };
    organization: { id: string };
    project: { id: string; name: string; status: string };
    context: {
        id: string;
        methodologyVersionId: string;
        catalogVersionId: string;
    } | null;
    filters: {
        target_type?: string;
        status?: string;
        stale?: string;
        threat?: string;
        category?: string;
        decision_required?: string;
    };
    capabilities: { write: boolean };
    targets: RiskTarget[];
    threats: RiskThreat[];
    triggers: RiskTrigger[];
    methodologies: MethodologyVersion[];
    catalogs: CatalogVersion[];
    resources: RiskResources;
    cycles: {
        data: RiskCycle[];
        meta: { currentPage: number; lastPage: number; perPage: number };
        links: { next: string | null; previous: string | null };
    };
}
