/** datetime-local controls display the browser's wall time, never sliced UTC. */
export function localDateTime(value: string | null | undefined): string {
    if (!value) return '';
    const date = new Date(value);
    const pad = (part: number) => String(part).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function absoluteDateTime(
    value: string,
    original: string | null | undefined,
): string | null {
    if (!value) return null;
    // Keep seconds and the exact instant in a repeated DST hour when unchanged.
    if (original && value === localDateTime(original)) return original;
    return new Date(value).toISOString();
}
