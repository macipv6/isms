export interface OrganizationSummary {
    id: string;
    name: string;
    industry: string | null;
    employee_count: number | null;
    contact_name: string | null;
    contact_email: string | null;
    is_active: boolean;
}

export interface OrganizationDetails extends OrganizationSummary {
    address: string | null;
    contact_phone: string | null;
    notes: string | null;
}

export interface OrganizationReference {
    id: string;
    name: string;
}
