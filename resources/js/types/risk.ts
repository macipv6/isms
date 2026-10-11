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
