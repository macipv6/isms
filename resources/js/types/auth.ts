export interface AuthUser {
    id: string;
    name: string;
    email: string;
    role: 'admin' | 'consultant';
}

export interface AuthProps {
    [key: string]: unknown;
    auth: {
        user: AuthUser | null;
    };
}
