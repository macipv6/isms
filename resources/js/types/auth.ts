export interface AuthUser {
    id: string;
    name: string;
    email: string;
    role: 'admin' | 'consultant';
}

export interface AuthProps {
    auth: {
        user: AuthUser | null;
    };
}
