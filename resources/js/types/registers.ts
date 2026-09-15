export type RegisterKind = 'processes' | 'assets' | 'dependencies';
export type RegisterStateFilter = 'active' | 'inactive';
export type AssetType =
    | 'information'
    | 'application'
    | 'it_system'
    | 'service'
    | 'facility';
export type DependencyNodeType = 'process' | 'asset';
export type DependencyImportance = 'critical' | 'supporting';
export type TraversalDirection =
    | 'dependencies'
    | 'dependents'
    | 'affected_processes';

export interface RegisterCapabilities {
    create: boolean;
    edit: boolean;
    changeStatus: boolean;
    import: boolean;
    traverse: boolean;
}

export interface RegisterActions {
    store: string;
    previewImport: string;
}

export interface Pagination<T> {
    data: T[];
    meta: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
    };
    links: { previous: string | null; next: string | null };
}

export interface RecordActions {
    update: string;
    status: string;
}

export interface ProcessItem {
    id: string;
    key: string;
    name: string;
    description: string | null;
    owner_name: string | null;
    active: boolean;
    updated_at: string;
    actions: RecordActions;
}

export interface ProcessEditItem extends Omit<ProcessItem, 'actions'> {
    owner_email: string | null;
}

export interface AssetItem extends ProcessItem {
    type: AssetType;
}

export interface AssetEditItem extends Omit<AssetItem, 'actions'> {
    owner_email: string | null;
}

export interface DependencyNodeReference {
    type: DependencyNodeType;
    key: string;
    name: string;
}

export interface DependencyItem {
    id: string;
    source: DependencyNodeReference;
    target: DependencyNodeReference;
    importance: DependencyImportance;
    reason: string | null;
    active: boolean;
    updated_at: string;
    actions: RecordActions;
}

export interface ImportPreviewRow {
    line?: number;
    category: 'new' | 'changed' | 'unchanged' | 'invalid';
    field?: string;
    code?: string;
    reference?: string;
}

export interface RegisterImportPreviewData {
    id: string;
    kind: RegisterKind;
    status: 'pending' | 'applied' | 'rejected' | 'expired';
    counts: {
        new: number;
        changed: number;
        unchanged: number;
        invalid: number;
    };
    rows: ImportPreviewRow[];
    expiresAt: string;
    expired: boolean;
    canConfirm: boolean;
    actions: { preview: string; confirm: string };
}

export interface TraversalHit {
    type: DependencyNodeType;
    key: string;
    name: string;
    depth: number;
    importance: DependencyImportance;
}

export interface DependencyTraversalData {
    selected: { type: DependencyNodeType; key: string };
    direction: TraversalDirection;
    transitive: boolean;
    includeInactive: boolean;
    hits: TraversalHit[];
}
