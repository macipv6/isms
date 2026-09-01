export type ProjectStatus = 'draft' | 'active' | 'completed' | 'archived';

export interface ProjectSummary {
    id: string;
    name: string;
    framework: 'BSI';
    approach: 'basis_absicherung';
    bcm_level: 'aufbau_bcms';
    status: ProjectStatus;
    started_at: string | null;
    target_date: string | null;
    completed_at: string | null;
}

export interface ProjectDetails extends ProjectSummary {
    description: string | null;
    scope_text: string | null;
}

export interface ProjectDefaults {
    framework: 'BSI';
    approach: 'basis_absicherung';
    bcm_level: 'aufbau_bcms';
    status: ProjectStatus;
}
