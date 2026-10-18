import type { FieldValue } from '../types/management.ts';

export function mergeImportedFields(
    current: Record<string, FieldValue>,
    imported: Record<string, FieldValue>,
    overwrite = false,
): { values: Record<string, FieldValue>; skipped: string[] } {
    const values = { ...current };
    const skipped: string[] = [];
    for (const [key, value] of Object.entries(imported)) {
        if (key === 'offerUrl') continue;
        const existing = values[key];
        if (
            !overwrite &&
            existing !== null &&
            existing !== undefined &&
            existing !== '' &&
            !(Array.isArray(existing) && existing.length === 0)
        ) {
            skipped.push(key);
            continue;
        }
        values[key] = value;
    }
    return { values, skipped };
}
